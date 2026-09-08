import { ApiError, createOrder, type OrderTarget } from './api/client'
import type { CatalogItem, Offer, Order } from './api/types'
import { money } from './format'
import { notify } from './ui/notice'
import { applyOfferGone } from './ui/productCards'

/**
 * Покупка предложения — общая для витрины (main.ts, карточки в рядах) и
 * страницы каталога (catalog.ts, карточки сетки поиска).
 *
 * Раньше жила только в main.ts как единственном потребителе; вынесена сюда,
 * когда появился второй потребитель (задача 12) и код стал буквальной
 * копией — девяносто строк самой чувствительной логики (деньги,
 * идемпотентность, гонка за последнюю единицу) не должны существовать в
 * двух местах: следующая правка почти наверняка попала бы только в одно из
 * них (см. ревью задачи 12).
 *
 * Ключи идемпотентности — свои на каждую СТРАНИЦУ, а не глобально общие:
 * ES-модули — синглтоны в рамках одного собранного бандла, а витрина и
 * каталог — разные точки входа (разные HTML, разные бандлы), поэтому
 * каждая страница всё равно получает свою независимую карту, как и раньше,
 * когда у каждой был собственный локальный Map.
 *
 * Ключ создаётся при первом клике и живёт, пока запрос не завершится
 * успехом — тогда двойной клик уходит на сервер с ОДНИМ ключом, и сервер
 * отдаёт тот же заказ вместо создания второго. При отказе ключ НЕ удаляется
 * (см. ветку .catch у buyOffer/buyAlternative ниже): повторный клик по той
 * же цели обязан остаться тем же запросом, а не породить новый.
 *
 * Ключ покупки карточки каталога — offer_id, а не sku: у альтернативного
 * предложения из 409 sold_out свой offer_id, и он естественно получает свой,
 * отдельный ключ безо всякого специального сброса — «другое тело — другой
 * ключ», как и требует сервер (иначе 409 order_conflict).
 */
const idempotencyKeys = new Map<string, string>()

export function idempotencyKeyFor(key: string): string {
  const existing = idempotencyKeys.get(key)

  if (existing !== undefined) {
    return existing
  }

  // randomUUID есть только в защищённом контексте: на http-хостинге
  // (ТЗ разрешает деплой фронта куда угодно) нужен запасной вариант.
  const value =
    typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `idem_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 12)}`
  idempotencyKeys.set(key, value)

  return value
}

/**
 * Ключ выдан заново на следующий клик тем же кодом, что его резервировал.
 * Нужен как отдельная экспортируемая функция (а не прямой доступ к Map)
 * ради main.ts: его собственная «Покупка» с промокодом (buy()) идёт не
 * через attemptPurchase ниже (у неё другая форма ответа — строка ошибки,
 * а не исключение, под конкретный UI поля промокода), но обязана снимать
 * ключ так же, как и attemptPurchase.
 */
export function releaseIdempotencyKey(key: string): void {
  idempotencyKeys.delete(key)
}

/** Общая попытка покупки предложения — и у карточки каталога, и у альтернативы из отказа sold_out. */
export async function attemptPurchase(target: OrderTarget, key: string): Promise<Order> {
  const order = await createOrder(target, idempotencyKeyFor(key))
  releaseIdempotencyKey(key)

  return order
}

/**
 * Покупка карточки каталога уходит с offer_id, а не sku: карточка уже знает
 * своё ЛУЧШЕЕ предложение, и купить нужно именно его — к моменту клика
 * лучшим по цене мог стать другой offer_id того же товара, а sku выбрал бы
 * заново на сервере, не обязательно то же самое предложение, что видел
 * покупатель на экране.
 */
export function buyOffer(item: CatalogItem, button: HTMLButtonElement): void {
  const label = button.textContent
  button.disabled = true
  button.textContent = 'Оформляем...'

  void attemptPurchase({ offerId: item.best.offer_id }, `offer:${item.best.offer_id}`)
    .then((order) => {
      window.location.href = `./order.html?id=${encodeURIComponent(order.id)}`
    })
    .catch((error: unknown) => {
      // sold_out — не временный сбой, а точное знание «единиц больше нет»:
      // откатывать кнопку на «Купить» здесь нельзя. Пока запрос летел,
      // offer.updated по этому же offer_id мог уже погасить кнопку сам
      // (событие приходит из той же транзакции, что забрала единицу) —
      // безусловный сброс к исходному label затёр бы это верное состояние
      // отставшим «можно купить». applyOfferGone — тот же путь, что и у
      // настоящего offer.gone: кнопка гаснет и без свежего события.
      if (error instanceof ApiError && error.reason === 'sold_out') {
        applyOfferGone(item.best.offer_id)
      } else {
        button.disabled = false
        button.textContent = label ?? 'Купить'
      }

      reportPurchaseError(error)
    })
}

/** Клик по «Купить у {продавец} за {цена}» — на скорую руку не отличается от обычной покупки: тот же путь, другая цель. */
export function buyAlternative(offer: Offer): void {
  void attemptPurchase({ offerId: offer.offer_id }, `offer:${offer.offer_id}`)
    .then((order) => {
      window.location.href = `./order.html?id=${encodeURIComponent(order.id)}`
    })
    .catch((error: unknown) => {
      // Альтернативу тоже успели раскупить (см. buyOffer выше — то же рассуждение).
      if (error instanceof ApiError && error.reason === 'sold_out') {
        applyOfferGone(offer.offer_id)
      }

      reportPurchaseError(error)
    })
}

/**
 * 409 sold_out — не тупик (2.2 ТЗ): если пришла альтернатива, тост
 * показывает кнопку «Купить у ...», которая запускает покупку альтернативного
 * предложения. Дальше рекурсия того же обработчика: если раскупят и его,
 * покажется уже его собственная альтернатива, если она есть.
 */
export function reportPurchaseError(error: unknown): void {
  if (error instanceof ApiError && error.reason === 'sold_out') {
    // details — сырой ответ сервера (см. ApiError.details): у sold_out он
    // несёт alternative ровно в форме Offer (OfferState::toArray()), но
    // parse() в client.ts об этом не знает, поэтому приведение типа — здесь,
    // у единственного места, которому известна конкретная причина отказа.
    const alternative = (error.details?.alternative ?? null) as Offer | null

    if (alternative === null) {
      notify('Товар только что раскупили.')

      return
    }

    notify('Товар только что раскупили.', {
      label: `Купить у ${alternative.seller.name} за ${money(alternative.price_minor, alternative.currency)}`,
      onClick: () => {
        buyAlternative(alternative)
      },
    })

    return
  }

  notify(error instanceof ApiError ? error.message : 'Не удалось создать заказ. Проверьте, что бэкенд запущен.')
}

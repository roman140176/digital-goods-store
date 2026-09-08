import './styles/app.scss'

import { ApiError, createOrder, fetchCatalog, type OrderTarget } from './api/client'
import type { CatalogItem, Offer, Order, StreamEnvelope } from './api/types'
import { money } from './format'
import { connectRealtime, shouldApply } from './realtime'
import { mountBanner } from './ui/banner'
import { mountCatalogMenu } from './ui/catalogMenu'
import { mountCurrencySwitcher } from './ui/currencySwitcher'
import { renderFooter } from './ui/footer'
import { hydrateIcons } from './ui/hydrateIcons'
import { mountHeaderSearch } from './ui/headerSearch'
import { notify } from './ui/notice'
import { applyOfferGone, applyOfferState, renderProductCards, renderTabs } from './ui/productCards'
import { renderReviews } from './ui/reviews'
import { renderServices } from './ui/services'
import { mountSteamTopup } from './ui/steamTopup'

/**
 * Ключи идемпотентности на покупку.
 *
 * Ключ создаётся при первом клике и живёт, пока запрос не завершится
 * успехом — тогда двойной клик уходит на сервер с ОДНИМ ключом, и сервер
 * отдаёт тот же заказ вместо создания второго. При отказе ключ НЕ удаляется
 * (см. ветку .catch ниже): повторный клик по той же цели обязан остаться
 * тем же запросом, а не породить новый.
 *
 * Ключ покупки карточки каталога — offer_id, а не sku: у альтернативного
 * предложения из 409 sold_out свой offer_id, и он естественно получает свой,
 * отдельный ключ безо всякого специального сброса — «другое тело — другой
 * ключ», как и требует сервер (иначе 409 order_conflict).
 */
const idempotencyKeys = new Map<string, string>()

const idempotencyKeyFor = (key: string): string => {
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
 * Покупка. Возвращает null при успехе (страница уже уходит на статус заказа)
 * либо текст ошибки: показывать её решает вызывающий — у поля промокода или
 * общим сообщением.
 */
async function buy(sku: string, button: HTMLButtonElement, promoCode?: string): Promise<string | null> {
  const label = button.textContent
  button.disabled = true
  button.textContent = 'Оформляем...'

  try {
    const order = await createOrder({ sku }, idempotencyKeyFor(sku), promoCode)
    idempotencyKeys.delete(sku)
    window.location.href = `./order.html?id=${encodeURIComponent(order.id)}`

    return null
  } catch (error) {
    button.disabled = false
    button.textContent = label ?? 'Купить'

    return error instanceof ApiError ? error.message : 'Не удалось создать заказ. Проверьте, что бэкенд запущен.'
  }
}

/** Общая попытка покупки предложения — и у карточки каталога, и у альтернативы из отказа sold_out. */
async function attemptPurchase(target: OrderTarget, key: string): Promise<Order> {
  const order = await createOrder(target, idempotencyKeyFor(key))
  idempotencyKeys.delete(key)

  return order
}

/**
 * Покупка карточки каталога уходит с offer_id, а не sku: карточка уже знает
 * своё ЛУЧШЕЕ предложение, и купить нужно именно его — к моменту клика
 * лучшим по цене мог стать другой offer_id того же товара, а sku выбрал бы
 * заново на сервере, не обязательно то же самое предложение, что видел
 * покупатель на экране.
 */
function buyOffer(item: CatalogItem, button: HTMLButtonElement): void {
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
function buyAlternative(offer: Offer): void {
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
function reportPurchaseError(error: unknown): void {
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

function requireElement<T extends HTMLElement>(selector: string): T {
  const element = document.querySelector<T>(selector)

  if (element === null) {
    throw new Error(`Не найден элемент ${selector}`)
  }

  return element
}

const CARDS_PER_ROW = 5
const ROW_SELECTORS = ['[data-cards="popular"]', '[data-cards="recommended"]', '[data-cards="other"]'] as const
const TOTAL_CARDS = CARDS_PER_ROW * ROW_SELECTORS.length

/** Три ряда по пять карточек срезами по одному запросу — без хождения по кругу, позиций теперь хватает. */
function renderRows(items: readonly CatalogItem[]): void {
  ROW_SELECTORS.forEach((selector, rowIndex) => {
    const slice = items.slice(rowIndex * CARDS_PER_ROW, (rowIndex + 1) * CARDS_PER_ROW)
    renderProductCards(requireElement(selector), slice, buyOffer)
  })
}

let disconnectRealtime: (() => void) | undefined

/**
 * Открывает подписку на топик каталога.
 *
 * После resync соединение НЕ переоткрывается этой функцией — сервер сам
 * репозиционирует тот же поток на свежий курсор в одном и том же
 * рукопожатии (см. onResync ниже и 4.4 спеки), здесь достаточно перечитать
 * снапшот.
 */
function subscribeToCatalog(cursor: number): void {
  disconnectRealtime?.()
  disconnectRealtime = connectRealtime({
    topics: ['catalog'],
    cursor,
    onEvent: applyCatalogEvent,
    onResync: () => {
      void resyncCatalog()
    },
  })
}

function applyCatalogEvent(envelope: StreamEnvelope): void {
  const offerId = Number(envelope.payload.offer_id)

  if (!Number.isFinite(offerId) || !shouldApply(offerId, envelope.id)) {
    return
  }

  if (envelope.type === 'offer.updated') {
    // Событие несёт ровно форму Offer (контракт сервера, см. 4.1 спеки) —
    // здесь ему доверяют, а не проверяют отдельной рантайм-схемой: лишний
    // слой валидации для тестового задания с одним источником данных.
    applyOfferState(envelope.payload as unknown as Offer)
  } else if (envelope.type === 'offer.gone') {
    applyOfferGone(offerId)
  }
}

async function resyncCatalog(): Promise<void> {
  try {
    const response = await fetchCatalog({ per_page: TOTAL_CARDS })
    renderRows(response.items)
    // Соединение НЕ переоткрывается: сервер репозиционирует тот же поток на
    // maxId сразу после resync-кадра, в одном и том же рукопожатии (4.4
    // спеки) — переоткрытие здесь оборвало бы уже рабочее соединение.
  } catch {
    // Тихий отказ: поток всё равно жив, следующее событие придёт по нему же.
  }
}

function init(): void {
  hydrateIcons(document)

  mountBanner(requireElement('[data-banner]'))
  renderServices(requireElement('[data-services]'))
  renderTabs(requireElement('[data-tabs]'))
  renderReviews(requireElement('[data-reviews]'))
  renderFooter(requireElement('[data-footer]'))
  mountCurrencySwitcher(requireElement('[data-currency-switcher]'))
  mountCatalogMenu(requireElement('[data-catalog-button]'), requireElement('[data-catalog-menu]'))

  // Задача 12: поиск в шапке ведёт в каталог с ?q= — сам мгновенный поиск
  // живёт только на catalog.html, здесь только переход.
  mountHeaderSearch(requireElement<HTMLInputElement>('.search__input'), requireElement<HTMLButtonElement>('.search__submit'))

  // Этап 4: единственное место в макете, где покупатель может ввести код.
  mountSteamTopup(requireElement('[data-steam]'), {
    sku: 'STEAM-TOPUP-500',
    onBuy: buy,
    onError: notify,
  })

  void fetchCatalog({ per_page: TOTAL_CARDS })
    .then((response) => {
      if (response.items.length === 0) {
        ROW_SELECTORS.forEach((selector) => {
          requireElement(selector).innerHTML = '<p class="order-note">Каталог пуст: в базе нет товаров.</p>'
        })

        return
      }

      renderRows(response.items)
      subscribeToCatalog(response.stream_cursor)
    })
    .catch(() => {
      ROW_SELECTORS.forEach((selector) => {
        requireElement(selector).innerHTML =
          '<p class="order-note">Каталог не загрузился: бэкенд недоступен. Запустите стек командой make up.</p>'
      })
    })
}

init()

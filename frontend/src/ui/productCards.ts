import type { CatalogItem, Offer, ProductType } from '../api/types'
import { escapeHtml, money } from '../format'
import { tabs } from '../data/tabs'
import { iconImg, tabIcons } from '../icons'

/**
 * Названия карточек в макете оформлены эмодзи-акцентами
 * («💥 DOOM 2016 💀 STEAM KEY 🔑»), а каталог ТЗ даёт сухие названия.
 * Оформляется только витрина: в заказе остаётся имя товара из каталога.
 * Квалификатор вроде «STEAM KEY» не добавляем — с ним ни одно название
 * каталога не влезает в строку 200.92px, а в макете строка одна.
 */
const titleDecor: Record<ProductType, readonly [string, string]> = {
  key: ['💥', '💀🔑'],
  topup: ['⚡', '💳🚀'],
  subscription: ['🎁', '⭐🔥'],
  giftcard: ['🎴', '💎🎯'],
}

const cardTitle = (item: CatalogItem): string => {
  const [lead, tail] = titleDecor[item.type]

  return `${lead} ${item.name} ${tail}`
}

/**
 * Зачёркнутая цена в макете декоративная: старой цены в данных ТЗ нет,
 * она считается от текущей, чтобы не ломать вёрстку карточки. Живые события
 * её не пересчитывают (см. applyOfferState) — она не претендует на то, чтобы
 * быть настоящей историей цены, только на то, чтобы в макете было на что
 * посмотреть рядом с настоящей.
 */
const decorativeOldPrice = (minor: number): number => Math.round((minor * 1.6) / 10000) * 10000

export function renderTabs(root: HTMLElement): void {
  root.innerHTML = tabs
    .map(
      (tab, index) => `
    <button class="tab" type="button" aria-pressed="${index === 0}">
      ${iconImg(tabIcons[tab.icon] ?? '', 14)}<span>${tab.label}</span>
    </button>`,
    )
    .join('')
}

/** Текст и disabled кнопки «Купить» — одно правило для начального рендера и для точечных обновлений. */
const buyButtonState = (available: boolean): { readonly disabled: boolean; readonly label: string } =>
  available ? { disabled: false, label: 'Купить' } : { disabled: true, label: 'Нет в наличии' }

export function renderProductCards(
  root: HTMLElement,
  items: readonly CatalogItem[],
  onBuy: (item: CatalogItem, button: HTMLButtonElement) => void,
): void {
  root.innerHTML = items
    .map((item) => {
      const available = item.best.available > 0
      const button = buyButtonState(available)

      return `
    <article class="card${available ? '' : ' card--sold-out'}" data-offer-id="${item.best.offer_id}" data-sku="${escapeHtml(item.sku)}">
      <!-- Картинка одна на все плитки, как в макете: пути из каталога ТЗ
           (assets/steam.png и прочие) в задании не поставляются. -->
      <div class="card__media">
        <picture>
          <source srcset="./assets/card.webp 1x, ./assets/card@2x.webp 2x" type="image/webp">
          <img src="./assets/card@2x.jpg" alt="" width="227" height="152" loading="lazy" decoding="async">
        </picture>
      </div>
      <div class="card__body">
        <p class="card__meta"><span class="card__name">${escapeHtml(cardTitle(item))}</span>РФ+СНГ</p>
        <div class="card__prices">
          <span class="card__price" data-price>${money(item.best.price_minor, item.best.currency)}</span>
          <span class="card__price-old">${money(decorativeOldPrice(item.best.price_minor), item.best.currency)}</span>
        </div>
        <!-- Остаток не нарисован в макете отдельным текстом: узел скрыт
             визуально, но несёт число для applyOfferState и для проверки
             состояния измерениями (см. живые проверки задачи 10). -->
        <span class="visually-hidden" data-available>${item.best.available}</span>
        <button class="card__buy" type="button" ${button.disabled ? 'disabled' : ''}>${button.label}</button>
      </div>
    </article>`
    })
    .join('')

  // Порядок карточек в DOM совпадает с порядком items 1:1 (обе стороны
  // построены из одного и того же массива в одном проходе) — сопоставление
  // по индексу проще и надёжнее, чем повторный разбор data-offer-id.
  root.querySelectorAll<HTMLElement>('[data-offer-id]').forEach((card, index) => {
    const item = items[index]
    const buyButton = card.querySelector<HTMLButtonElement>('.card__buy')

    if (item === undefined || buyButton === null) {
      return
    }

    buyButton.addEventListener('click', () => onBuy(item, buyButton))
  })
}

/**
 * Точечное обновление карточки по data-offer-id: меняются цена, остаток и
 * доступность кнопки. Список НЕ перерисовывается — полная перерисовка
 * innerHTML мигает и сбивает фокус (5.1 ТЗ), а карточка тем временем могла
 * быть в фокусе или под курсором пользователя.
 *
 * Если карточки с таким offer_id сейчас нет на экране (не входит в текущие
 * 15 показанных позиций) — тихий no-op, а не ошибка: событие каталога
 * приходит по ВСЕМ предложениям витрины (см. допущение 12.1 спеки), а не
 * только по видимым.
 */
export function applyOfferState(state: Offer): void {
  const card = document.querySelector<HTMLElement>(`[data-offer-id="${state.offer_id}"]`)

  if (card === null) {
    return
  }

  const priceNode = card.querySelector<HTMLElement>('[data-price]')
  const availableNode = card.querySelector<HTMLElement>('[data-available]')

  if (priceNode !== null) {
    priceNode.textContent = money(state.price_minor, state.currency)
  }

  if (availableNode !== null) {
    availableNode.textContent = String(state.available)
  }

  setCardAvailability(card, state.available > 0 && state.status === 'active')
}

/**
 * offer.gone несёt только offer_id и sku (см. решения задачи 13: это не
 * «остаток стал нулём», а исчезновение предложения с витрины), поэтому цену
 * и остаток тут обновлять нечем — карточка просто гасится тем же способом,
 * что и настоящий sold_out.
 */
export function applyOfferGone(offerId: number): void {
  const card = document.querySelector<HTMLElement>(`[data-offer-id="${offerId}"]`)

  if (card === null) {
    return
  }

  setCardAvailability(card, false)
}

function setCardAvailability(card: HTMLElement, available: boolean): void {
  card.classList.toggle('card--sold-out', !available)

  const buyButton = card.querySelector<HTMLButtonElement>('.card__buy')

  if (buyButton === null) {
    return
  }

  const button = buyButtonState(available)
  buyButton.disabled = button.disabled
  buyButton.textContent = button.label
}

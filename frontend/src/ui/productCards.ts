import type { Product } from '../api/types'
import { money } from '../format'
import { tabs } from '../data/tabs'
import { iconImg, tabIcons } from '../icons'

const escapeHtml = (value: string): string =>
  value.replace(/[&<>"']/g, (char) => {
    const map: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }

    return map[char] ?? char
  })

/**
 * Зачёркнутая цена в макете декоративная: старой цены в данных ТЗ нет,
 * она считается от текущей, чтобы не ломать вёрстку карточки.
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

export function renderProductCards(
  root: HTMLElement,
  products: readonly Product[],
  onBuy: (sku: string, button: HTMLButtonElement) => void,
): void {
  root.innerHTML = products
    .map(
      (product) => `
    <article class="card">
      <div class="card__media">
        <picture>
          <source srcset="./assets/card.webp 1x, ./assets/card@2x.webp 2x" type="image/webp">
          <img src="./assets/card@2x.jpg" alt="" width="227" height="152" loading="lazy" decoding="async">
        </picture>
      </div>
      <div class="card__body">
        <p class="card__meta">${escapeHtml(product.name)}<br>РФ+СНГ</p>
        <div class="card__prices">
          <span class="card__price">${money(product.price_minor, product.currency)}</span>
          <span class="card__price-old">${money(decorativeOldPrice(product.price_minor), product.currency)}</span>
        </div>
        <button class="card__buy" type="button" data-sku="${escapeHtml(product.sku)}">Купить</button>
      </div>
    </article>`,
    )
    .join('')

  root.querySelectorAll<HTMLButtonElement>('[data-sku]').forEach((button) => {
    button.addEventListener('click', () => onBuy(button.dataset.sku ?? '', button))
  })
}

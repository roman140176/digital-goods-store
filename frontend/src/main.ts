import './styles/app.scss'

import { ApiError, createOrder, fetchProducts } from './api/client'
import type { Product } from './api/types'
import { mountBanner } from './ui/banner'
import { mountCatalogMenu } from './ui/catalogMenu'
import { mountCurrencySwitcher } from './ui/currencySwitcher'
import { renderFooter } from './ui/footer'
import { hydrateIcons } from './ui/hydrateIcons'
import { renderProductCards, renderTabs } from './ui/productCards'
import { renderReviews } from './ui/reviews'
import { renderServices } from './ui/services'

/**
 * Ключи идемпотентности на покупку.
 *
 * Ключ создаётся при первом клике по товару и живёт, пока запрос не
 * завершится успехом. Поэтому двойной клик уходит на сервер с ОДНИМ
 * ключом, и сервер отдаёт тот же заказ вместо создания второго.
 * Блокировка кнопки — только удобство, гарантию даёт ключ.
 */
const idempotencyKeys = new Map<string, string>()

const idempotencyKeyFor = (sku: string): string => {
  const existing = idempotencyKeys.get(sku)

  if (existing !== undefined) {
    return existing
  }

  const key = crypto.randomUUID()
  idempotencyKeys.set(sku, key)

  return key
}

async function buy(sku: string, button: HTMLButtonElement): Promise<void> {
  const label = button.textContent
  button.disabled = true
  button.textContent = 'Оформляем...'

  try {
    const order = await createOrder(sku, idempotencyKeyFor(sku))
    idempotencyKeys.delete(sku)
    window.location.href = `./order.html?id=${encodeURIComponent(order.id)}`
  } catch (error) {
    button.disabled = false
    button.textContent = label ?? 'Купить'

    const message =
      error instanceof ApiError ? error.message : 'Не удалось создать заказ. Проверьте, что бэкенд запущен.'
    window.alert(message)
  }
}

function requireElement<T extends HTMLElement>(selector: string): T {
  const element = document.querySelector<T>(selector)

  if (element === null) {
    throw new Error(`Не найден элемент ${selector}`)
  }

  return element
}

/** Ряд из пяти карточек: каталог ТЗ короче трёх ряд, поэтому идём по кругу. */
const rowOf = (products: readonly Product[], offset: number): readonly Product[] =>
  Array.from({ length: 5 }, (_, index) => products[(offset + index) % products.length]).filter(
    (product): product is Product => product !== undefined,
  )

function init(): void {
  hydrateIcons(document)

  mountBanner(requireElement('[data-banner]'))
  renderServices(requireElement('[data-services]'))
  renderTabs(requireElement('[data-tabs]'))
  renderReviews(requireElement('[data-reviews]'))
  renderFooter(requireElement('[data-footer]'))
  mountCurrencySwitcher(requireElement('[data-currency-switcher]'))
  mountCatalogMenu(requireElement('[data-catalog-button]'), requireElement('[data-catalog-menu]'))

  const rows: readonly [string, number][] = [
    ['[data-cards="popular"]', 0],
    ['[data-cards="recommended"]', 5],
    ['[data-cards="other"]', 10],
  ]

  void fetchProducts()
    .then((products) => {
      if (products.length === 0) {
        return
      }

      rows.forEach(([selector, offset]) => {
        renderProductCards(requireElement(selector), rowOf(products, offset), (sku, button) => void buy(sku, button))
      })
    })
    .catch(() => {
      rows.forEach(([selector]) => {
        requireElement(selector).innerHTML =
          '<p class="order-note">Каталог не загрузился: бэкенд недоступен. Запустите стек командой make up.</p>'
      })
    })
}

init()

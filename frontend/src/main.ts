import './styles/app.scss'

import { ApiError, createOrder, fetchProducts } from './api/client'
import { mountBanner } from './ui/banner'
import { mountCatalogMenu } from './ui/catalogMenu'
import { mountCurrencySwitcher } from './ui/currencySwitcher'
import { hydrateIcons } from './ui/icons'
import { renderProductCards, renderTabs } from './ui/productCards'
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

    const message = error instanceof ApiError ? error.message : 'Не удалось создать заказ. Проверьте, что бэкенд запущен.'
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

function init(): void {
  hydrateIcons(document)

  mountBanner(requireElement('[data-banner]'))
  renderServices(requireElement('[data-services]'))
  renderTabs(requireElement('[data-tabs]'))
  mountCurrencySwitcher(requireElement('[data-currency-switcher]'))
  mountCatalogMenu(
    requireElement('[data-catalog-button]'),
    requireElement('[data-catalog-menu]'),
    requireElement('[data-search]'),
  )

  const cards = requireElement<HTMLElement>('[data-cards]')

  void fetchProducts()
    .then((products) => {
      renderProductCards(cards, products.slice(0, 5), (sku, button) => void buy(sku, button))
    })
    .catch(() => {
      cards.innerHTML = '<p class="order-note">Каталог не загрузился: бэкенд недоступен. Запустите стек командой make up.</p>'
    })
}

init()

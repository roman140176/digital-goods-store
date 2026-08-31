import './styles/app.scss'

import { ApiError, createOrder, fetchProducts } from './api/client'
import type { Product } from './api/types'
import { mountBanner } from './ui/banner'
import { mountCatalogMenu } from './ui/catalogMenu'
import { mountCurrencySwitcher } from './ui/currencySwitcher'
import { renderFooter } from './ui/footer'
import { hydrateIcons } from './ui/hydrateIcons'
import { notify } from './ui/notice'
import { renderProductCards, renderTabs } from './ui/productCards'
import { renderReviews } from './ui/reviews'
import { renderServices } from './ui/services'
import { mountSteamTopup } from './ui/steamTopup'

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

  // randomUUID есть только в защищённом контексте: на http-хостинге
  // (ТЗ разрешает деплой фронта куда угодно) нужен запасной вариант.
  const key =
    typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `idem_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 12)}`
  idempotencyKeys.set(sku, key)

  return key
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
    const order = await createOrder(sku, idempotencyKeyFor(sku), promoCode)
    idempotencyKeys.delete(sku)
    window.location.href = `./order.html?id=${encodeURIComponent(order.id)}`

    return null
  } catch (error) {
    button.disabled = false
    button.textContent = label ?? 'Купить'

    return error instanceof ApiError ? error.message : 'Не удалось создать заказ. Проверьте, что бэкенд запущен.'
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

  // Этап 4: единственное место в макете, где покупатель может ввести код.
  mountSteamTopup(requireElement('[data-steam]'), {
    sku: 'STEAM-TOPUP-500',
    onBuy: buy,
    onError: notify,
  })

  const rows: readonly [string, number][] = [
    ['[data-cards="popular"]', 0],
    ['[data-cards="recommended"]', 5],
    ['[data-cards="other"]', 10],
  ]

  void fetchProducts()
    .then((products) => {
      if (products.length === 0) {
        rows.forEach(([selector]) => {
          requireElement(selector).innerHTML = '<p class="order-note">Каталог пуст: в базе нет товаров.</p>'
        })

        return
      }

      rows.forEach(([selector, offset]) => {
        renderProductCards(requireElement(selector), rowOf(products, offset), (sku, button) => {
          void buy(sku, button).then((message) => {
            if (message !== null) {
              notify(message)
            }
          })
        })
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

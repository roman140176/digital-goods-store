import './styles/app.scss'

import { ApiError, createOrder, fetchCatalog } from './api/client'
import type { CatalogItem, Offer, StreamEnvelope } from './api/types'
import { idempotencyKeyFor, releaseIdempotencyKey, buyOffer } from './purchase'
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
 * Покупка. Возвращает null при успехе (страница уже уходит на статус заказа)
 * либо текст ошибки: показывать её решает вызывающий — у поля промокода или
 * общим сообщением.
 *
 * Ключи идемпотентности (idempotencyKeyFor/releaseIdempotencyKey) и покупка
 * предложения каталога (buyOffer, вместе с обработкой sold_out и
 * альтернативой) — общий модуль `purchase.ts`: страница каталога (задача
 * 12) использует те же самые функции, а не свою копию.
 */
async function buy(sku: string, button: HTMLButtonElement, promoCode?: string): Promise<string | null> {
  const label = button.textContent
  button.disabled = true
  button.textContent = 'Оформляем...'

  try {
    const order = await createOrder({ sku }, idempotencyKeyFor(sku), promoCode)
    releaseIdempotencyKey(sku)
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

const CARDS_PER_ROW = 5
const ROW_SELECTORS = ['[data-cards="popular"]', '[data-cards="recommended"]', '[data-cards="other"]'] as const
const TOTAL_CARDS = CARDS_PER_ROW * ROW_SELECTORS.length

/**
 * Три ряда по пять карточек одним запросом. Пока позиций хватает (объёмный
 * каталог, make seed-catalog) ряды идут простыми срезами и не повторяются; на
 * стандартном сиде ТЗ позиций двенадцать, и без добора по кругу третий ряд
 * показывал бы две карточки из пяти — а макет требует пять. Поэтому короткий
 * каталог заполняет ряды по кругу, как на первом этапе.
 */
function renderRows(items: readonly CatalogItem[]): void {
  ROW_SELECTORS.forEach((selector, rowIndex) => {
    const start = rowIndex * CARDS_PER_ROW

    if (items.length >= TOTAL_CARDS) {
      renderProductCards(requireElement(selector), items.slice(start, start + CARDS_PER_ROW), buyOffer)

      return
    }

    const cycled: CatalogItem[] = []

    for (let index = 0; index < CARDS_PER_ROW && items.length > 0; index += 1) {
      const item = items[(start + index) % items.length]

      if (item !== undefined) {
        cycled.push(item)
      }
    }

    renderProductCards(requireElement(selector), cycled, buyOffer)
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

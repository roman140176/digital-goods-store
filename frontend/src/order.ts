import './styles/app.scss'

import { ApiError, fetchOrder, simulatePayment } from './api/client'
import type { Order } from './api/types'
import { dateTime, escapeHtml as esc, money } from './format'
import { notify } from './ui/notice'
import { hydrateIcons } from './ui/hydrateIcons'

/**
 * Страница статуса заказа.
 *
 * Выдача асинхронная, поэтому страница опрашивает API, пока заказ не придёт
 * в финальное состояние. Интервал растёт, опрос замолкает на скрытой вкладке
 * и через две минуты уступает место кнопке «Обновить»: вечно долбить сервер
 * из-за восстановимого заказа незачем. Дизайн по ТЗ не требуется — нужен
 * рабочий вид, но состояния должны читаться однозначно.
 */

const POLL_MS = 1000
const POLL_MAX_MS = 5000
const POLL_BUDGET_MS = 120_000

const actionLabels: Record<string, string> = {
  order_created: 'Заказ создан',
  payment_paid: 'Оплата подтверждена',
  payment_failed: 'Оплата не прошла',
  code_issued: 'Код получен у поставщика',
  code_confirmed: 'Код подтверждён повторно',
  delivery_gave_up: 'Выдача остановлена, требуется восстановление',
  delivery_error: 'Сбой выдачи',
  manual_redeliver: 'Запрошена повторная выдача',
}

const page = document.querySelector<HTMLElement>('[data-order-page]')
const orderId = new URLSearchParams(window.location.search).get('id')

let timer: number | undefined
let pollDelay = POLL_MS
let pollUntil = Date.now() + POLL_BUDGET_MS

/** Оплата отправлена: кнопки не должны ожить на следующей перерисовке. */
let paymentSent = false

function renderMissing(message: string): void {
  if (page !== null) {
    page.innerHTML = `<div class="order-card"><p class="order-note">${message}</p>
      <div class="order-actions"><a class="order-button order-button--ghost" href="./index.html">На витрину</a></div></div>`
  }
}

function renderOrder(order: Order): void {
  if (page === null) {
    return
  }

  const code =
    order.code === null
      ? ''
      : `<div class="order-code">
           <div class="order-grid__label">Ваш код</div>
           <div class="order-code__value">${esc(order.code)}</div>
           <p class="order-note">Выдан поставщиком «${esc(order.delivered_by ?? '—')}» ${dateTime(order.delivered_at)}</p>
         </div>`

  const recovery = order.is_recoverable
    ? `<p class="order-note">Оплата прошла, но код пока не выдан. Заказ в восстановимом состоянии: система повторит выдачу
         автоматически, а менеджер может запустить её вручную из админки. Второй раз деньги не спишутся и второй ключ не уйдёт.</p>`
    : ''

  const failure =
    order.delivery?.last_error == null
      ? ''
      : `<p class="order-note">Последняя ошибка выдачи: ${esc(order.delivery.last_error)}</p>`

  page.innerHTML = `
    <div class="order-card">
      <h1 class="order-page__title">${esc(order.name ?? order.sku)}</h1>
      <div class="order-page__id">${esc(order.id)}</div>

      <div class="order-status" data-status="${esc(order.status)}">
        <span class="order-status__dot"></span>
        <span>${esc(order.status_label)}</span>
      </div>

      <div class="order-grid">
        <div>
          <div class="order-grid__label">Цена</div>
          <div class="order-grid__value">${money(order.amount_minor, order.currency)}</div>
        </div>
        <div>
          <div class="order-grid__label">Скидка</div>
          <div class="order-grid__value">${order.discount_minor === 0 ? '—' : money(order.discount_minor, order.currency)}</div>
        </div>
        <div>
          <div class="order-grid__label">К оплате</div>
          <div class="order-grid__value">${money(order.total_minor, order.currency)}</div>
        </div>
        <div>
          <div class="order-grid__label">Промокод</div>
          <div class="order-grid__value">${esc(order.promo_code ?? '—')}</div>
        </div>
      </div>

      ${code}
      ${recovery}
      ${failure}

      <div class="order-actions">
        <button class="order-button" type="button" data-pay="success" ${order.status === 'created' && !paymentSent ? '' : 'disabled'}>
          Оплатить (успех)
        </button>
        <button class="order-button order-button--ghost" type="button" data-pay="fail" ${order.status === 'created' && !paymentSent ? '' : 'disabled'}>
          Оплатить (неуспех)
        </button>
        <a class="order-button order-button--ghost" href="./index.html">На витрину</a>
      </div>

      <p class="order-note">Реального эквайринга нет: кнопки отправляют вебхук по контракту из ТЗ.</p>
    </div>

    <div class="order-card">
      <div class="order-grid__label">История заказа</div>
      <ul class="order-timeline">
        ${order.history
          .map(
            (entry) => `
          <li class="order-timeline__item">
            <span class="order-timeline__time">${dateTime(entry.at)}</span>
            <span>${actionLabels[entry.action] ?? esc(entry.action)}
              ${entry.from === null ? '' : `<span class="order-note">${esc(entry.from)} → ${esc(entry.to ?? '—')} (${esc(entry.actor)})</span>`}
            </span>
          </li>`,
          )
          .join('')}
      </ul>
    </div>`

  hydrateIcons(page)

  page.querySelectorAll<HTMLButtonElement>('[data-pay]').forEach((button) => {
    button.addEventListener('click', () => {
      const result = button.dataset.pay === 'fail' ? 'fail' : 'success'
      button.disabled = true
      paymentSent = true

      void simulatePayment(order.id, result)
        .then(() => {
          // После оплаты состояние меняется быстро: опрос снова частый.
          pollDelay = POLL_MS
          pollUntil = Date.now() + POLL_BUDGET_MS

          return poll()
        })
        .catch(() => {
          paymentSent = false
          button.disabled = false
          notify('Не удалось отправить вебхук оплаты.')
        })
    })
  })
}

async function poll(): Promise<void> {
  if (orderId === null) {
    return
  }

  try {
    const order = await fetchOrder(orderId)
    renderOrder(order)

    if (timer !== undefined) {
      window.clearTimeout(timer)
      timer = undefined
    }

    // Опрос продолжается, пока заказ не финализирован. Восстановимые
    // состояния тоже опрашиваем: их дожимает реконсилятор — но с растущим
    // интервалом и не дольше отведённого времени.
    if (!order.is_final && !document.hidden && Date.now() < pollUntil) {
      timer = window.setTimeout(() => void poll(), pollDelay)
      pollDelay = Math.min(Math.round(pollDelay * 1.5), POLL_MAX_MS)
    }
  } catch (error) {
    renderMissing(
      error instanceof ApiError && error.status === 404
        ? 'Заказ не найден.'
        : 'Не удалось получить заказ: бэкенд недоступен.',
    )
  }
}

// На скрытой вкладке опрашивать незачем, при возврате — сразу свежий запрос.
document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    if (timer !== undefined) {
      window.clearTimeout(timer)
      timer = undefined
    }

    return
  }

  pollDelay = POLL_MS
  pollUntil = Date.now() + POLL_BUDGET_MS
  void poll()
})

hydrateIcons(document)

if (orderId === null) {
  renderMissing('В адресе не указан идентификатор заказа.')
} else {
  void poll()
}

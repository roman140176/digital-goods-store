import './styles/app.scss'

import { ApiError, fetchOrder, simulatePayment } from './api/client'
import type { Order } from './api/types'
import { dateTime, money } from './format'
import { hydrateIcons } from './ui/hydrateIcons'

/**
 * Страница статуса заказа.
 *
 * Выдача асинхронная, поэтому страница опрашивает API раз в секунду, пока
 * заказ не придёт в финальное состояние. Дизайн по ТЗ не требуется —
 * нужен рабочий вид, но состояния должны читаться однозначно.
 */

const POLL_MS = 1000

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
           <div class="order-code__value">${order.code}</div>
           <p class="order-note">Выдан поставщиком «${order.delivered_by ?? '—'}» ${dateTime(order.delivered_at)}</p>
         </div>`

  const recovery = order.is_recoverable
    ? `<p class="order-note">Оплата прошла, но код пока не выдан. Заказ в восстановимом состоянии: система повторит выдачу
         автоматически, а менеджер может запустить её вручную из админки. Второй раз деньги не спишутся и второй ключ не уйдёт.</p>`
    : ''

  const failure =
    order.delivery?.last_error == null
      ? ''
      : `<p class="order-note">Последняя ошибка выдачи: ${order.delivery.last_error}</p>`

  page.innerHTML = `
    <div class="order-card">
      <h1 class="order-page__title">${order.name ?? order.sku}</h1>
      <div class="order-page__id">${order.id}</div>

      <div class="order-status" data-status="${order.status}">
        <span class="order-status__dot"></span>
        <span>${order.status_label}</span>
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
          <div class="order-grid__value">${order.promo_code ?? '—'}</div>
        </div>
      </div>

      ${code}
      ${recovery}
      ${failure}

      <div class="order-actions">
        <button class="order-button" type="button" data-pay="success" ${order.status === 'created' ? '' : 'disabled'}>
          Оплатить (успех)
        </button>
        <button class="order-button order-button--ghost" type="button" data-pay="fail" ${order.status === 'created' ? '' : 'disabled'}>
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
            <span>${actionLabels[entry.action] ?? entry.action}
              ${entry.from === null ? '' : `<span class="order-note">${entry.from} → ${entry.to ?? '—'} (${entry.actor})</span>`}
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

      void simulatePayment(order.id, result)
        .then(() => void poll())
        .catch(() => {
          button.disabled = false
          window.alert('Не удалось отправить вебхук оплаты.')
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
    }

    // Опрос продолжается, пока заказ не финализирован. Восстановимые
    // состояния тоже опрашиваем: их дожимает реконсилятор.
    if (!order.is_final) {
      timer = window.setTimeout(() => void poll(), POLL_MS)
    }
  } catch (error) {
    renderMissing(
      error instanceof ApiError && error.status === 404
        ? 'Заказ не найден.'
        : 'Не удалось получить заказ: бэкенд недоступен.',
    )
  }
}

hydrateIcons(document)

if (orderId === null) {
  renderMissing('В адресе не указан идентификатор заказа.')
} else {
  void poll()
}

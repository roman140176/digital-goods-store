import './styles/app.scss'

import { ApiError, fetchOrder, repriceOrder, simulatePayment } from './api/client'
import type { Order, StreamEnvelope } from './api/types'
import { dateTime, escapeHtml as esc, money } from './format'
import { notify } from './ui/notice'
import { hydrateIcons } from './ui/hydrateIcons'
import { connectRealtime, shouldApply } from './realtime'

/**
 * Страница статуса заказа.
 *
 * Основной источник правды — поток order:<id> (топик заказа) и catalog
 * (там же живёт цена предложения, см. 6.6 спеки). Опрос остаётся фоллбэком
 * на случай, если поток не поднялся: страница обязана оставаться правдивой
 * и без него (4.2 ТЗ), просто медленнее реагирует на изменения.
 */

const POLL_MS = 1000
const POLL_MAX_MS = 5000
const POLL_BUDGET_MS = 120_000

/** Сколько ждать открытия потока, прежде чем включить опрос фоллбэком. */
const STREAM_GRACE_MS = 3000

const actionLabels: Record<string, string> = {
  order_created: 'Заказ создан',
  payment_paid: 'Оплата подтверждена',
  payment_failed: 'Оплата не прошла',
  code_issued: 'Код получен у поставщика',
  code_confirmed: 'Код подтверждён повторно',
  delivery_gave_up: 'Выдача остановлена, требуется восстановление',
  delivery_error: 'Сбой выдачи',
  manual_redeliver: 'Запрошена повторная выдача',
  price_accepted: 'Новая цена принята',
}

const page = document.querySelector<HTMLElement>('[data-order-page]')
const orderId = new URLSearchParams(window.location.search).get('id')

let timer: number | undefined
let pollDelay = POLL_MS
let pollUntil = Date.now() + POLL_BUDGET_MS

/** Опрос — фоллбэк: включается один раз, если поток не открылся, и выключается, как только он открылся. */
let fallbackActive = false
let streamOpened = false

/** Оплата отправлена: кнопки не должны ожить на следующей перерисовке. */
let paymentSent = false

let currentOrder: Order | null = null

/**
 * Последняя известная цена предложения этого заказа — независимо от
 * amount_minor заказа (6.6 спеки). Источник переключается по мере того, что
 * доступно:
 *
 * 1. Пока живого события потока ещё не было (livePriceReceived === false) —
 *    источник правды снапшот заказа (applyOrder), то есть и опрос-фоллбэк
 *    тоже: без этого требование 1.3 ломалось бы ровно тогда, когда стример
 *    недоступен, — фоллбэк исправно получал бы свежую цену в каждом
 *    снапшоте, но плашка никогда не появлялась бы, потому что её обновляла
 *    только несуществующая живая подписка.
 * 2. С первого живого offer.updated (applyOfferEvent) — источник правды
 *    только поток, снапшоты эту переменную больше не трогают: иначе более
 *    старый снапшот (опрос вообще не гарантирует порядок доставки
 *    относительно потока) откатил бы уже применённую свежую цену назад.
 */
let latestOfferPrice: number | null = null
let livePriceReceived = false

/** Гард по версии для order.updated: тот же принцип, что и shouldApply в realtime.ts, но ключ здесь один — сам заказ. */
let lastOrderEventId = 0

let reservationExpiredLocally = false
let countdownTimer: number | undefined
let countdownExpiresAt: string | null = null

function renderMissing(message: string): void {
  if (page !== null) {
    page.innerHTML = `<div class="order-card"><p class="order-note">${message}</p>
      <div class="order-actions"><a class="order-button order-button--ghost" href="./index.html">На витрину</a></div></div>`
  }
}

/**
 * Остаток брони в формате «M:SS», посчитанный от expires_at, а не от
 * засыпающего seconds_left (см. докблок countdownTimer).
 *
 * Вверх, а не вниз: дедлайн пишет база временем НАЧАЛА транзакции брони и
 * хранит его с точностью до секунды, поэтому уже в первом кадре остаток
 * меньше пяти минут на доли секунды — с floor покупатель видел бы «4:59»
 * сразу после создания заказа. Тем же ceil считает серверное seconds_left
 * (OrderPresenter), так что число на странице и число в API не расходятся.
 */
function formatCountdown(expiresAtIso: string): string {
  const remainingMs = new Date(expiresAtIso).getTime() - Date.now()
  const totalSeconds = Math.max(0, Math.ceil(remainingMs / 1000))
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60

  return `${minutes}:${String(seconds).padStart(2, '0')}`
}

/**
 * «Бронь истекла» показывается по любому из двух источников правды:
 * либо клиентский тикер первым досчитал до нуля (reservationExpiredLocally,
 * пока order.reservation ещё не обнулился — сервер не догнал), либо
 * планировщик уже снял бронь и заказ пришёл со статусом
 * reservation_expired. Одного reservationExpiredLocally мало: в этом тесте
 * (и вообще when планировщик успевает раньше клиентских часов) order.updated
 * приходит с reservation: null и status: reservation_expired СРАЗУ, минуя
 * локальный тикер вовсе, — без явной проверки статуса блок просто исчезал
 * бы, а бейдж статуса наверху не несёт ссылку «Вернуться к товару».
 *
 * order.reservation !== null в локальной ветке — чтобы не показать
 * «истекла» для заказа, который тем временем успели оплатить (3.3 ТЗ):
 * оплата тоже обнуляет reservation, но статус тогда 'paid', а не
 * 'reservation_expired', и мы должны молчать здесь, отдав слово статусу.
 */
function reservationBlock(order: Order): string {
  const expired = order.status === 'reservation_expired' || (reservationExpiredLocally && order.reservation !== null)

  if (expired) {
    return `<div class="order-reservation order-reservation--expired" data-reservation>
      Бронь истекла. <a href="./index.html">Вернуться к товару</a>
    </div>`
  }

  if (order.reservation === null) {
    return ''
  }

  return `<div class="order-reservation" data-reservation>Бронь действует ещё ${esc(formatCountdown(order.reservation.expires_at))}</div>`
}

/** Кнопки оплаты заблокированы, пока новая цена не принята: сервер и так откажет 409, но отказ незачем показывать (1.3 ТЗ). */
function priceChanged(order: Order): boolean {
  return latestOfferPrice !== null && latestOfferPrice !== order.amount_minor
}

function priceChangeBlock(order: Order): string {
  if (!priceChanged(order) || latestOfferPrice === null) {
    return ''
  }

  return `<div class="order-price-change" data-price-change>
    <p>Цена изменилась: ${money(order.amount_minor, order.currency)} → ${money(latestOfferPrice, order.currency)}</p>
    <button class="order-button" type="button" data-accept-price ${acceptingPrice ? 'disabled' : ''}>Оплатить по новой цене</button>
  </div>`
}

/** Оплата принята, а товара не нашлось (6.4 спеки, ветка 3) — без этой плашки исход был бы не виден покупателю вовсе. */
function refundBlock(order: Order): string {
  if (!order.refund_required) {
    return ''
  }

  return `<p class="order-note order-note--refund">Оплата получена, но товар закончился. Заказ поставлен на возврат.</p>`
}

function paymentsBlocked(order: Order): boolean {
  return order.status !== 'created' || paymentSent || reservationExpiredLocally || priceChanged(order)
}

function render(): void {
  if (page === null || currentOrder === null) {
    return
  }

  const order = currentOrder

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

  const blocked = paymentsBlocked(order)

  page.innerHTML = `
    <div class="order-card">
      <h1 class="order-page__title">${esc(order.name ?? order.sku)}</h1>
      <div class="order-page__id">${esc(order.id)}</div>

      <div class="order-status" data-status="${esc(order.status)}">
        <span class="order-status__dot"></span>
        <span>${esc(order.status_label)}</span>
      </div>

      ${reservationBlock(order)}
      ${priceChangeBlock(order)}
      ${refundBlock(order)}

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
        <button class="order-button" type="button" data-pay="success" ${blocked ? 'disabled' : ''}>
          Оплатить (успех)
        </button>
        <button class="order-button order-button--ghost" type="button" data-pay="fail" ${blocked ? 'disabled' : ''}>
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
      void pay(order.id, result)
    })
  })

  page.querySelector<HTMLButtonElement>('[data-accept-price]')?.addEventListener('click', () => {
    void acceptNewPrice(order.id)
  })

  ensureCountdown(order)
}

/**
 * Заводит (или перезапускает, если сменился дедлайн) секундный тикер
 * обратного отсчёта. Тикер не вызывает render(): он точечно правит текст
 * узла [data-reservation], иначе каждую секунду перерисовывалась бы вся
 * карточка заказа — того же рода мигание, которого просит избегать 5.1 ТЗ.
 * render() тикер вызывает только один раз — в момент, когда отсчёт дошёл до
 * нуля, потому что это смена СОСТОЯНИЯ (гаснут кнопки, появляется ссылка),
 * а не косметическое обновление числа.
 */
function stopCountdownTimer(): void {
  if (countdownTimer !== undefined) {
    window.clearInterval(countdownTimer)
    countdownTimer = undefined
  }
}

function ensureCountdown(order: Order): void {
  if (order.reservation === null) {
    stopCountdownTimer()
    countdownExpiresAt = null

    return
  }

  // Уже решили на клиенте, что бронь истекла: это render() ИЗ САМОГО
  // тикера (см. ниже) — сервер ещё не прислал order.updated с
  // reservation: null, тот же order.reservation виден повторно. Тикеру
  // больше нечего делать, а вот перезапустить его здесь и сбросить флаг
  // обратно в false было бы багом — на следующей секунде текст снова
  // «протух» бы в «Бронь действует», хотя дедлайн давно позади.
  if (reservationExpiredLocally) {
    stopCountdownTimer()

    return
  }

  const expiresAt = order.reservation.expires_at

  if (countdownExpiresAt === expiresAt && countdownTimer !== undefined) {
    return
  }

  stopCountdownTimer()
  countdownExpiresAt = expiresAt

  countdownTimer = window.setInterval(() => {
    const remainingMs = new Date(expiresAt).getTime() - Date.now()

    if (remainingMs <= 0) {
      stopCountdownTimer()
      reservationExpiredLocally = true
      render()

      return
    }

    const node = document.querySelector<HTMLElement>('[data-reservation]')

    if (node !== null) {
      node.textContent = `Бронь действует ещё ${formatCountdown(expiresAt)}`
    }
  }, 1000)
}

let realtimeStarted = false

/**
 * Применяет снапшот заказа — из первого refresh(), из ретрая опроса, из
 * poll() фоллбэка. Поднимает поток при самом первом успешном снапшоте,
 * откуда бы он ни пришёл: иначе временный сбой бэкенда при загрузке
 * страницы навсегда оставил бы её без потока, лишь на опросе (см.
 * STREAM_GRACE_MS выше — окно для решения даётся один раз, при самой первой
 * попытке подключения).
 *
 * latestOfferPrice обновляется из КАЖДОГО снапшота, пока livePriceReceived
 * ещё false (см. её докблок) — не только из первого: пока стример
 * недоступен, единственный способ узнать о смене цены — это опрос, и без
 * обновления на каждом тике плашка цены (1.3 ТЗ) никогда не появилась бы в
 * этом режиме.
 */
function applyOrder(order: Order): void {
  if (!livePriceReceived && order.offer !== null) {
    latestOfferPrice = order.offer.price_minor
  }

  currentOrder = order
  render()

  if (!realtimeStarted) {
    realtimeStarted = true
    startRealtime(order.stream_cursor)
  }
}

async function pay(id: string, result: 'success' | 'fail'): Promise<void> {
  paymentSent = true
  render()

  try {
    await simulatePayment(id, result)
    // После оплаты состояние меняется быстро: если опрос ещё активен,
    // пусть догонит немедленно, а не ждёт текущий (уже подросший) интервал.
    pollDelay = POLL_MS
    pollUntil = Date.now() + POLL_BUDGET_MS
    await refresh()
  } catch (error) {
    paymentSent = false

    if (error instanceof ApiError && error.reason === 'price_changed') {
      const current = error.details?.current_price_minor

      if (typeof current === 'number') {
        latestOfferPrice = current
      }

      notify('Цена предложения изменилась, подтвердите новую цену.')
    } else {
      notify('Не удалось отправить вебхук оплаты.')
    }

    render()
  }
}

/**
 * Идёт запрос reprice — вторая попытка (двойной клик) не бронирует лишний
 * сетевой запрос: reprice и так идемпотентен, но клику незачем удваивать
 * его без причины. Кнопка при этом тоже гаснет синхронно (см.
 * priceChangeBlock) — как и у кнопок оплаты, защита не только на уровне
 * этого флага, но и видна в разметке.
 */
let acceptingPrice = false

/**
 * «Оплатить по новой цене»: сначала reprice принимает актуальную цену,
 * затем — раз покупатель явно согласился платить — сразу пробуем успешную
 * оплату, не заставляя нажимать вторую кнопку ради того же намерения.
 */
async function acceptNewPrice(id: string): Promise<void> {
  if (latestOfferPrice === null || acceptingPrice) {
    return
  }

  acceptingPrice = true
  render()

  try {
    const order = await repriceOrder(id, latestOfferPrice)
    applyOrder(order)
    await pay(order.id, 'success')
  } catch (error) {
    if (error instanceof ApiError && error.reason === 'price_changed') {
      const current = error.details?.current_price_minor

      if (typeof current === 'number') {
        latestOfferPrice = current
      }

      notify('Цена снова изменилась, попробуйте ещё раз.')
    } else {
      notify(error instanceof ApiError ? error.message : 'Не удалось принять новую цену.')
    }
  } finally {
    // Флаг гасится ДО финального render(), а не после: иначе разметка успела
    // бы отрисоваться с disabled="" по ещё не сброшенному acceptingPrice, и
    // кнопка осталась бы задизейбленной навсегда после неудачного reprice —
    // следующий render() пришёл бы нескоро (или не пришёл бы вовсе, если
    // плашка при этом больше ни от чего не перерисовывается).
    acceptingPrice = false
    render()
  }
}

async function refresh(): Promise<void> {
  if (orderId === null) {
    return
  }

  try {
    const order = await fetchOrder(orderId)
    applyOrder(order)

    if (timer !== undefined) {
      window.clearTimeout(timer)
      timer = undefined
    }
  } catch (error) {
    renderMissing(
      error instanceof ApiError && error.status === 404
        ? 'Заказ не найден.'
        : 'Не удалось получить заказ: бэкенд недоступен.',
    )
  }
}

/** Опрос — фоллбэк (см. докблок файла): растущий интервал, молчит на скрытой вкладке, не длиннее отведённого бюджета. */
async function poll(): Promise<void> {
  if (orderId === null || !fallbackActive) {
    return
  }

  await refresh()

  if (!fallbackActive || currentOrder === null) {
    return
  }

  if (!currentOrder.is_final && !document.hidden && Date.now() < pollUntil) {
    timer = window.setTimeout(() => void poll(), pollDelay)
    pollDelay = Math.min(Math.round(pollDelay * 1.5), POLL_MAX_MS)
  }
}

function startFallbackPoll(): void {
  if (fallbackActive || streamOpened) {
    return
  }

  fallbackActive = true
  pollDelay = POLL_MS
  pollUntil = Date.now() + POLL_BUDGET_MS
  void poll()
}

function stopFallbackPoll(): void {
  fallbackActive = false

  if (timer !== undefined) {
    window.clearTimeout(timer)
    timer = undefined
  }
}

/** order.updated — снапшот заказа целиком (та же форма, что у GET /api/orders/{id}, см. типы). */
function applyOrderEvent(envelope: StreamEnvelope): void {
  if (envelope.id <= lastOrderEventId) {
    return
  }

  lastOrderEventId = envelope.id
  applyOrder(envelope.payload as unknown as Order)
}

/** offer.updated в топике catalog — интересует только цена ЭТОГО заказа (6.6 спеки), остальные предложения витрины здесь ни при чём. */
function applyOfferEvent(envelope: StreamEnvelope): void {
  if (currentOrder === null) {
    return
  }

  const offerId = Number(envelope.payload.offer_id)

  if (!Number.isFinite(offerId) || offerId !== currentOrder.offer_id) {
    return
  }

  if (!shouldApply(offerId, envelope.id)) {
    return
  }

  const price = envelope.payload.price_minor

  if (typeof price !== 'number') {
    return
  }

  // С первого живого события эта переменная переходит под управление потока
  // (см. докблок latestOfferPrice) — даже если цена в этом кадре совпала с
  // уже известной и рендерить нечего, флаг всё равно поднимается: иначе
  // следующий снапшот (опрос ещё может быть активен, если поток открылся
  // только что) продолжил бы её перезаписывать.
  livePriceReceived = true

  if (price !== latestOfferPrice) {
    latestOfferPrice = price
    render()
  }
}

function startRealtime(cursor: number): void {
  if (orderId === null) {
    return
  }

  connectRealtime({
    topics: [`order:${orderId}`, 'catalog'],
    cursor,
    onEvent: (envelope) => {
      if (envelope.type === 'order.updated') {
        applyOrderEvent(envelope)
      } else if (envelope.type === 'offer.updated') {
        applyOfferEvent(envelope)
      }
    },
    onResync: () => {
      void refresh()
    },
    onOpen: () => {
      streamOpened = true
      stopFallbackPoll()
    },
    // Поток однажды открылся и упал — фоллбэк обязан вернуться сам, а не
    // ждать, пока реконнект (до 15 с по расписанию realtime.ts) снова
    // поднимет соединение: без этого страница молчала бы всё это время не
    // только по цене, но и по статусу заказа.
    //
    // livePriceReceived тоже сбрасывается: «доверять только потоку»
    // (см. её докблок) верно, только пока поток действительно жив. Если он
    // умер, единственный оставшийся источник правды — снова снапшоты
    // фоллбэк-опроса, и им нужно разрешить писать в latestOfferPrice, иначе
    // ровно этот же баг вернётся при ВТОРОМ падении потока — после того как
    // он один раз уже успел прислать хоть одно живое событие.
    onClose: () => {
      streamOpened = false
      livePriceReceived = false
      startFallbackPoll()
    },
  })
}

// На скрытой вкладке опрашивать незачем, при возврате — сразу свежий запрос.
// Поток эта логика не касается: требование 1.1 — «во всех открытых
// вкладках», включая свёрнутые и на втором мониторе.
document.addEventListener('visibilitychange', () => {
  if (!fallbackActive) {
    return
  }

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

// «Назад» отдаёт страницу из bfcache вместе со старым DOM: без этого на
// экране остаётся, например, «Ожидает оплаты» у уже оплаченного заказа —
// bfcache восстанавливает снимок страницы, сделанный ДО оплаты, и ничего
// не запрашивает заново само по себе (4.2 ТЗ).
window.addEventListener('pageshow', (event) => {
  if (event.persisted) {
    void refresh()
  }
})

hydrateIcons(document)

if (orderId === null) {
  renderMissing('В адресе не указан идентификатор заказа.')
} else {
  // startRealtime поднимается из applyOrder — при первом же успешном
  // снапшоте, откуда бы он ни пришёл (см. её докблок).
  void refresh()

  window.setTimeout(() => {
    if (!streamOpened) {
      startFallbackPoll()
    }
  }, STREAM_GRACE_MS)
}

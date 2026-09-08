import { apiBase } from './api/client'
import type { StreamEnvelope } from './api/types'

/**
 * Подписка на поток изменений (см. 4.4, 4.6 спеки).
 *
 * Гард по версии обязателен: bigserial выделяет id события ДО коммита
 * транзакции (см. 4.3 спеки), поэтому «опоздавшее» событие может прийти
 * ПОСЛЕ более свежего. Без гарда оно перетёрло бы актуальное состояние
 * старым — карточка показала бы цену, которой уже нет. Ключ — offer_id, а
 * не sku: у одной позиции несколько предложений с независимыми ценами и
 * остатками.
 */
const appliedByOffer = new Map<number, number>()

export function shouldApply(offerId: number, eventId: number): boolean {
  const applied = appliedByOffer.get(offerId)

  if (applied !== undefined && applied >= eventId) {
    return false
  }

  appliedByOffer.set(offerId, eventId)

  return true
}

/** Все типы кадров, которые вообще способен прислать стример (3.1 спеки). */
const FRAME_TYPES = ['offer.updated', 'offer.gone', 'order.updated', 'resync'] as const

/** 1 → 2 → 4 → 8 → 15 с: см. докблок connectRealtime про 503 без отступа. */
const RECONNECT_DELAYS_MS = [1000, 2000, 4000, 8000, 15000]

export interface RealtimeOptions {
  /** Топики подписки, например ['catalog'] или ['order:ord_1', 'catalog']. */
  readonly topics: readonly string[]
  /** Курсор снапшота (stream_cursor из ответа API) — стартовая позиция потока. */
  readonly cursor: number
  readonly onEvent: (envelope: StreamEnvelope) => void
  /** Курсор клиента вне окна журнала (4.4 спеки) — нужен свежий снапшот. */
  readonly onResync: () => void
  /**
   * Соединение подтверждено сервером. Не часть контракта задачи 10 — нужна
   * странице заказа (задача 11) для решения «ждать поток или включить опрос
   * фоллбэком»; подписка на витрине этот колбэк не передаёт.
   */
  readonly onOpen?: () => void
  /**
   * Соединение потеряно, начат реконнект. Как и onOpen — не часть контракта
   * задачи 10. Странице заказа этот сигнал нужен симметрично onOpen: если
   * ОДНАЖДЫ открывшийся поток всё же упал, фоллбэк-опрос, единожды
   * выключенный по onOpen, обязан вернуться, а не оставить страницу немой
   * до того, как реконнект сам не поднимет соединение (это может занять до
   * 15 с по расписанию ниже).
   */
  readonly onClose?: () => void
}

/**
 * Открывает SSE-поток и возвращает функцию отключения.
 *
 * Переподключение — своё, поверх браузерного. Встроенный реконнект
 * EventSource не отступает при 503 (лимит соединений стримера исчерпан, см.
 * 4.2 спеки) и долбил бы перегруженный процесс без паузы между попытками.
 * Поэтому при любой ошибке сокет закрывается явно (`es.close()`) — это не
 * даёт браузеру запустить собственный реконнект — и открывается заново уже
 * по расписанию 1→2→4→8→15 c.
 *
 * Поток НЕ закрывается на скрытой вкладке: требование 1.1 — «во всех
 * открытых вкладках», а не только в активной, поэтому document.hidden
 * здесь намеренно не проверяется нигде в этом модуле.
 */
export function connectRealtime(options: RealtimeOptions): () => void {
  let source: EventSource | null = null
  let cursor = options.cursor
  let attempt = 0
  let reconnectTimer: number | undefined
  let stopped = false

  function open(): void {
    const query = new URLSearchParams({
      topics: options.topics.join(','),
      last_event_id: String(cursor),
    })
    const es = new EventSource(`${apiBase}/api/stream?${query.toString()}`)
    source = es

    es.onopen = () => {
      // Соединение подтверждено сервером — отступ реконнекта сбрасывается:
      // следующий обрыв снова начнёт с секунды, а не донашивает счётчик от
      // уже решённой проблемы.
      attempt = 0
      options.onOpen?.()
    }

    for (const type of FRAME_TYPES) {
      es.addEventListener(type, (event) => {
        // Типы DOM для EventSource не знают наших имён событий и типизируют
        // произвольный addEventListener как обычный Event. В рантайме это
        // всегда MessageEvent — сервер шлёт только текстовые SSE-кадры.
        const message = event as MessageEvent<string>
        const id = Number(message.lastEventId)

        if (Number.isFinite(id) && id > cursor) {
          cursor = id
        }

        if (type === 'resync') {
          options.onResync()

          return
        }

        let payload: Record<string, unknown>

        try {
          payload = JSON.parse(message.data) as Record<string, unknown>
        } catch {
          // Сервер отдаёт валидный JSON всегда; битый кадр — не повод ронять
          // весь поток исключением из обработчика события.
          return
        }

        options.onEvent({ id, type, payload })
      })
    }

    es.onerror = () => {
      es.close()

      if (source === es) {
        source = null
      }

      if (!stopped) {
        options.onClose?.()
        scheduleReconnect()
      }
    }
  }

  function scheduleReconnect(): void {
    const delay = RECONNECT_DELAYS_MS[Math.min(attempt, RECONNECT_DELAYS_MS.length - 1)] ?? 15000
    attempt += 1

    reconnectTimer = window.setTimeout(() => {
      reconnectTimer = undefined
      open()
    }, delay)
  }

  open()

  return () => {
    stopped = true

    if (reconnectTimer !== undefined) {
      window.clearTimeout(reconnectTimer)
      reconnectTimer = undefined
    }

    source?.close()
    source = null
  }
}

import type { CatalogResponse, OffersResponse, Order } from './types'

/**
 * Базовый адрес API. Пустой, когда витрину раздаёт тот же nginx, что и
 * бэкенд. Если фронт задеплоен отдельно (ТЗ это разрешает), адрес задаётся
 * переменной сборки VITE_API_BASE.
 *
 * Экспортируется: realtime.ts строит от него же адрес SSE-потока — один и
 * тот же способ понять, куда стучаться, не должен дублироваться текстом в
 * двух файлах.
 */
export const apiBase = (import.meta.env.VITE_API_BASE ?? '').replace(/\/+$/, '')

const endpoint = (path: string): string => `${apiBase}/api${path}`

/**
 * Query-строка без пустых параметров: чем короче запрос каталога, тем легче
 * читать его в логах и devtools, а необязательный фильтр (price_min и
 * подобные) сервер и так трактует как «не задан» при отсутствии — пустая
 * строка или undefined значат одно и то же, дублировать не нужно.
 */
function buildQuery(params: Record<string, string | number | boolean | undefined>): string {
  const query = new URLSearchParams()

  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === false || value === '') {
      continue
    }

    query.set(key, value === true ? '1' : String(value))
  }

  const text = query.toString()

  return text === '' ? '' : `?${text}`
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly reason: string | null = null,
    /**
     * Остальные поля ответа как пришли: alternative у sold_out,
     * current_price_minor у price_changed. Общий parse() не решает, какая
     * причина отказа что значит, — это знает только вызывающий код, у
     * которого есть контекст запроса (createOrder знает про alternative,
     * repriceOrder — про current_price_minor).
     */
    readonly details: Record<string, unknown> | null = null,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

async function parse<T>(response: Response): Promise<T> {
  const payload: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    const details = payload !== null && typeof payload === 'object' ? (payload as Record<string, unknown>) : {}
    const reason = typeof details.reason === 'string' ? details.reason : null
    const rawMessage = typeof details.message === 'string' ? details.message : null

    // Ошибки валидации Laravel приходят на своём языке и говорят о полях
    // запроса, а не о том, что делать пользователю: наружу их не показываем.
    const message =
      reason !== null && rawMessage !== null
        ? rawMessage
        : details.errors !== undefined
          ? 'Заказ не принят: проверьте выбранный товар и промокод.'
          : (rawMessage ?? `Запрос завершился с кодом ${response.status}`)

    throw new ApiError(message, response.status, reason, details)
  }

  return payload as T
}

export interface CatalogParams {
  readonly q?: string
  readonly type?: string
  readonly price_min?: number
  readonly price_max?: number
  readonly in_stock?: boolean
  readonly seller?: number
  readonly sort?: 'price_asc' | 'price_desc' | 'name'
  readonly page?: number
  readonly per_page?: number
}

/**
 * GET /api/catalog — поиск, фильтры, сортировка и постраничная выдача (7 спеки).
 *
 * signal — опциональный: страница каталога (задача 12) отменяет предыдущий
 * запрос при каждом новом вводе через AbortController, а витрине и странице
 * заказа отмена не нужна вовсе (они запрашивают снапшот один раз или после
 * resync, гонки последовательных вводов там нет).
 */
export async function fetchCatalog(params: CatalogParams = {}, signal?: AbortSignal): Promise<CatalogResponse> {
  // signal добавляется условно: RequestInit.signal типизирован как
  // AbortSignal | null, а exactOptionalPropertyTypes запрещает присваивать
  // такому полю значение undefined явно (в отличие от простого отсутствия
  // ключа) — тот же приём, что и у promo_code в createOrder ниже.
  const response = await fetch(endpoint(`/catalog${buildQuery({ ...params })}`), {
    headers: { Accept: 'application/json' },
    ...(signal !== undefined ? { signal } : {}),
  })

  return parse<CatalogResponse>(response)
}

/**
 * GET /api/offers?sku= — все активные предложения позиции. Не используется
 * прямо на витрине (альтернатива при sold_out приходит готовой в теле 409),
 * но нужна как публичный метод API-клиента — например, для карточки товара
 * со списком продавцов.
 */
export async function fetchOffers(sku: string): Promise<OffersResponse> {
  const response = await fetch(endpoint(`/offers${buildQuery({ sku })}`), {
    headers: { Accept: 'application/json' },
  })

  return parse<OffersResponse>(response)
}

/**
 * Цель покупки: offer_id — основной путь (карточка каталога знает своё
 * лучшее предложение), sku — совместимость с первым этапом и с кнопкой
 * пополнения Steam (сервер сам выбирает лучшее предложение позиции).
 */
export type OrderTarget = { readonly offerId: number } | { readonly sku: string }

/**
 * Создание заказа. Ключ идемпотентности обязателен: именно он превращает
 * двойной клик по «Купить» в один заказ.
 */
export async function createOrder(target: OrderTarget, idempotencyKey: string, promoCode?: string): Promise<Order> {
  const body: Record<string, unknown> = 'offerId' in target ? { offer_id: target.offerId } : { sku: target.sku }

  if (promoCode !== undefined) {
    body.promo_code = promoCode
  }

  const response = await fetch(endpoint('/orders'), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'Idempotency-Key': idempotencyKey,
    },
    body: JSON.stringify(body),
  })

  return parse<Order>(response)
}

export async function fetchOrder(id: string): Promise<Order> {
  const response = await fetch(endpoint(`/orders/${encodeURIComponent(id)}`), {
    headers: { Accept: 'application/json' },
  })

  return parse<Order>(response)
}

/**
 * Принятие изменившейся цены предложения до оплаты (требование 1.3 ТЗ, 6.6
 * спеки). expectedPriceMinor — цена, которую покупатель видел (последнее
 * offer.updated) и явно подтверждает; сервер сверяет её с фактической ценой
 * предложения и откажет 409 price_changed, если она успела разойтись ещё раз.
 */
export async function repriceOrder(id: string, expectedPriceMinor: number): Promise<Order> {
  const response = await fetch(endpoint(`/orders/${encodeURIComponent(id)}/reprice`), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify({ expected_price_minor: expectedPriceMinor }),
  })

  return parse<Order>(response)
}

/**
 * Эмулятор оплаты: реального эквайринга нет, ручка шлёт вебхук по контракту.
 *
 * Тело ответа возвращается наружу, а не выбрасывается: ручка отвечает 200,
 * даже когда САМ вебхук ответил ошибкой, и настоящий исход лежит в
 * webhook_status. Без него любой 5xx применения (например, конфликт
 * блокировок с тиком освобождения брони) выглядел бы для страницы успехом.
 */
export async function simulatePayment(id: string, result: 'success' | 'fail'): Promise<{ webhook_status?: number }> {
  const response = await fetch(endpoint(`/dev/pay/${encodeURIComponent(id)}?result=${result}`), {
    method: 'POST',
    headers: { Accept: 'application/json' },
  })

  return parse<{ webhook_status?: number }>(response)
}

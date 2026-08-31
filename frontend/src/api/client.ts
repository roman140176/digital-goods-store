import type { Order, Product } from './types'

/**
 * Базовый адрес API. Пустой, когда витрину раздаёт тот же nginx, что и
 * бэкенд. Если фронт задеплоен отдельно (ТЗ это разрешает), адрес задаётся
 * переменной сборки VITE_API_BASE.
 */
const apiBase = (import.meta.env.VITE_API_BASE ?? '').replace(/\/+$/, '')

const endpoint = (path: string): string => `${apiBase}/api${path}`

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly reason: string | null = null,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

async function parse<T>(response: Response): Promise<T> {
  const payload: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    const details = (payload ?? {}) as { message?: string; reason?: string; errors?: unknown }

    // Ошибки валидации Laravel приходят на своём языке и говорят о полях
    // запроса, а не о том, что делать пользователю: наружу их не показываем.
    const message =
      details.reason !== undefined && details.message !== undefined
        ? details.message
        : details.errors !== undefined
          ? 'Заказ не принят: проверьте выбранный товар и промокод.'
          : (details.message ?? `Запрос завершился с кодом ${response.status}`)

    throw new ApiError(message, response.status, details.reason ?? null)
  }

  return payload as T
}

export async function fetchProducts(): Promise<readonly Product[]> {
  const response = await fetch(endpoint('/products'), { headers: { Accept: 'application/json' } })
  const payload = await parse<{ products: readonly Product[] }>(response)

  return payload.products
}

/**
 * Создание заказа. Ключ идемпотентности обязателен: именно он превращает
 * двойной клик по «Купить» в один заказ.
 */
export async function createOrder(sku: string, idempotencyKey: string, promoCode?: string): Promise<Order> {
  const response = await fetch(endpoint('/orders'), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'Idempotency-Key': idempotencyKey,
    },
    body: JSON.stringify(promoCode === undefined ? { sku } : { sku, promo_code: promoCode }),
  })

  return parse<Order>(response)
}

export async function fetchOrder(id: string): Promise<Order> {
  const response = await fetch(endpoint(`/orders/${encodeURIComponent(id)}`), {
    headers: { Accept: 'application/json' },
  })

  return parse<Order>(response)
}

/** Эмулятор оплаты: реального эквайринга нет, ручка шлёт вебхук по контракту. */
export async function simulatePayment(id: string, result: 'success' | 'fail'): Promise<void> {
  const response = await fetch(endpoint(`/dev/pay/${encodeURIComponent(id)}?result=${result}`), {
    method: 'POST',
    headers: { Accept: 'application/json' },
  })

  await parse<unknown>(response)
}

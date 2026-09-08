/** Тип позиции каталога — общий словарь для карточки и её декоративного заголовка. */
export type ProductType = 'topup' | 'key' | 'subscription' | 'giftcard'

export interface Seller {
  readonly id: number
  readonly name: string
}

/**
 * Снимок предложения продавца — форма, в которой оно приходит и в ответе
 * заказа (order.offer), и в событии offer.updated, и в /api/offers.
 * Событие несёт полное состояние объекта, а не дельту (см. 4.1 спеки), и
 * ровно это состояние здесь описано: точечное обновление карточки
 * (applyOfferState в productCards.ts) не мержит поля из разных ответов, а
 * подставляет этот объект целиком.
 */
export interface Offer {
  readonly offer_id: number
  readonly sku: string
  readonly name: string
  readonly price_minor: number
  readonly currency: string
  readonly available: number
  readonly status: 'active' | 'hidden'
  readonly seller: Seller
}

/**
 * Предложение внутри карточки каталога — облегчённая проекция Offer: sku,
 * name и status у неё избыточны (совпадают с полями самой позиции или всегда
 * 'active', иначе оно не попало бы в выдачу как лучшее). Pick вместо копии
 * полей — чтобы форма гарантированно не разошлась с Offer при правке одного
 * из двух мест.
 */
export type CatalogBestOffer = Pick<Offer, 'offer_id' | 'price_minor' | 'currency' | 'available' | 'seller'>

export interface CatalogItem {
  readonly sku: string
  readonly name: string
  readonly type: ProductType
  readonly image: string | null
  readonly offers_count: number
  readonly best: CatalogBestOffer
}

export interface CatalogResponse {
  readonly items: readonly CatalogItem[]
  readonly total: number
  readonly page: number
  readonly per_page: number
  readonly stream_cursor: number
}

export interface OffersResponse {
  readonly offers: readonly Offer[]
  readonly stream_cursor: number
}

export type OrderStatus =
  | 'created'
  | 'paid'
  | 'delivering'
  | 'delivered'
  | 'payment_failed'
  | 'out_of_stock'
  | 'delivery_failed'
  // Бронь не выкупили за отведённое время (TTL, см. RESERVATION_TTL_SECONDS):
  // единица вернулась в продажу всем. Поздняя оплата всё ещё принимается
  // сервером (6.4 спеки), поэтому статус не значит «заказ мёртв».
  | 'reservation_expired'

export interface OrderHistoryEntry {
  readonly action: string
  readonly from: string | null
  readonly to: string | null
  readonly actor: string
  readonly at: string | null
}

export interface DeliveryInfo {
  readonly state: string
  readonly attempts: number
  readonly supplier: string | null
  readonly last_error: string | null
}

/**
 * Бронь единицы под заказ. null, если брони сейчас нет: либо она истекла и
 * была снята планировщиком, либо единица уже продана (оплата стирает
 * reserved_until — отсчёту после оплаты полагается ни на что не влиять, 3.3
 * ТЗ). seconds_left посчитан сервером на момент ответа и годится только как
 * стартовое значение — дальше отсчёт ведёт клиент сам от expires_at (см.
 * order.ts): вкладка могла быть свёрнута, а seconds_left «замёрзнуть» в
 * прошлом.
 */
export interface Reservation {
  readonly unit_id: number
  readonly expires_at: string
  readonly seconds_left: number
}

export interface Order {
  readonly id: string
  readonly sku: string
  readonly name: string | null
  readonly status: OrderStatus
  readonly status_label: string
  readonly is_final: boolean
  readonly is_recoverable: boolean
  readonly offer_id: number
  readonly amount_minor: number
  readonly discount_minor: number
  readonly total_minor: number
  readonly currency: string
  readonly promo_code: string | null
  readonly code: string | null
  readonly delivered_by: string | null
  readonly delivered_at: string | null
  readonly created_at: string | null
  // Оплата прошла, а товара под неё не нашлось (единицу забрали раньше, чем
  // успела примениться поздняя оплата, см. 6.4 спеки): деньги не возвращаются
  // автоматически (реального эквайринга нет), заказ виден в админке отдельным
  // списком. Пользователю — плашка «Заказ поставлен на возврат», не тупик.
  readonly refund_required: boolean
  readonly offer: Offer | null
  readonly reservation: Reservation | null
  readonly stream_cursor: number
  readonly delivery: DeliveryInfo | null
  readonly history: readonly OrderHistoryEntry[]
}

/**
 * Кадр SSE после разбора: id — версия события для гарда по объекту (см.
 * realtime.ts), type — 'offer.updated' | 'offer.gone' | 'order.updated' |
 * 'resync', payload — тело как есть, без предположений о форме: она разная
 * у разных type, разбирает его конкретный обработчик события.
 */
export interface StreamEnvelope {
  readonly id: number
  readonly type: string
  readonly payload: Record<string, unknown>
}

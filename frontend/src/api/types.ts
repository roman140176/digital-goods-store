export interface Product {
  readonly sku: string
  readonly name: string
  readonly type: 'topup' | 'key' | 'subscription' | 'giftcard'
  readonly price_minor: number
  readonly currency: string
  readonly image: string | null
}

export type OrderStatus =
  | 'created'
  | 'paid'
  | 'delivering'
  | 'delivered'
  | 'payment_failed'
  | 'out_of_stock'
  | 'delivery_failed'

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

export interface Order {
  readonly id: string
  readonly sku: string
  readonly name: string | null
  readonly status: OrderStatus
  readonly status_label: string
  readonly is_final: boolean
  readonly is_recoverable: boolean
  readonly amount_minor: number
  readonly discount_minor: number
  readonly total_minor: number
  readonly currency: string
  readonly promo_code: string | null
  readonly code: string | null
  readonly delivered_by: string | null
  readonly delivered_at: string | null
  readonly created_at: string | null
  readonly delivery: DeliveryInfo | null
  readonly history: readonly OrderHistoryEntry[]
}

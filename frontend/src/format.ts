const rubles = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 })

const symbols: Record<string, string> = { RUB: '₽', USD: '$', KZT: '₸' }

export const money = (minor: number, currency = 'RUB'): string =>
  `${rubles.format(Math.round(minor / 100))} ${symbols[currency] ?? currency}`

export const dateTime = (iso: string | null): string =>
  iso === null ? '—' : new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'medium' })

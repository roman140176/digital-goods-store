/**
 * Данные из API уходят в innerHTML, поэтому экранируются здесь, а не по месту:
 * например last_error выдачи — это строка из ответа поставщика.
 */
export const escapeHtml = (value: string): string =>
  value.replace(/[&<>"']/g, (char) => {
    const map: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }

    return map[char] ?? char
  })

const rubles = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 })

const symbols: Record<string, string> = { RUB: '₽', USD: '$', KZT: '₸' }

export const money = (minor: number, currency = 'RUB'): string =>
  `${rubles.format(Math.round(minor / 100))} ${symbols[currency] ?? currency}`

export const dateTime = (iso: string | null): string =>
  iso === null ? '—' : new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'medium' })

/**
 * Ряд сервисов. Цвет рамки у каждой плитки свой — так в макете,
 * это брендовый цвет приложения, а не общий стиль.
 */
export interface ServiceIcon {
  readonly slug: string
  readonly label: string
  readonly border: string
  /** PUBG оформлен иначе: чёрная обёртка и белая рамка 3px. */
  readonly variant?: 'framed'
  /** TikTok лежит на чёрной подложке. */
  readonly background?: string
}

export const services: readonly ServiceIcon[] = [
  { slug: 'steam', label: 'Steam', border: '#1482b3' },
  { slug: 'telegram', label: 'Telegram', border: '#45baee' },
  { slug: 'roblox', label: 'Roblox', border: '#b8c5ff' },
  { slug: 'brawl-stars', label: 'Brawl Stars', border: '#e86eff' },
  { slug: 'pubg-mobile', label: 'PUBG Mob...', border: '#ffffff', variant: 'framed' },
  { slug: 'app-store', label: 'App Store', border: '#4acdff' },
  { slug: 'chatgpt', label: 'ChatGPT', border: '#38d4ad' },
  { slug: 'playstation', label: 'PlayStation', border: '#117fda' },
  { slug: 'tiktok', label: 'TikTok', border: '#454545', background: '#000000' },
  { slug: 'mobile-legends', label: 'Mobile Leg..', border: 'rgba(255,255,255,0.45)' },
]

/** Ряд сервисов из макета. Иконки выгружены из Figma и пережаты в webp. */
export interface ServiceIcon {
  readonly slug: string
  readonly label: string
}

export const services: readonly ServiceIcon[] = [
  { slug: 'steam', label: 'Steam' },
  { slug: 'telegram', label: 'Telegram' },
  { slug: 'roblox', label: 'Roblox' },
  { slug: 'brawl-stars', label: 'Brawl Stars' },
  { slug: 'pubg-mobile', label: 'PUBG Mob...' },
  { slug: 'app-store', label: 'App Store' },
  { slug: 'chatgpt', label: 'ChatGPT' },
  { slug: 'playstation', label: 'PlayStation' },
  { slug: 'tiktok', label: 'TikTok' },
  { slug: 'mobile-legends', label: 'Mobile Leg..' },
]

/** Табы над рядом карточек. По ТЗ статичны, интерактив в них не требуется. */
export interface CatalogTab {
  readonly icon: string
  readonly label: string
}

export const tabs: readonly CatalogTab[] = [
  { icon: 'donate', label: 'Донат' },
  { icon: 'subscribes', label: 'Подписки' },
  { icon: 'items', label: 'Предметы' },
  { icon: 'accounts', label: 'Аккаунты' },
  { icon: 'keys', label: 'Ключи' },
  { icon: 'currency', label: 'Игровая валюта' },
  { icon: 'other', label: 'Другое' },
]

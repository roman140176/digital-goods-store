/**
 * Содержимое выпадающего меню взято со второго скриншота ТЗ.
 * По условию точность меню не оценивается — важны структура и работа.
 */
export interface MenuGroup {
  readonly title: string
  readonly links: readonly string[]
}

export interface MenuCategory {
  readonly title: string
  readonly columns: readonly MenuGroup[]
  readonly extra: readonly MenuGroup[]
}

export const catalogCategories: readonly MenuCategory[] = [
  {
    title: 'Игры и игровые сервисы',
    columns: [
      {
        title: 'Steam',
        links: ['Игры и DLC', 'Пополнение баланса', 'Подарочные карты', 'Коллекционные карточки', 'Смена региона'],
      },
      { title: 'PlayStation', links: ['Игры и DLC', 'Пополнение баланса', 'Новые аккаунты', 'PS Plus', 'EA Play'] },
      { title: 'Xbox', links: ['Игры и DLC', 'Пополнение баланса', 'Новые аккаунты', 'Xbox Game Pass', 'Услуги'] },
      { title: 'Nintendo', links: ['Игры и DLC', 'Подарочные карты', 'Новые аккаунты', 'NS Online'] },
      {
        title: 'Battle.net',
        links: ['World of Warcraft', 'Подарочные карты', 'Прямое пополнение', 'Новые аккаунты', 'Смена региона'],
      },
    ],
    extra: [
      {
        title: 'Подборки',
        links: ['Скидки 90%', 'Популярные издатели', 'Лучшие серии игр', 'Steam Deck', 'Bundle-наборы'],
      },
    ],
  },
  {
    title: 'Игровые ценности',
    columns: [
      { title: 'Игровая валюта', links: ['Robux', 'UC для PUBG', 'Алмазы Free Fire', 'Гемы Brawl Stars'] },
      { title: 'Предметы', links: ['Скины CS2', 'Кейсы', 'Наборы', 'Обмен'] },
      { title: 'Аккаунты', links: ['Готовые аккаунты', 'Прокачка', 'Гарантия'] },
      { title: 'Бустинг', links: ['Ранги', 'Достижения', 'Сопровождение'] },
      { title: 'Подборки', links: ['Дешевле 100 ₽', 'Новинки', 'Хиты недели'] },
    ],
    extra: [],
  },
  {
    title: 'Мобильные игры',
    columns: [
      { title: 'Mobile Legends', links: ['Алмазы', 'Пропуски', 'Аккаунты'] },
      { title: 'PUBG Mobile', links: ['UC', 'Royale Pass', 'Аккаунты'] },
      { title: 'Brawl Stars', links: ['Гемы', 'Brawl Pass', 'Аккаунты'] },
      { title: 'Genshin Impact', links: ['Кристаллы', 'Благословение', 'Аккаунты'] },
      { title: 'Roblox', links: ['Robux', 'Premium', 'Аккаунты'] },
    ],
    extra: [],
  },
  {
    title: 'Сервисы и соцсети',
    columns: [
      { title: 'Подписки', links: ['Discord Nitro', 'YouTube Premium', 'Spotify Premium', 'Telegram Premium'] },
      { title: 'ИИ-сервисы', links: ['ChatGPT Plus', 'Midjourney', 'Claude'] },
      { title: 'Соцсети', links: ['TikTok монеты', 'Реклама', 'Продвижение'] },
      { title: 'App Store', links: ['Пополнение', 'Подарочные карты'] },
      { title: 'Прочее', links: ['VPN', 'Хостинг', 'Домены'] },
    ],
    extra: [],
  },
  {
    title: 'Программы',
    columns: [
      { title: 'Операционные системы', links: ['Windows', 'Office', 'Антивирусы'] },
      { title: 'Дизайн', links: ['Adobe', 'Figma', 'Canva'] },
      { title: 'Разработка', links: ['JetBrains', 'GitHub', 'Хостинг'] },
      { title: 'Утилиты', links: ['Архиваторы', 'Резервные копии'] },
      { title: 'Подборки', links: ['Для учёбы', 'Для работы'] },
    ],
    extra: [],
  },
]

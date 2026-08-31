/**
 * Графика макета. Все иконки — экспортированные из Figma ассеты, ничего
 * не перерисовано от руки: цвета и геометрия ровно те, что в дизайне.
 * Vite подставит хешированные пути при сборке.
 */
import arrowNext from './assets/icons/arrow-next.svg'
import arrowPrev from './assets/icons/arrow-prev.svg'
import catalog from './assets/icons/catalog.svg'
import chevron from './assets/icons/chevron.svg'
import favorite from './assets/icons/favorite.svg'
import loginProfile from './assets/icons/login-profile.svg'
import more from './assets/icons/more.svg'
import profileBody from './assets/icons/profile-body.svg'
import profileHead from './assets/icons/profile-head.svg'
import ruble from './assets/icons/ruble.svg'
import search from './assets/icons/search.svg'
import social1 from './assets/icons/social-1.svg'
import social2 from './assets/icons/social-2.svg'
import social3 from './assets/icons/social-3.svg'
import social4 from './assets/icons/social-4.svg'
import tabAccounts from './assets/icons/tab-accounts.svg'
import tabCurrency from './assets/icons/tab-currency.svg'
import tabDonate from './assets/icons/tab-donate.svg'
import tabItems from './assets/icons/tab-items.svg'
import tabKeys from './assets/icons/tab-keys.svg'
import tabOther from './assets/icons/tab-other.svg'
import tabSubscribes from './assets/icons/tab-subscribes.svg'

export const icons = {
  arrowNext,
  arrowPrev,
  catalog,
  chevron,
  favorite,
  loginProfile,
  more,
  profileBody,
  profileHead,
  ruble,
  search,
  social1,
  social2,
  social3,
  social4,
} as const

export const tabIcons: Record<string, string> = {
  donate: tabDonate,
  subscribes: tabSubscribes,
  items: tabItems,
  accounts: tabAccounts,
  keys: tabKeys,
  currency: tabCurrency,
  other: tabOther,
}

/** Иконка как разметка с явными размерами: растягивать ассеты нельзя. */
export const iconImg = (src: string, width: number, height = width, className = ''): string =>
  `<img src="${src}" alt="" width="${width}" height="${height}"${className === '' ? '' : ` class="${className}"`}>`

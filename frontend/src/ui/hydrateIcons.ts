import { iconImg, icons } from '../icons'

/**
 * Размеры каждой иконки заданы явно, ровно как в макете: общего правила
 * «растянуть по контейнеру» нет, иначе ассеты деформируются.
 */
const spec: Record<string, readonly [string, number, number]> = {
  catalog: [icons.catalog, 20, 20],
  search: [icons.search, 20, 20],
  favorite: [icons.favorite, 14, 13],
  'profile-body': [icons.profileBody, 12, 8],
  'profile-head': [icons.profileHead, 8, 8],
  'login-profile': [icons.loginProfile, 20, 20],
  ruble: [icons.ruble, 20, 20],
  chevron: [icons.chevron, 12, 12],
  'arrow-prev': [icons.arrowPrev, 22, 22],
  'arrow-next': [icons.arrowNext, 22, 22],
  more: [icons.more, 28, 28],
  'social-1': [icons.social1, 34, 34],
  'social-2': [icons.social2, 34, 34],
  'social-3': [icons.social3, 34, 34],
  'social-4': [icons.social4, 18, 18],
}

/** Подставляет экспортированные из макета иконки в места с data-icon. */
export function hydrateIcons(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-icon]').forEach((node) => {
    const entry = spec[node.dataset.icon ?? '']

    if (entry !== undefined) {
      const [src, width, height] = entry
      node.outerHTML = iconImg(src, width, height)
    }
  })
}

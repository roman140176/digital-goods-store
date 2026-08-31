import {
  iconArrowLeft,
  iconArrowRight,
  iconChevronDown,
  iconChevronRight,
  iconGrid,
  iconHeart,
  iconMore,
  iconProfile,
  iconRuble,
  iconSearch,
  iconTab,
} from '../icons'

const registry: Record<string, () => string> = {
  grid: iconGrid,
  search: iconSearch,
  heart: iconHeart,
  profile: iconProfile,
  'arrow-left': iconArrowLeft,
  'arrow-right': iconArrowRight,
  'chevron-right': iconChevronRight,
  'chevron-down': iconChevronDown,
  ruble: iconRuble,
  more: iconMore,
}

/** Подставляет инлайновую графику в места, помеченные data-icon. */
export function hydrateIcons(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-icon]').forEach((node) => {
    const name = node.dataset.icon ?? ''
    const render = registry[name]

    node.innerHTML = render === undefined ? iconTab(name) : render()
  })
}

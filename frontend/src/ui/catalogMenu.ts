import { catalogCategories } from '../data/catalogMenu'
import { iconChevronRight } from '../icons'

/**
 * Интерактив №2: выпадающее меню «Каталог».
 *
 * Открывается кликом по кнопке, закрывается повторным кликом, кликом вне
 * меню и клавишей Escape. При открытии поиск переходит в активное
 * состояние — так показано на втором скриншоте ТЗ.
 */
export function mountCatalogMenu(button: HTMLElement, panel: HTMLElement, search: HTMLElement): void {
  const renderGroup = (title: string, links: readonly string[]): string => `
    <div class="catalog-menu__group">
      <h3 class="catalog-menu__group-title">${title}<span class="catalog-menu__chevron">${iconChevronRight()}</span></h3>
      <ul class="catalog-menu__links">
        ${links.map((link) => `<li><a class="catalog-menu__link" href="#">${link}</a></li>`).join('')}
      </ul>
    </div>`

  const renderContent = (index: number): string => {
    const category = catalogCategories[index]

    if (category === undefined) {
      return ''
    }

    const columns = `<div class="catalog-menu__columns">${category.columns
      .map((group) => renderGroup(group.title, group.links))
      .join('')}</div>`

    const extra =
      category.extra.length === 0
        ? ''
        : `<div class="catalog-menu__columns">${category.extra.map((group) => renderGroup(group.title, group.links)).join('')}</div>`

    return columns + extra
  }

  panel.innerHTML = `
    <div class="catalog-menu__inner">
      <div class="catalog-menu__sidebar" role="tablist" aria-label="Категории каталога">
        ${catalogCategories
          .map(
            (category, index) => `
          <button class="catalog-menu__category" type="button" role="tab" data-category="${index}" aria-selected="${index === 0}">
            <span>${category.title}</span>
            <span class="catalog-menu__chevron">${iconChevronRight()}</span>
          </button>`,
          )
          .join('')}
      </div>
      <div class="catalog-menu__content" data-menu-content>${renderContent(0)}</div>
    </div>`

  const content = panel.querySelector<HTMLElement>('[data-menu-content]')
  const categoryButtons = Array.from(panel.querySelectorAll<HTMLElement>('[data-category]'))

  categoryButtons.forEach((categoryButton) => {
    const activate = (): void => {
      const index = Number(categoryButton.dataset.category)

      categoryButtons.forEach((node) => node.setAttribute('aria-selected', String(node === categoryButton)))

      if (content !== null) {
        content.innerHTML = renderContent(index)
      }
    }

    categoryButton.addEventListener('click', activate)
    categoryButton.addEventListener('mouseenter', activate)
  })

  const setOpen = (open: boolean): void => {
    panel.classList.toggle('is-open', open)
    button.setAttribute('aria-expanded', String(open))
    search.classList.toggle('is-active', open)
  }

  const isOpen = (): boolean => panel.classList.contains('is-open')

  button.addEventListener('click', (event) => {
    event.stopPropagation()
    setOpen(!isOpen())
  })

  panel.addEventListener('click', (event) => event.stopPropagation())

  document.addEventListener('click', () => {
    if (isOpen()) {
      setOpen(false)
    }
  })

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen()) {
      setOpen(false)
      button.focus()
    }
  })

  setOpen(false)
}

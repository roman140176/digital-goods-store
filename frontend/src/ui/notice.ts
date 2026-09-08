/**
 * Сообщение об ошибке витрины.
 *
 * Нативный alert для этого не годится: он блокирует страницу и выглядит
 * чужеродно, а ошибки здесь ожидаемые — исчерпанный промокод, неподнятый
 * бэкенд, раскупленный товар. Сообщение живёт в одном узле и само гаснет.
 */

export interface NoticeAction {
  readonly label: string
  readonly onClick: () => void
}

const VISIBLE_MS = 6000

/**
 * Сообщение с действием («Купить у другого продавца за ...») должно
 * продержаться дольше обычного тоста: 6 секунд мало, чтобы прочитать текст
 * и решиться нажать кнопку, а не просто увидеть и забыть.
 */
const VISIBLE_WITH_ACTION_MS = 15000

let host: HTMLElement | undefined
let hideTimer: number | undefined

function hide(): void {
  host?.classList.remove('is-visible')
}

export function notify(message: string, action?: NoticeAction): void {
  if (host === undefined) {
    host = document.createElement('div')
    host.className = 'notice'
    host.setAttribute('role', 'status')
    host.setAttribute('aria-live', 'polite')
    document.body.append(host)
  }

  host.replaceChildren(document.createTextNode(message))

  if (action !== undefined) {
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'notice__action'
    button.textContent = action.label
    // Клик прячет тост сразу: колбэк обычно либо уводит со страницы (заказ
    // создан), либо сам покажет новое сообщение — висящая кнопка поверх
    // него была бы лишней.
    button.addEventListener('click', () => {
      hide()
      action.onClick()
    })
    host.append(button)
  }

  host.classList.add('is-visible')

  if (hideTimer !== undefined) {
    window.clearTimeout(hideTimer)
  }

  hideTimer = window.setTimeout(hide, action === undefined ? VISIBLE_MS : VISIBLE_WITH_ACTION_MS)
}

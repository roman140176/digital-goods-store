/**
 * Сообщение об ошибке витрины.
 *
 * Нативный alert для этого не годится: он блокирует страницу и выглядит
 * чужеродно, а ошибки здесь ожидаемые — исчерпанный промокод, неподнятый
 * бэкенд. Сообщение живёт в одном узле и само гаснет.
 */

const VISIBLE_MS = 6000

let host: HTMLElement | undefined
let hideTimer: number | undefined

export function notify(message: string): void {
  if (host === undefined) {
    host = document.createElement('div')
    host.className = 'notice'
    host.setAttribute('role', 'status')
    host.setAttribute('aria-live', 'polite')
    document.body.append(host)
  }

  host.textContent = message
  host.classList.add('is-visible')

  if (hideTimer !== undefined) {
    window.clearTimeout(hideTimer)
  }

  hideTimer = window.setTimeout(() => host?.classList.remove('is-visible'), VISIBLE_MS)
}

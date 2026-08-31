/**
 * Блок пополнения Steam из макета.
 *
 * Кнопка «Ввести промокод» раскрывает поле — это этап 4 задания, и другого
 * места для кода в макете нет. «Оплатить» покупает товар каталога
 * STEAM-TOPUP-500: именно этот номинал нарисован в блоке. Логин Steam по ТЗ
 * остаётся декоративным, пересчёт валют не требуется.
 *
 * Скидку считает сервер: сюда попадает только его ответ. Ошибка по коду
 * показывается у поля, остальные — общим сообщением.
 */
export interface SteamTopupOptions {
  readonly sku: string
  readonly onBuy: (sku: string, button: HTMLButtonElement, promoCode?: string) => Promise<string | null>
  readonly onError: (message: string) => void
}

export function mountSteamTopup(root: HTMLElement, options: SteamTopupOptions): void {
  const toggle = root.querySelector<HTMLButtonElement>('[data-steam-promo-toggle]')
  const field = root.querySelector<HTMLElement>('[data-steam-promo-field]')
  const input = root.querySelector<HTMLInputElement>('[data-steam-promo-input]')
  const error = root.querySelector<HTMLElement>('[data-steam-promo-error]')
  const pay = root.querySelector<HTMLButtonElement>('[data-steam-pay]')

  if (toggle === null || field === null || input === null || error === null || pay === null) {
    return
  }

  const setOpen = (open: boolean): void => {
    field.hidden = !open
    toggle.setAttribute('aria-expanded', String(open))
  }

  const clearError = (): void => {
    error.textContent = ''
    error.hidden = true
  }

  toggle.addEventListener('click', () => {
    const open = field.hidden
    setOpen(open)

    if (open) {
      input.focus()
    }
  })

  input.addEventListener('input', clearError)

  pay.addEventListener('click', () => {
    clearError()

    const code = input.value.trim()
    const promoCode = code === '' ? undefined : code

    void options.onBuy(options.sku, pay, promoCode).then((message) => {
      if (message === null) {
        return
      }

      // Промокод отправляли — значит и ответ про него: показываем у поля.
      if (promoCode !== undefined) {
        setOpen(true)
        error.textContent = message
        error.hidden = false

        return
      }

      options.onError(message)
    })
  })
}

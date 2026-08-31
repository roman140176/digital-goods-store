/**
 * Интерактив №3: переключатель валют в блоке пополнения Steam.
 * Меняется только активное состояние — пересчёт суммы по ТЗ не требуется.
 */
export function mountCurrencySwitcher(root: HTMLElement): void {
  const buttons = Array.from(root.querySelectorAll<HTMLButtonElement>('[data-currency]'))

  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      buttons.forEach((node) => node.setAttribute('aria-pressed', String(node === button)))
    })
  })
}

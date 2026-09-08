/**
 * Поиск в шапке витрины (задача 12 ТЗ).
 *
 * На витрине это не встроенный мгновенный поиск, а вход в каталог: Enter
 * или клик по «Найти» уводит на страницу каталога с заполненным q, а уже
 * там поиск живой и мгновенный (см. catalog.ts — он подключает шапку с той
 * же разметкой, но своё, отдельное поведение поля, а не этот модуль).
 */
export function mountHeaderSearch(input: HTMLInputElement, submit: HTMLButtonElement): void {
  const goToCatalog = (): void => {
    const q = input.value.trim()
    const query = q === '' ? '' : `?q=${encodeURIComponent(q)}`
    window.location.href = `./catalog.html${query}`
  }

  submit.addEventListener('click', goToCatalog)

  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      // Поле не внутри <form>, поэтому Enter по умолчанию ничего не
      // отправляет — preventDefault на будущее, если это когда-нибудь изменится.
      event.preventDefault()
      goToCatalog()
    }
  })
}

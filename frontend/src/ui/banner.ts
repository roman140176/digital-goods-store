import { slides } from '../data/slides'
import { iconArrowLeft, iconArrowRight } from '../icons'

const AUTOPLAY_MS = 5000

/**
 * Интерактив №1: карусель баннера.
 * Автопрокрутка, стрелки и активная точка-индикатор. Автопрокрутка встаёт
 * на паузу при наведении и при уходе со вкладки, а также уважает
 * системную настройку «уменьшить движение».
 */
export function mountBanner(root: HTMLElement): void {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches

  root.innerHTML = `
    <div class="banner__viewport">
      ${slides
        .map(
          (slide, index) => `
        <div class="banner__slide${index === 0 ? ' is-active' : ''}" data-slide="${index}" aria-hidden="${index === 0 ? 'false' : 'true'}">
          <picture>
            <source srcset="${slide.image} 1x, ${slide.image2x} 2x" type="image/webp">
            <img class="banner__image" src="${slide.image}" alt="" loading="${index === 0 ? 'eager' : 'lazy'}" decoding="async">
          </picture>
          <div class="banner__overlay">
            <span class="banner__kicker">${slide.kicker}</span>
            <p class="banner__title">${slide.title}</p>
            <button class="banner__cta" type="button">${slide.cta}</button>
          </div>
        </div>`,
        )
        .join('')}
    </div>
    <div class="banner__notch"></div>
    <div class="banner__nav">
      <button class="banner__arrow" type="button" data-direction="-1" aria-label="Предыдущий слайд">${iconArrowLeft()}</button>
      <button class="banner__arrow" type="button" data-direction="1" aria-label="Следующий слайд">${iconArrowRight()}</button>
    </div>
    <div class="banner__dots" role="tablist" aria-label="Слайды баннера">
      ${slides
        .map(
          (_, index) =>
            `<button class="banner__dot${index === 0 ? ' is-active' : ''}" type="button" role="tab" data-dot="${index}" aria-label="Слайд ${index + 1}" aria-selected="${index === 0}"></button>`,
        )
        .join('')}
    </div>`

  const slideNodes = Array.from(root.querySelectorAll<HTMLElement>('[data-slide]'))
  const dotNodes = Array.from(root.querySelectorAll<HTMLElement>('[data-dot]'))

  let current = 0
  let timer: number | undefined

  const show = (next: number): void => {
    current = (next + slideNodes.length) % slideNodes.length

    slideNodes.forEach((node, index) => {
      node.classList.toggle('is-active', index === current)
      node.setAttribute('aria-hidden', index === current ? 'false' : 'true')
    })

    dotNodes.forEach((node, index) => {
      node.classList.toggle('is-active', index === current)
      node.setAttribute('aria-selected', String(index === current))
    })
  }

  const stop = (): void => {
    if (timer !== undefined) {
      window.clearInterval(timer)
      timer = undefined
    }
  }

  const start = (): void => {
    if (reduceMotion || timer !== undefined) {
      return
    }

    timer = window.setInterval(() => show(current + 1), AUTOPLAY_MS)
  }

  const restart = (): void => {
    stop()
    start()
  }

  root.querySelectorAll<HTMLElement>('[data-direction]').forEach((button) => {
    button.addEventListener('click', () => {
      show(current + Number(button.dataset.direction))
      restart()
    })
  })

  dotNodes.forEach((dot) => {
    dot.addEventListener('click', () => {
      show(Number(dot.dataset.dot))
      restart()
    })
  })

  root.addEventListener('mouseenter', stop)
  root.addEventListener('mouseleave', start)
  document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()))

  start()
}

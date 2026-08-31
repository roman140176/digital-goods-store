import { services } from '../data/services'
import { icons } from '../icons'

/** Ряд иконок сервисов. Выделение при наведении — интерактив №4, он в CSS. */
export function renderServices(root: HTMLElement): void {
  const tiles = services
    .map((service) => {
      const style = [`--service-border: ${service.border}`]

      if (service.background !== undefined) {
        style.push(`background: ${service.background}`)
      }

      return `
      <button class="service${service.variant === 'framed' ? ' service--framed' : ''}" type="button" title="${service.label}">
        <span class="service__wrapper">
          <span class="service__tile" style="${style.join('; ')}">
            <picture>
              <source srcset="./assets/icons/${service.slug}.webp 1x, ./assets/icons/${service.slug}@2x.webp 2x" type="image/webp">
              <img src="./assets/icons/${service.slug}.webp" alt="" width="68" height="68" loading="lazy" decoding="async">
            </picture>
          </span>
        </span>
        <span class="service__label">${service.label}</span>
      </button>`
    })
    .join('')

  root.innerHTML = `${tiles}
    <button class="service service--more" type="button" title="Все сервисы">
      <span class="service__wrapper">
        <span class="service__tile">
          <img src="${icons.more}" alt="" width="28" height="28">
        </span>
      </span>
      <span class="service__label">еще 841</span>
    </button>`
}

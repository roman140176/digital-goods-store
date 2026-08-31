import { services } from '../data/services'
import { iconMore } from '../icons'

/** Ряд иконок сервисов. Выделение при наведении — интерактив №4, он в CSS. */
export function renderServices(root: HTMLElement): void {
  root.innerHTML = `
    ${services
      .map(
        (service) => `
      <button class="service" type="button" title="${service.label}">
        <span class="service__tile">
          <picture>
            <source srcset="./assets/icons/${service.slug}.webp 1x, ./assets/icons/${service.slug}@2x.webp 2x" type="image/webp">
            <img src="./assets/icons/${service.slug}.webp" alt="" width="76" height="76" loading="lazy" decoding="async">
          </picture>
        </span>
        <span class="service__label">${service.label}</span>
      </button>`,
      )
      .join('')}
    <button class="service" type="button" title="Все сервисы">
      <span class="service__tile"><span class="service__more">${iconMore()}</span></span>
      <span class="service__label">ещё 841</span>
    </button>`
}

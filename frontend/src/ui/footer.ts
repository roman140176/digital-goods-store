import { footerLegal, footerNav } from '../data/footer'
import { iconImg, icons } from '../icons'

export function renderFooter(root: HTMLElement): void {
  root.innerHTML = `
    <div class="footer__row footer__row--top">
      <nav class="footer__nav">
        ${footerNav.map((link) => `<a href="#">${link}</a>`).join('')}
      </nav>
    </div>

    <div class="footer__row footer__row--middle">
      <a class="footer__social" href="#" aria-label="ВКонтакте">${iconImg(icons.social1, 34)}</a>
      <a class="footer__social" href="#" aria-label="Telegram">${iconImg(icons.social2, 34)}</a>
      <a class="footer__social" href="#" aria-label="TikTok">${iconImg(icons.social3, 34)}</a>
      <a class="footer__social footer__social--boxed" href="#" aria-label="YouTube">${iconImg(icons.social4, 18)}</a>

      <div class="footer__payments">
        <span class="footer__badge footer__badge--visa">VISA</span>
        <span class="footer__badge footer__badge--mir">мир</span>
        <span class="footer__badge footer__badge--mastercard">
          <span class="footer__mc-circle footer__mc-circle--red"></span>
          <span class="footer__mc-circle footer__mc-circle--yellow"></span>
        </span>
      </div>
    </div>

    <div class="footer__row footer__row--bottom">
      <nav class="footer__legal">
        ${footerLegal.map((link) => `<a href="#">${link}</a>`).join('')}
      </nav>
    </div>`
}

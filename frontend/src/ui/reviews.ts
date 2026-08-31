import { reviews } from '../data/reviews'

/** Блок последних отзывов. Содержимое статичное — по ТЗ интерактив тут не нужен. */
export function renderReviews(root: HTMLElement): void {
  root.innerHTML = reviews
    .map(
      (review) => `
    <article class="review">
      <div class="review__head">
        <div class="review__author">
          <span class="review__avatar">
            <picture>
              <source srcset="./assets/review-avatar.webp 1x, ./assets/review-avatar@2x.webp 2x" type="image/webp">
              <img src="./assets/review-avatar.webp" alt="" width="48" height="48" loading="lazy" decoding="async">
            </picture>
          </span>
          <div class="review__meta">
            <p class="review__name">${review.author}</p>
            <div class="review__rating">
              <span class="review__stars">★★★★★</span>
              <span class="review__score">${review.score}</span>
            </div>
          </div>
        </div>
        <span class="review__date">${review.date}</span>
      </div>

      <p class="review__quote">${review.text.join('<br>')}</p>

      <div class="review__item">
        <span class="review__item-image">
          <picture>
            <source srcset="./assets/review-item.webp 1x, ./assets/review-item@2x.webp 2x" type="image/webp">
            <img src="./assets/review-item.webp" alt="" width="64" height="52" loading="lazy" decoding="async">
          </picture>
        </span>
        <p class="review__item-title">${review.itemTitle.join('<br>')}</p>
        <span class="review__item-price">${review.itemPrice}</span>
      </div>
    </article>`,
    )
    .join('')
}

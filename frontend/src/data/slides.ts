/**
 * Слайды баннера.
 *
 * В макете баннер пустой (чёрный прямоугольник), готового арта нет ни в
 * исходнике, ни на скриншотах. Пока используются кропы имеющегося
 * изображения: чтобы подставить тематические баннеры, достаточно заменить
 * пути ниже — вёрстку трогать не нужно.
 */
export interface Slide {
  readonly image: string
  readonly image2x: string
  readonly kicker: string
  readonly title: string
  readonly cta: string
}

const slide = (index: number, kicker: string, title: string, cta: string): Slide => ({
  image: `./assets/banner-${index}.webp`,
  image2x: `./assets/banner-${index}@2x.webp`,
  kicker,
  title,
  cta,
})

export const slides: readonly Slide[] = [
  slide(1, 'Скидки недели', 'Ключи и подписки со скидкой до 90%', 'Смотреть подборку'),
  slide(2, 'Пополнение Steam', 'Пополняем баланс за минуту, бонус 5%', 'Пополнить'),
  slide(3, 'Подписки', 'Discord Nitro, YouTube Premium, Spotify', 'Выбрать подписку'),
  slide(4, 'Игровая валюта', 'Robux, UC и алмазы без ожидания', 'Купить валюту'),
  slide(5, 'Подарочные карты', 'PlayStation, Xbox и Roblox номиналом на выбор', 'Открыть карты'),
  slide(6, 'Новые аккаунты', 'Готовые аккаунты с гарантией и поддержкой', 'Посмотреть'),
]

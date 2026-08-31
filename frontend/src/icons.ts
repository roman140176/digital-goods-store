/**
 * Мелкая монохромная графика инлайном: в макете это векторы 14–22px,
 * тянуть их отдельными файлами дороже, чем описать здесь.
 */

const svg = (size: number, body: string, extra = ''): string =>
  `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" fill="none" aria-hidden="true" ${extra}>${body}</svg>`

export const iconGrid = (): string =>
  svg(
    20,
    [
      [2.5, 2.5],
      [11.5, 2.5],
      [2.5, 11.5],
      [11.5, 11.5],
    ]
      .map(([x, y]) => `<rect x="${x}" y="${y}" width="6" height="6" rx="1.6" fill="currentColor"/>`)
      .join(''),
  )

export const iconSearch = (): string =>
  svg(
    20,
    '<circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.8"/>' +
      '<path d="M13.5 13.5 17.5 17.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
  )

export const iconHeart = (): string =>
  svg(
    16,
    '<path d="M8 13.6 3.2 9.1a3.1 3.1 0 0 1 4.4-4.4L8 5.1l.4-.4a3.1 3.1 0 0 1 4.4 4.4L8 13.6Z" fill="currentColor"/>',
  )

export const iconProfile = (): string =>
  svg(
    20,
    '<circle cx="10" cy="7" r="3.2" stroke="currentColor" stroke-width="1.7"/>' +
      '<path d="M4.2 16.4c.6-2.7 3-4.4 5.8-4.4s5.2 1.7 5.8 4.4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>',
  )

export const iconArrowLeft = (): string =>
  svg(
    22,
    '<path d="M13 6 8 11l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
  )

export const iconArrowRight = (): string =>
  svg(
    22,
    '<path d="M9 6l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
  )

export const iconChevronRight = (): string =>
  svg(
    16,
    '<path d="M6.5 4l4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
  )

export const iconChevronDown = (): string =>
  svg(
    12,
    '<path d="M3 5l3 3 3-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
  )

export const iconRuble = (): string =>
  svg(
    20,
    '<circle cx="10" cy="10" r="8.2" stroke="currentColor" stroke-width="1.5"/>' +
      '<path d="M8 14V6h2.6a2.4 2.4 0 0 1 0 4.8H7m0 1.6h4.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>',
  )

export const iconMore = (): string =>
  svg(
    28,
    '<circle cx="14" cy="14" r="9" stroke="currentColor" stroke-width="1.6"/>' +
      '<path d="M9.5 14h9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
  )

const tabGlyphs: Record<string, string> = {
  donate: '<path d="M7 2.2 8.6 5.4l3.4.5-2.5 2.4.6 3.5L7 10.2 3.9 11.8l.6-3.5L2 5.9l3.4-.5L7 2.2Z" fill="currentColor"/>',
  subscribes:
    '<rect x="1.6" y="3.4" width="10.8" height="7.6" rx="1.6" stroke="currentColor" stroke-width="1.2"/><path d="M4 6.4h6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>',
  items:
    '<path d="M7 1.6l5 2.7v5.4L7 12.4 2 9.7V4.3L7 1.6Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>',
  accounts:
    '<circle cx="7" cy="5.4" r="2.2" stroke="currentColor" stroke-width="1.2"/><path d="M3 11.6c.5-1.8 2.1-2.8 4-2.8s3.5 1 4 2.8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>',
  keys:
    '<circle cx="4.8" cy="7" r="2.6" stroke="currentColor" stroke-width="1.2"/><path d="M7.4 7h5.2m-2 0v2.2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>',
  currency:
    '<circle cx="7" cy="7" r="5.2" stroke="currentColor" stroke-width="1.2"/><path d="M7 4.2v5.6M5.4 5.8h3.2M5.4 8.2h3.2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>',
  other:
    '<circle cx="3.2" cy="7" r="1.2" fill="currentColor"/><circle cx="7" cy="7" r="1.2" fill="currentColor"/><circle cx="10.8" cy="7" r="1.2" fill="currentColor"/>',
}

export const iconTab = (name: string): string => svg(14, tabGlyphs[name] ?? tabGlyphs.other!)

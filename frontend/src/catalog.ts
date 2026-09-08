import './styles/app.scss'

import { ApiError, createOrder, fetchCatalog, type CatalogParams, type OrderTarget } from './api/client'
import type { CatalogItem, CatalogResponse, Offer, Order, ProductType, StreamEnvelope } from './api/types'
import { escapeHtml, money } from './format'
import { connectRealtime, shouldApply } from './realtime'
import { hydrateIcons } from './ui/hydrateIcons'
import { notify } from './ui/notice'
import { applyOfferGone, applyOfferState } from './ui/productCards'

/**
 * Страница каталога с мгновенным поиском (задача 12, бонус 5 ТЗ).
 *
 * Список карточек НЕ использует renderProductCards из productCards.ts:
 * та функция перерисовывает root.innerHTML целиком при каждом вызове, а
 * это ровно то полное перерисовывание, которое запрещено 5.1 ТЗ (мигает,
 * сбрасывает фокус в поле поиска). Здесь своя функция синхронизации сетки
 * по ключу offer_id (см. syncGrid ниже) — существующие узлы переиспользуются,
 * новые вставляются, лишние удаляются. Карточки при этом используют те же
 * классы, что и cards.scss, а точечные живые обновления идут через
 * applyOfferState/applyOfferGone — те же самые функции, что и на витрине
 * (main.ts): они ищут узел по data-offer-id глобально через
 * document.querySelector и одинаково находят карточку независимо от того,
 * какой код её создал.
 */

const SORTS = ['price_asc', 'price_desc', 'name'] as const
type Sort = (typeof SORTS)[number]

function isSort(value: string | null): value is Sort {
  return value !== null && (SORTS as readonly string[]).includes(value)
}

// Совпадает с CatalogFilters::MIN_QUERY_LENGTH на бэкенде: короче триграммный
// индекс не используется (см. задачу 8), сервер и так трактует такой запрос
// как «показать всё». Отправлять q короче двух символов незачем — результат
// будет тем же самым, что и без q вовсе (см. readParamsFromForm).
const MIN_QUERY_LENGTH = 2

const DEBOUNCE_MS = 120

const typeLabels: Record<ProductType, string> = {
  key: 'Ключ активации',
  topup: 'Пополнение',
  subscription: 'Подписка',
  giftcard: 'Подарочная карта',
}

/** Текст и disabled кнопки «Купить» — та же формулировка, что и на витрине (productCards.ts), но своя копия: там функция приватная. */
const buyButtonState = (available: boolean): { readonly disabled: boolean; readonly label: string } =>
  available ? { disabled: false, label: 'Купить' } : { disabled: true, label: 'Нет в наличии' }

/**
 * Русское склонение по числу: 1 товар, 2 товара, 5 товаров, 11 товаров,
 * 21 товар... «Найдено N товаров» было бы грамматически неверно для N=1
 * или N=21, а поиск по каталогу к таким числам как раз и приводит.
 */
function pluralize(count: number, one: string, few: string, many: string): string {
  const mod10 = count % 10
  const mod100 = count % 100

  if (mod10 === 1 && mod100 !== 11) {
    return one
  }

  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
    return few
  }

  return many
}

function requireElement<T extends HTMLElement>(selector: string): T {
  const element = document.querySelector<T>(selector)

  if (element === null) {
    throw new Error(`Не найден элемент ${selector}`)
  }

  return element
}

const searchInput = requireElement<HTMLInputElement>('[data-catalog-search-input]')
const searchSubmit = requireElement<HTMLButtonElement>('[data-catalog-search-submit]')
const typeSelect = requireElement<HTMLSelectElement>('[data-filter="type"]')
const priceMinInput = requireElement<HTMLInputElement>('[data-filter="price_min"]')
const priceMaxInput = requireElement<HTMLInputElement>('[data-filter="price_max"]')
const inStockCheckbox = requireElement<HTMLInputElement>('[data-filter="in_stock"]')
const sortSelect = requireElement<HTMLSelectElement>('[data-filter="sort"]')
const resetButton = requireElement<HTMLButtonElement>('[data-catalog-reset]')

const resultsNode = requireElement<HTMLElement>('[data-catalog-results]')
const gridNode = requireElement<HTMLElement>('[data-catalog-grid]')
const emptyNode = requireElement<HTMLElement>('[data-catalog-empty]')
const summaryNode = requireElement<HTMLElement>('[data-catalog-summary]')

const paginationNode = requireElement<HTMLElement>('[data-catalog-pagination]')
const pageStatusNode = requireElement<HTMLElement>('[data-page-status]')
const prevPageButton = requireElement<HTMLButtonElement>('[data-page-prev]')
const nextPageButton = requireElement<HTMLButtonElement>('[data-page-next]')

/** Исходный текст пустой выдачи (из HTML) — восстанавливается после того, как его перекрыло сообщение о сбое загрузки (см. showFatalEmptyState). */
const defaultEmptyText = emptyNode.textContent ?? ''

let currentPage = 1
let totalPages = 1

/**
 * Против гонки ответов нужны ОБА средства (5.2 ТЗ): AbortController отменяет
 * предыдущий запрос, но не спасает от того, что ответ №3 придёт позже
 * ответа №4 — запрос уже ушёл и не был прерван до того, как отработал.
 * requestSeq — монотонный счётчик: применяется только ответ, чей seq
 * совпадает с последним выданным (значит, ничего новее не запускалось,
 * пока этот ответ летел).
 */
let requestSeq = 0
let inFlight: AbortController | null = null

/**
 * Ключ последнего РЕАЛЬНО отправленного запроса. Если следующий вызов
 * runSearch() (например, из-за нормализации q короче двух символов в
 * «пусто», см. readParamsFromForm) вычисляет тот же самый ключ — значит,
 * эффективные параметры не изменились, и слать «поиск ни по чему» второй
 * раз незачем (5.1 брифа задачи 12). onResync ниже сбрасывает этот ключ
 * явно: ресинку обязан обновить данные, даже если фильтры не менялись.
 */
let lastRequestKey: string | undefined

let hasLoadedOnce = false
let realtimeStarted = false
let debounceTimer: number | undefined

function setLoading(loading: boolean): void {
  resultsNode.classList.toggle('is-loading', loading)
}

function showFatalEmptyState(message: string): void {
  gridNode.hidden = true
  emptyNode.hidden = false
  emptyNode.textContent = message
  summaryNode.textContent = ''
  paginationNode.hidden = true
}

/** rubles → минорные единицы; пустая или некорректная строка — «фильтр не задан» (тот же принцип терпимости, что и у CatalogFilters на бэкенде). */
function toMinorUnits(rawInput: string): number | undefined {
  const trimmed = rawInput.trim()

  if (trimmed === '') {
    return undefined
  }

  const rubles = Number(trimmed)

  return Number.isFinite(rubles) && rubles >= 0 ? Math.round(rubles * 100) : undefined
}

/** минорные единицы (из URL) → строка в рублях для поля ввода; нечисловое или отсутствующее значение — пустое поле. */
function minorToRubleInput(raw: string | null): string {
  if (raw === null || !/^\d+$/.test(raw)) {
    return ''
  }

  return String(Math.round(Number(raw) / 100))
}

/**
 * Параметры запроса читаются из формы, а не из отдельного состояния: DOM
 * здесь и есть единственный источник правды для того, что сейчас введено.
 * exactOptionalPropertyTypes запрещает присваивать полю ключ со значением
 * undefined — поэтому поля добавляются условным spread'ом, а не через
 * `q: condition ? q : undefined`.
 */
function readParamsFromForm(): CatalogParams {
  const q = searchInput.value.trim()
  const type = typeSelect.value
  const priceMin = toMinorUnits(priceMinInput.value)
  const priceMax = toMinorUnits(priceMaxInput.value)
  const sort = sortSelect.value

  return {
    ...(q.length >= MIN_QUERY_LENGTH ? { q } : {}),
    ...(type !== '' ? { type } : {}),
    ...(priceMin !== undefined ? { price_min: priceMin } : {}),
    ...(priceMax !== undefined ? { price_max: priceMax } : {}),
    ...(inStockCheckbox.checked ? { in_stock: true } : {}),
    // Сервер и так по умолчанию сортирует по price_asc — не дублируем дефолт
    // ни в запросе, ни в адресе (то же правило, что и у buildQuery в client.ts).
    ...(sort !== 'price_asc' && isSort(sort) ? { sort } : {}),
  }
}

/**
 * Восстанавливает форму фильтров из query-строки адреса — при загрузке
 * страницы и на popstate (5.3 ТЗ). Возвращает номер страницы: он не хранится
 * в самой форме, а является отдельной частью состояния (currentPage).
 */
function applyUrlToForm(search: string): number {
  const url = new URLSearchParams(search)
  const sortValue = url.get('sort')
  const page = Number(url.get('page'))

  searchInput.value = url.get('q') ?? ''
  typeSelect.value = url.get('type') ?? ''
  priceMinInput.value = minorToRubleInput(url.get('price_min'))
  priceMaxInput.value = minorToRubleInput(url.get('price_max'))
  inStockCheckbox.checked = url.get('in_stock') === '1'
  sortSelect.value = isSort(sortValue) ? sortValue : 'price_asc'

  return Number.isFinite(page) && page > 1 ? Math.floor(page) : 1
}

/**
 * Пишет текущее состояние в адрес через replaceState (не pushState: иначе
 * каждый введённый символ добавлял бы запись в историю, и «Назад» листало
 * бы посимвольно вместо возврата на предыдущую страницу — 5.3 ТЗ). Пустые
 * значения опускаются, чтобы ссылка оставалась читаемой.
 */
function writeUrl(params: CatalogParams, page: number): void {
  const query = new URLSearchParams()

  if (params.q !== undefined) {
    query.set('q', params.q)
  }
  if (params.type !== undefined) {
    query.set('type', params.type)
  }
  if (params.price_min !== undefined) {
    query.set('price_min', String(params.price_min))
  }
  if (params.price_max !== undefined) {
    query.set('price_max', String(params.price_max))
  }
  if (params.in_stock !== undefined) {
    query.set('in_stock', '1')
  }
  if (params.sort !== undefined) {
    query.set('sort', params.sort)
  }
  if (page > 1) {
    query.set('page', String(page))
  }

  const qs = query.toString()
  const url = qs === '' ? window.location.pathname : `${window.location.pathname}?${qs}`
  window.history.replaceState(null, '', url)
}

function updatePaginationUi(): void {
  const hasMultiplePages = totalPages > 1
  paginationNode.hidden = !hasMultiplePages

  if (!hasMultiplePages) {
    return
  }

  pageStatusNode.textContent = `Страница ${currentPage} из ${totalPages}`
  prevPageButton.disabled = currentPage <= 1
  nextPageButton.disabled = currentPage >= totalPages
}

/** applyOfferState ждёт полный Offer, а лучшее предложение каталога — только его проекцию (CatalogBestOffer, см. типы); недостающие поля берутся из самой позиции. */
function offerFromCatalogItem(item: CatalogItem): Offer {
  return {
    offer_id: item.best.offer_id,
    sku: item.sku,
    name: item.name,
    price_minor: item.best.price_minor,
    currency: item.best.currency,
    available: item.best.available,
    // best всегда активное предложение — так его выбирает CatalogQuery на
    // бэкенде (bestOfferLateral фильтрует status = 'active'), иначе оно не
    // попало бы в выдачу как лучшее.
    status: 'active',
    seller: item.best.seller,
  }
}

/**
 * Карточка каталога использует те же классы, что и cards.scss (.card,
 * .card__media, .card__body...), но без декоративных эмодзи в заголовке и
 * фиктивной зачёркнутой цены витрины (см. titleDecor/decorativeOldPrice в
 * productCards.ts) — это оформление конкретных кураторских рядов на
 * главной, а не подходящий вид для тысяч настоящих результатов поиска.
 */
function buildCard(item: CatalogItem): HTMLElement {
  const available = item.best.available > 0
  const state = buyButtonState(available)

  const card = document.createElement('article')
  card.className = available ? 'card' : 'card card--sold-out'
  card.dataset.offerId = String(item.best.offer_id)
  card.dataset.sku = item.sku

  card.innerHTML = `
    <div class="card__media">
      <picture>
        <source srcset="./assets/card.webp 1x, ./assets/card@2x.webp 2x" type="image/webp">
        <img src="./assets/card@2x.jpg" alt="" width="227" height="152" loading="lazy" decoding="async">
      </picture>
    </div>
    <div class="card__body">
      <p class="card__meta"><span class="card__name">${escapeHtml(item.name)}</span>${typeLabels[item.type]}</p>
      <div class="card__prices">
        <span class="card__price" data-price>${money(item.best.price_minor, item.best.currency)}</span>
      </div>
      <span class="visually-hidden" data-available>${item.best.available}</span>
      <button class="card__buy" type="button"${state.disabled ? ' disabled' : ''}>${state.label}</button>
    </div>`

  const buyButton = card.querySelector<HTMLButtonElement>('.card__buy')

  if (buyButton !== null) {
    buyButton.addEventListener('click', () => buyOffer(item, buyButton))
  }

  return card
}

/**
 * Синхронизация сетки по ключу offer_id (5.1 ТЗ): существующие узлы
 * переиспользуются (appendChild на уже существующем узле двигает его на
 * нужное место, а не создаёт новый — браузер просто переставляет элемент),
 * новые карточки вставляются, лишние удаляются. root.innerHTML не
 * применяется никогда — это и есть запрещённая полная перерисовка.
 */
function syncGrid(items: readonly CatalogItem[]): void {
  const existing = new Map<number, HTMLElement>()

  gridNode.querySelectorAll<HTMLElement>('[data-offer-id]').forEach((node) => {
    const id = Number(node.dataset.offerId)

    if (Number.isFinite(id)) {
      existing.set(id, node)
    }
  })

  for (const item of items) {
    const id = item.best.offer_id
    const node = existing.get(id)

    if (node !== undefined) {
      existing.delete(id)
      // Тот же путь, что и у живых событий (applyOfferState), — одна
      // функция форматирует цену/остаток/доступность кнопки одинаково и там, и тут.
      applyOfferState(offerFromCatalogItem(item))
      gridNode.appendChild(node)
    } else {
      gridNode.appendChild(buildCard(item))
    }
  }

  // Всё, что осталось несопоставленным, — предложения, которых больше нет
  // в текущей выдаче (сменилась страница или фильтр).
  existing.forEach((node) => node.remove())
}

function applyResponse(response: CatalogResponse, params: CatalogParams): void {
  hasLoadedOnce = true
  totalPages = Math.max(1, Math.ceil(response.total / response.per_page))

  syncGrid(response.items)

  const isEmpty = response.items.length === 0
  gridNode.hidden = isEmpty
  emptyNode.hidden = !isEmpty
  emptyNode.textContent = defaultEmptyText

  summaryNode.textContent = `Найдено ${response.total} ${pluralize(response.total, 'товар', 'товара', 'товаров')}`

  updatePaginationUi()
  writeUrl(params, currentPage)
  setLoading(false)

  // Поднимается один раз, от курсора самого первого успешного ответа —
  // тот же приём, что и в main.ts (subscribeToCatalog) и order.ts
  // (realtimeStarted): последующие обновления сетки идут через уже открытый
  // поток, переподключаться на каждый повторный поиск незачем.
  if (!realtimeStarted) {
    realtimeStarted = true
    startRealtime(response.stream_cursor)
  }
}

/**
 * Запускает поиск с текущими параметрами формы, если они отличаются от
 * последнего реально отправленного запроса (см. lastRequestKey). Ответы
 * гонки гасятся и AbortController'ом (отмена устаревшего запроса), и
 * монотонным requestSeq (страховка на случай, если отменённый запрос всё же
 * успеет вернуться раньше нового, см. докблок requestSeq выше).
 */
async function runSearch(): Promise<void> {
  const params = readParamsFromForm()
  const key = JSON.stringify({ ...params, page: currentPage })

  if (key === lastRequestKey) {
    return
  }

  const seq = ++requestSeq
  inFlight?.abort()
  inFlight = new AbortController()
  lastRequestKey = key
  setLoading(true)

  const requestParams: CatalogParams = {
    ...params,
    ...(currentPage > 1 ? { page: currentPage } : {}),
  }

  try {
    const response = await fetchCatalog(requestParams, inFlight.signal)

    if (seq !== requestSeq) {
      return // ответ устарел, свежий уже применён
    }

    applyResponse(response, params)
  } catch (error) {
    if (seq !== requestSeq) {
      return // тоже устарел — не поднимаем notify по гонке, которую уже выиграл более свежий запрос
    }

    if (error instanceof DOMException && error.name === 'AbortError') {
      return // отменили сами (см. inFlight?.abort() выше) — новый поиск уже пошёл, здесь нечего делать
    }

    setLoading(false)

    if (!hasLoadedOnce) {
      showFatalEmptyState('Каталог не загрузился: бэкенд недоступен. Запустите стек командой make up.')
    }

    notify(error instanceof ApiError ? error.message : 'Не удалось загрузить каталог: бэкенд недоступен.')
  }
}

function cancelPendingDebounce(): void {
  if (debounceTimer !== undefined) {
    window.clearTimeout(debounceTimer)
    debounceTimer = undefined
  }
}

/** Ввод с задержкой 120 мс (5.1 ТЗ) — сбрасывает страницу на первую: результат нового запроса не имеет отношения к прежней странице. */
function scheduleSearch(): void {
  currentPage = 1
  cancelPendingDebounce()
  debounceTimer = window.setTimeout(() => {
    debounceTimer = undefined
    void runSearch()
  }, DEBOUNCE_MS)
}

/** Enter, клик «Найти» и «Сбросить» — не ждут дебаунс, ищут сразу. */
function searchNow(): void {
  cancelPendingDebounce()
  currentPage = 1
  void runSearch()
}

function goToPage(page: number): void {
  cancelPendingDebounce()
  currentPage = page
  void runSearch()
}

/**
 * Ключи идемпотентности на покупку — своя карта для этой страницы: тот же
 * приём, что и в main.ts (см. его докблок про idempotencyKeys), но копия, а
 * не импорт — main.ts ничего не экспортирует (это отдельная точка входа, а
 * не библиотека), и обе страницы никогда не открыты в одной вкладке
 * одновременно, так что раздельное состояние ничего не теряет.
 */
const idempotencyKeys = new Map<string, string>()

const idempotencyKeyFor = (key: string): string => {
  const existing = idempotencyKeys.get(key)

  if (existing !== undefined) {
    return existing
  }

  const value =
    typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `idem_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 12)}`
  idempotencyKeys.set(key, value)

  return value
}

async function attemptPurchase(target: OrderTarget, key: string): Promise<Order> {
  const order = await createOrder(target, idempotencyKeyFor(key))
  idempotencyKeys.delete(key)

  return order
}

/** Покупка карточки каталога — offer_id, а не sku: карточка уже знает своё лучшее предложение (см. main.ts, buyOffer — тот же приём). */
function buyOffer(item: CatalogItem, button: HTMLButtonElement): void {
  const label = button.textContent
  button.disabled = true
  button.textContent = 'Оформляем...'

  void attemptPurchase({ offerId: item.best.offer_id }, `offer:${item.best.offer_id}`)
    .then((order) => {
      window.location.href = `./order.html?id=${encodeURIComponent(order.id)}`
    })
    .catch((error: unknown) => {
      if (error instanceof ApiError && error.reason === 'sold_out') {
        applyOfferGone(item.best.offer_id)
      } else {
        button.disabled = false
        button.textContent = label ?? 'Купить'
      }

      reportPurchaseError(error)
    })
}

function buyAlternative(offer: Offer): void {
  void attemptPurchase({ offerId: offer.offer_id }, `offer:${offer.offer_id}`)
    .then((order) => {
      window.location.href = `./order.html?id=${encodeURIComponent(order.id)}`
    })
    .catch((error: unknown) => {
      if (error instanceof ApiError && error.reason === 'sold_out') {
        applyOfferGone(offer.offer_id)
      }

      reportPurchaseError(error)
    })
}

function reportPurchaseError(error: unknown): void {
  if (error instanceof ApiError && error.reason === 'sold_out') {
    const alternative = (error.details?.alternative ?? null) as Offer | null

    if (alternative === null) {
      notify('Товар только что раскупили.')

      return
    }

    notify('Товар только что раскупили.', {
      label: `Купить у ${alternative.seller.name} за ${money(alternative.price_minor, alternative.currency)}`,
      onClick: () => {
        buyAlternative(alternative)
      },
    })

    return
  }

  notify(error instanceof ApiError ? error.message : 'Не удалось создать заказ. Проверьте, что бэкенд запущен.')
}

/** offer.updated/offer.gone из топика catalog — тот же обработчик, что и на витрине (main.ts, applyCatalogEvent): гард shouldApply, точечное применение к видимой карточке. */
function applyCatalogEvent(envelope: StreamEnvelope): void {
  const offerId = Number(envelope.payload.offer_id)

  if (!Number.isFinite(offerId) || !shouldApply(offerId, envelope.id)) {
    return
  }

  if (envelope.type === 'offer.updated') {
    applyOfferState(envelope.payload as unknown as Offer)
  } else if (envelope.type === 'offer.gone') {
    applyOfferGone(offerId)
  }
}

function startRealtime(cursor: number): void {
  connectRealtime({
    topics: ['catalog'],
    cursor,
    onEvent: applyCatalogEvent,
    onResync: () => {
      // Ресинк обязан обновить данные, даже если фильтры не менялись —
      // дедуп по lastRequestKey здесь должен промолчать.
      lastRequestKey = undefined
      void runSearch()
    },
  })
}

function init(): void {
  hydrateIcons(document)

  currentPage = applyUrlToForm(window.location.search)

  searchInput.addEventListener('input', scheduleSearch)
  priceMinInput.addEventListener('input', scheduleSearch)
  priceMaxInput.addEventListener('input', scheduleSearch)
  typeSelect.addEventListener('change', scheduleSearch)
  sortSelect.addEventListener('change', scheduleSearch)
  inStockCheckbox.addEventListener('change', scheduleSearch)

  searchSubmit.addEventListener('click', searchNow)
  searchInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      // Поле не внутри <form>, Enter по умолчанию ничего не отправляет —
      // preventDefault на случай, если это когда-нибудь изменится.
      event.preventDefault()
      searchNow()
    }
  })

  resetButton.addEventListener('click', () => {
    searchInput.value = ''
    typeSelect.value = ''
    priceMinInput.value = ''
    priceMaxInput.value = ''
    inStockCheckbox.checked = false
    sortSelect.value = 'price_asc'
    searchNow()
  })

  prevPageButton.addEventListener('click', () => {
    if (currentPage > 1) {
      goToPage(currentPage - 1)
    }
  })

  nextPageButton.addEventListener('click', () => {
    if (currentPage < totalPages) {
      goToPage(currentPage + 1)
    }
  })

  // «Назад»/«Вперёд»: состояние пишется replaceState (см. writeUrl), поэтому
  // обычная навигация внутри этой же страницы новых записей в историю не
  // создаёт — но чтение на popstate всё равно нужно (5.3 ТЗ), на случай
  // перехода между записями, где эта страница встречается с разными query.
  window.addEventListener('popstate', () => {
    cancelPendingDebounce()
    currentPage = applyUrlToForm(window.location.search)
    lastRequestKey = undefined // адрес мог поменяться на состояние, уже совпадающее по «ключу» с прошлым запросом (например, после Вперёд-Назад) — обязаны перечитать
    void runSearch()
  })

  void runSearch()
}

init()

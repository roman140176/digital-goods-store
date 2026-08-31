/** Отзывы из макета. По ТЗ наполнение не оценивается, важна вёрстка. */
export interface Review {
  readonly author: string
  readonly score: string
  readonly date: string
  readonly text: readonly string[]
  readonly itemTitle: readonly string[]
  readonly itemPrice: string
}

const sample: Review = {
  author: 'Bizidin',
  score: '5.0',
  date: 'Сегодня в 11:48',
  text: ['Отзывчивый и приятный продавец,', 'помог не только с товаром но и с другим', 'вопросом. Рекомендую!'],
  itemTitle: ['🌸 FunTime | Полностью', 'готовый сервер под ключ ⚡'],
  itemPrice: '139₽',
}

export const reviews: readonly Review[] = [sample, sample, sample]

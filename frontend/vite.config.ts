import { defineConfig } from 'vite'

/**
 * Две страницы: витрина и статус заказа.
 *
 * base: './' — сборка должна открываться и с корня nginx, и из подкаталога
 * статического хостинга, если фронт задеплоен отдельно от бэкенда.
 * Пути к страницам считаются от import.meta.url, чтобы не тянуть @types/node.
 */
const page = (name: string): string => new URL(`./${name}.html`, import.meta.url).pathname

export default defineConfig({
  base: './',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        index: page('index'),
        order: page('order'),
      },
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://localhost:8085',
    },
  },
})

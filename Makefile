# Всё поднимается одной командой: make up
# Контейнеры запускаются под текущим пользователем, чтобы storage/ был доступен для записи.

APP_UID := $(shell id -u)
APP_GID := $(shell id -g)
COMPOSE := APP_UID=$(APP_UID) APP_GID=$(APP_GID) docker compose
EXEC    := $(COMPOSE) exec -T app
RACE    := $(EXEC) php /scripts

.DEFAULT_GOAL := help

.PHONY: help
help:
	@echo "make up        — собрать витрину, поднять стек, накатить миграции и сиды"
	@echo "make down      — остановить стек"
	@echo "make destroy   — остановить и снести данные"
	@echo "make fresh     — пересоздать схему, засеять заново, обнулить склады"
	@echo "make front     — собрать витрину (node в контейнере, на хосте ничего не нужно)"
	@echo "make seed-catalog — засеять объёмный каталог и докупить склады поставщиков"
	@echo "make race-all  — прогнать все состязательные сценарии"
	@echo "make test      — модульные и функциональные тесты"
	@echo "make logs      — логи воркера и приложения"
	@echo "make sh        — шелл внутри контейнера приложения"

.PHONY: front
front:
	docker run --rm -u $(APP_UID):$(APP_GID) -e HOME=/tmp \
	  -v "$(PWD)/frontend":/app -w /app node:22-alpine \
	  sh -c "if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; \
	         else npm install --no-audit --no-fund; fi && npm run build"

# Локальное окружение из шаблона: в репозитории .env не хранится.
backend/.env:
	cp backend/.env.example backend/.env

.PHONY: up
up: backend/.env front
	$(COMPOSE) up -d --build db app nginx supplier-a supplier-b
	@echo "Ожидание готовности базы..."
	@until $(COMPOSE) exec -T db pg_isready -U app -d store >/dev/null 2>&1; do sleep 1; done
	@grep -q "^APP_KEY=base64:" backend/.env || $(EXEC) php artisan key:generate --force
	$(EXEC) php artisan migrate --force
	$(EXEC) php artisan db:seed --force
	@echo "Схема готова, поднимаю воркер и планировщик..."
	$(COMPOSE) up -d worker scheduler
	@echo ""
	@echo "Витрина:  http://localhost:8085"
	@echo "Админка:  http://localhost:8085/admin/orders?token=admin-secret-token"
	@echo "Склад A:  http://localhost:8086/inventory"
	@echo "Склад B:  http://localhost:8087/inventory"

.PHONY: down
down:
	$(COMPOSE) down

.PHONY: destroy
destroy:
	$(COMPOSE) down -v

.PHONY: fresh
fresh:
	$(EXEC) php artisan migrate:fresh --seed --force
	$(RACE)/reset.php

.PHONY: test
test:
	@$(COMPOSE) exec -T db psql -U app -d postgres -tc "SELECT 1 FROM pg_database WHERE datname='store_test'" \
	  | grep -q 1 || $(COMPOSE) exec -T db psql -U app -d postgres -c "CREATE DATABASE store_test"
	@# База тестов передаётся явно: compose отдаёт backend/.env в контейнер
	@# настоящими переменными окружения, и они сильнее значений из phpunit.xml.
	@# Без этого RefreshDatabase работал бы по базе витрины и стирал её данные.
	$(COMPOSE) exec -T -e APP_ENV=testing -e DB_DATABASE=store_test \
	  -e QUEUE_CONNECTION=sync app php artisan test

.PHONY: logs
logs:
	$(COMPOSE) logs -f --tail=100 app worker scheduler

.PHONY: sh
sh:
	$(COMPOSE) exec app sh

# ---- состязательные сценарии (номера соответствуют критериям приёмки ТЗ) ----

.PHONY: race-all
race-all:
	@echo "Чистая база и исходный пул ключей из ТЗ перед прогоном..."
	$(EXEC) php artisan migrate:fresh --seed --force
	$(RACE)/reset.php
	$(RACE)/run-all.php

.PHONY: race-double-click
race-double-click:
	$(RACE)/race-double-click.php

.PHONY: race-webhook-same-event
race-webhook-same-event:
	$(RACE)/race-webhook-same-event.php

.PHONY: race-webhook-distinct-events
race-webhook-distinct-events:
	$(RACE)/race-webhook-distinct-events.php

.PHONY: race-out-of-order
race-out-of-order:
	$(RACE)/race-out-of-order.php

.PHONY: race-empty-pool
race-empty-pool:
	$(RACE)/race-empty-pool.php

.PHONY: race-promo-limit
race-promo-limit:
	$(RACE)/race-promo-limit.php

.PHONY: race-timeout-leak
race-timeout-leak:
	$(RACE)/race-timeout-leak.php

.PHONY: race-error-after-issue
race-error-after-issue:
	$(RACE)/race-error-after-issue.php

# ---- объёмный каталог (задача 5 ТЗ: мгновенный поиск) ----

.PHONY: seed-catalog
seed-catalog:
	$(EXEC) php artisan db:seed --class=CatalogVolumeSeeder --force
	@echo "Пополняю склады поставщиков под объём каталога..."
	$(EXEC) php artisan stock:sync-suppliers

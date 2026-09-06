# Perintah pengembangan SIM Klinik.
#
# Target utama: `make setup` harus cukup untuk membawa hasil `git clone` sampai
# aplikasi berjalan dengan tiga klinik contoh — tanpa langkah manual di luar ini.

SHELL := /bin/bash
COMPOSE := docker compose
MYSQL := $(COMPOSE) exec -T mysql mysql -uroot -psecret

.PHONY: setup up down restart fresh dev test build lint tenants help

help:
	@echo "make setup    — pasang dependensi, nyalakan infra, migrasi, seed"
	@echo "make up       — nyalakan MySQL + Redis"
	@echo "make down     — matikan infra (data tetap ada di volume)"
	@echo "make dev      — server Laravel + Vite + worker antrian"
	@echo "make test     — jalankan seluruh suite, termasuk isolasi tenant"
	@echo "make fresh    — hapus semua database tenant, migrasi & seed ulang"
	@echo "make tenants  — daftar tenant beserta alamatnya"

setup: up
	@test -f .env || cp .env.example .env
	composer install
	@grep -q '^APP_KEY=base64' .env || php artisan key:generate
	npm install
	npm run build
	@echo "→ Menyiapkan database test"
	@$(MYSQL) -e "CREATE DATABASE IF NOT EXISTS simklinik_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
	php artisan migrate --force
	php artisan db:seed --force
	php artisan tenants:seed
	@$(MAKE) --no-print-directory tenants

up:
	$(COMPOSE) up -d --wait

down:
	$(COMPOSE) down

restart: down up

# Menghapus tenant lewat model, bukan lewat DROP DATABASE langsung, supaya
# pipeline penghapusan yang sama dengan produksi ikut teruji.
fresh: up
	php artisan tinker --execute='App\Models\Tenant::all()->each->delete();'
	php artisan migrate:fresh --force
	php artisan db:seed --force
	php artisan tenants:seed
	@$(MAKE) --no-print-directory tenants

dev:
	npx concurrently -c "#93c5fd,#c4b5fd,#fdba74" \
		"php artisan serve --port=8000" \
		"php artisan queue:listen --tries=1" \
		"npm run dev" \
		--names=server,queue,vite --kill-others

test:
	php artisan test

build:
	npm run build

lint:
	./vendor/bin/pint
	npx tsc --noEmit

tenants:
	@php artisan tinker --execute='foreach (App\Models\Tenant::orderBy("name")->get() as $$t) { printf("  %-16s %-12s http://%s:8000\n", $$t->slug, $$t->status->value, $$t->primaryDomain()); }'

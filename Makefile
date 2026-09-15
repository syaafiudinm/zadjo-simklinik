# Perintah pengembangan SIM Klinik.
#
# Target utama: `make setup` harus cukup untuk membawa hasil `git clone` sampai
# aplikasi berjalan dengan tiga klinik contoh — tanpa langkah manual di luar ini.

SHELL := /bin/bash
COMPOSE := docker compose
MYSQL := $(COMPOSE) exec -T mysql mysql -uroot -p$${TENANCY_ADMIN_DB_PASSWORD:-secret}
APP_DB_USER ?= $(shell grep -E '^DB_USERNAME=' .env 2>/dev/null | cut -d= -f2)
APP_DB_PASSWORD ?= $(shell grep -E '^DB_PASSWORD=' .env 2>/dev/null | cut -d= -f2)

.PHONY: setup up down restart fresh dev test test-isolation build lint tenants db-users help

help:
	@echo "make setup    — pasang dependensi, nyalakan infra, migrasi, seed"
	@echo "make up       — nyalakan MySQL + Redis"
	@echo "make down     — matikan infra (data tetap ada di volume)"
	@echo "make dev      — server Laravel + Vite + Horizon (worker antrian)"
	@echo "make test     — jalankan seluruh suite, termasuk isolasi tenant"
	@echo "make test-isolation — hanya suite isolasi tenant (gerbang CI)"
	@echo "make db-users — buat/perbarui user MySQL pusat berhak terbatas"
	@echo "make fresh    — hapus semua database tenant, migrasi & seed ulang"
	@echo "make tenants  — daftar tenant beserta alamatnya"
	@echo ""
	@echo "Klinik baru:   php artisan tenant:create <slug> \"<nama>\" <email-admin>"
	@echo "Hapus klinik:  php artisan tenant:delete <slug>   (ekspor otomatis dulu)"
	@echo "Email lokal:   http://localhost:8025 (Mailpit)"
	@echo "Antrian:       http://admin.simklinik.localhost:8000/horizon (lihat .env)"

setup: up
	@test -f .env || cp .env.example .env
	composer install
	@grep -q '^APP_KEY=base64' .env || php artisan key:generate
	npm install
	npm run build
	@$(MAKE) --no-print-directory db-users
	php artisan migrate --force
	@# TenantSeeder mem-provision tiga klinik lewat jalur yang sama dengan tenant:create.
	php artisan db:seed --force
	@$(MAKE) --no-print-directory tenants

up:
	$(COMPOSE) up -d --wait

# User MySQL pusat berhak terbatas + database pusat & test. Idempoten.
db-users:
	@test -n "$(APP_DB_USER)" || (echo "DB_USERNAME kosong di .env" && exit 1)
	@echo "→ User MySQL pusat [$(APP_DB_USER)] hanya berhak atas simklinik_central & simklinik_testing"
	@sed -e 's/@@APP_USER@@/$(APP_DB_USER)/g' -e 's/@@APP_PASSWORD@@/$(APP_DB_PASSWORD)/g' docker/mysql/central-user.sql | $(MYSQL)

down:
	$(COMPOSE) down

restart: down up

# Menghapus tenant lewat model, bukan lewat DROP DATABASE langsung, supaya
# pipeline penghapusan yang sama dengan produksi ikut teruji.
fresh: up
	php artisan tinker --execute='App\Models\Tenant::all()->each->delete();'
	php artisan migrate:fresh --force
	php artisan db:seed --force
	@$(MAKE) --no-print-directory tenants

dev:
	npx concurrently -c "#93c5fd,#c4b5fd,#fdba74" \
		"php artisan serve --port=8000" \
		"php artisan horizon:listen" \
		"npm run dev" \
		--names=server,horizon,vite --kill-others

test:
	php artisan test

# Gerbang wajib sebelum merge: bukti isolasi antar klinik.
test-isolation:
	php artisan test --group=isolation

build:
	npm run build

lint:
	./vendor/bin/pint
	npx tsc --noEmit

tenants:
	@php artisan tinker --execute='foreach (App\Models\Tenant::orderBy("name")->get() as $$t) { printf("  %-16s %-12s http://%s:8000\n", $$t->slug, $$t->status->value, $$t->primaryDomain()); }'

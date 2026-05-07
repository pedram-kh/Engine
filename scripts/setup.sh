#!/usr/bin/env bash
#
# scripts/setup.sh — first-time developer setup for Catalyst Engine.
# Idempotent. Re-run after pulling main.
#
# Steps:
#   1. Verify required tooling versions
#   2. Install Node + PHP dependencies
#   3. Boot Postgres + Redis + Mailhog via docker compose
#   4. Bootstrap apps/api/.env, generate APP_KEY, run migrations
#   5. Install Playwright browsers (one-time)

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

bold() { printf '\033[1m%s\033[0m\n' "$*"; }
info() { printf '  → %s\n' "$*"; }
fail() { printf '\033[31m✗ %s\033[0m\n' "$*"; exit 1; }

bold "Catalyst Engine — developer setup"

bold "1/5 Verifying tooling"
command -v node >/dev/null || fail "Node not installed. Use nvm + .nvmrc."
command -v pnpm >/dev/null || fail "pnpm not installed. Run: npm install -g pnpm"
command -v php >/dev/null  || fail "PHP not installed. Install PHP 8.3+ with pdo_pgsql, redis, intl, mbstring, gd."
command -v composer >/dev/null || fail "Composer not installed. https://getcomposer.org/download/"
command -v docker >/dev/null || fail "Docker not installed. https://www.docker.com/products/docker-desktop/"
info "Node $(node --version) / pnpm $(pnpm --version) / PHP $(php -r 'echo PHP_VERSION;') / Composer $(composer --version --no-ansi | awk '{print $3}')"

bold "2/5 Installing dependencies"
info "pnpm install"
pnpm install
info "composer install (apps/api)"
( cd apps/api && composer install --no-interaction )

bold "3/5 Booting docker services"
docker compose up -d postgres redis mailhog
info "Waiting for Postgres to accept connections…"
for _ in {1..30}; do
  if docker compose exec -T postgres pg_isready -U catalyst -d catalyst >/dev/null 2>&1; then
    info "Postgres is ready."
    break
  fi
  sleep 1
done

bold "4/5 Configuring apps/api"
# Bootstrap apps/api/.env idempotently. Steps:
#   a. If apps/api/.env is missing, copy it from .env.example.
#   b. Read APP_KEY= out of the file (works whether .env was just copied or
#      pre-existed). If it's empty, generate one. Never overwrite a real key.
if [[ ! -f apps/api/.env ]]; then
  cp apps/api/.env.example apps/api/.env
  info "Created apps/api/.env from .env.example"
fi

# Match `APP_KEY=…` exactly (no leading spaces, allowing trailing CR for safety).
existing_app_key="$(grep -E '^APP_KEY=' apps/api/.env | head -n1 | cut -d= -f2- | tr -d '\r')"
if [[ -z "$existing_app_key" ]]; then
  info "APP_KEY is empty in apps/api/.env — generating a new one"
  ( cd apps/api && php artisan key:generate --ansi --force )
else
  info "APP_KEY already set in apps/api/.env — keeping it"
fi

( cd apps/api && php artisan migrate --no-interaction )

bold "5/5 Installing Playwright browsers (Chromium only)"
pnpm --filter @catalyst/main test:e2e:install || info "Playwright install skipped (run manually if needed: pnpm --filter @catalyst/main test:e2e:install)"

bold "Setup complete. Next steps:"
cat <<'EOF'

  Start the dev stack:
    pnpm dev

  Run all tests:
    pnpm test

  Mailhog UI:
    http://127.0.0.1:8025

  Postgres:
    psql postgres://catalyst:catalyst@127.0.0.1:5432/catalyst

EOF

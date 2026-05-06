#!/usr/bin/env bash
#
# scripts/dev.sh — launch the full local dev stack.
# Brings up docker services if not already running, then starts all three
# apps (api, main, admin) in parallel via the root pnpm script.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

bold() { printf '\033[1m%s\033[0m\n' "$*"; }

bold "Ensuring docker services are up"
docker compose up -d postgres redis mailhog

bold "Starting api (8000), main (5173), admin (5174)"
exec pnpm dev

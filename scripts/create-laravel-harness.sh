#!/usr/bin/env bash
# Scaffold a local Laravel app at harnesses/laravel-test (gitignored).
set -euo pipefail
root="$(cd "$(dirname "$0")/../../.." && pwd)"
dest="$root/harnesses/laravel-test"
php_sdk="$root/sdks/php/packages"

if [ -d "$dest" ]; then
  echo "Harness already exists: $dest"
  exit 0
fi

mkdir -p "$root/harnesses"
composer create-project laravel/laravel "$dest"
cd "$dest"
composer config repositories.talaria path "$php_sdk/talaria"
composer config repositories.talaria-laravel path "$php_sdk/laravel"
composer require talaria/laravel:@dev

if [ ! -f .env ]; then
  cp .env.example .env
fi
{
  echo ""
  echo "TALARIA_DSN=http://127.0.0.1:8080"
  echo "TALARIA_API_KEY="
  echo "TALARIA_ENVIRONMENT=development"
  echo "TALARIA_ENABLE_TRACING=true"
} >> .env

echo "Harness ready. Set TALARIA_API_KEY, then: ./scripts/dev.sh laravel"

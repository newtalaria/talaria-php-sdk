#!/usr/bin/env bash
# Local matrix: exercise Talaria\Logger against psr/log 1, 2, and 3.
# Requires composer + php on PATH.
set -euo pipefail
cd "$(dirname "$0")/../packages/talaria"

pairs=(
  "1.1.4"
  "2.0.0"
  "3.0.0"
)

for psr in "${pairs[@]}"; do
  echo "=== psr/log ${psr} ==="
  composer require --no-update "psr/log:${psr}"
  composer update --prefer-dist --no-interaction --no-progress
  php -r 'require "vendor/autoload.php"; new ReflectionClass(Talaria\Logger::class); echo "Logger loads\n";'
  composer test
done

echo "All psr/log matrix combinations passed."

#!/usr/bin/env bash
# Run the full benchmark suite. Requires:
#   - ext-resp3 built at ../modules/resp3.so (run `make` from repo root)
#   - composer install --ignore-platform-req=ext-resp3 in repo root
#   - Redis or Valkey on 127.0.0.1:6379 (skip 03_*.php with --skip-e2e)

set -euo pipefail

cd "$(dirname "$0")/.."

EXT="-d extension=./modules/resp3.so"
SKIP_E2E="${1:-}"

mkdir -p bench/results

echo "== Scenario 1: parser throughput =="
php $EXT bench/01_parser_throughput.php
echo

echo "== Scenario 2: allocation pressure =="
php $EXT bench/02_allocation_pressure.php
echo

if [ "$SKIP_E2E" != "--skip-e2e" ]; then
    echo "== Scenario 3a: end-to-end Fledge =="
    php $EXT bench/03_e2e_fledge.php
    echo

    echo "== Scenario 3b: end-to-end amphp/redis =="
    php $EXT bench/03_e2e_amphp.php
    echo
fi

# Compose latest.md from individual scenario reports
{
    echo "# Benchmark results"
    echo
    echo "_Generated: $(date -u +%Y-%m-%dT%H:%M:%SZ)_"
    echo "_Host: $(uname -srm)_"
    echo "_PHP: $(php -v | head -n 1)_"
    echo
    for f in bench/results/01_parser_throughput.md \
             bench/results/02_allocation_pressure.md \
             bench/results/03_e2e_fledge.md \
             bench/results/03_e2e_amphp.md; do
        if [ -f "$f" ]; then
            cat "$f"
            echo
        fi
    done
} > bench/results/latest.md

echo "Wrote bench/results/latest.md"

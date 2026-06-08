#!/usr/bin/env bash
#
# Build downloadable source archives of each SDK and drop them into
# web/public/sdks/ so /downloads can serve them.
#
# Run from the repo root:  bash scripts/build-sdk-zips.sh

set -euo pipefail

VERSION="${1:-0.4.0}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/web/public/sdks"

mkdir -p "$OUT"

# Exclude language-specific build outputs and caches.
EXCLUDES=(
  '*/node_modules/*'
  '*/vendor/*'
  '*/.venv/*'
  '*/dist/*'
  '*/build/*'
  '*/__pycache__/*'
  '*/*.egg-info/*'
  '*/.pytest_cache/*'
)

zip_lang() {
  local lang="$1"
  local zipname="webhook-relay-sdk-${lang}-${VERSION}.zip"
  local target="$OUT/$zipname"

  rm -f "$target"
  cd "$ROOT/sdks"

  local args=()
  for pattern in "${EXCLUDES[@]}"; do
    args+=(-x "$pattern")
  done

  zip -rq "$target" "$lang" "${args[@]}"

  local size
  size=$(du -h "$target" | cut -f1)
  echo "  ✓ $zipname  ($size)"
}

echo "Building SDK source archives v${VERSION}:"
for lang in typescript php python go; do
  zip_lang "$lang"
done

echo
echo "Output: $OUT"
ls -la "$OUT"/*.zip

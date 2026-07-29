#!/usr/bin/env bash
# Build dbtoolbox-lite-VERSION.zip for FTP hosting (no build step required).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
OUT_DIR="${1:-$ROOT/dist}"
STAGE="$OUT_DIR/dbtoolbox-lite-$VERSION"
ZIP="$OUT_DIR/dbtoolbox-lite-$VERSION.zip"

rm -rf "$STAGE"
mkdir -p "$STAGE/storage/backups" "$OUT_DIR"

rsync -a \
  --exclude 'frontend/' \
  --exclude 'node_modules/' \
  --exclude 'vendor/' \
  --exclude 'dist/' \
  --exclude 'deploy/ftp.env' \
  --exclude 'deploy/docker/.env' \
  --exclude 'config/config.php' \
  --exclude 'storage/database.sqlite' \
  --exclude 'storage/db.sqlite' \
  --exclude 'storage/schema_cache/' \
  --exclude 'storage/backups/*' \
  --exclude '.DS_Store' \
  --exclude '._*' \
  "$ROOT/" "$STAGE/"

cp "$ROOT/deploy/root.htaccess" "$STAGE/.htaccess"
cp "$ROOT/deploy/root.index.php" "$STAGE/index.php"
touch "$STAGE/storage/.gitkeep" "$STAGE/storage/backups/.gitkeep"

rm -f "$ZIP"
(cd "$OUT_DIR" && zip -rq "dbtoolbox-lite-$VERSION.zip" "dbtoolbox-lite-$VERSION")

echo "Created $ZIP"
echo "Size: $(du -h "$ZIP" | awk '{print $1}')"

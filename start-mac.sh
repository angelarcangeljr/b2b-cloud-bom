#!/usr/bin/env bash
# Run Solution Summary Builder on a Mac with PHP's built-in server.
# First time: brew install php mysql && brew services start mysql
# Then:       cp .env.example .env   (edit it)   and   ./start-mac.sh
set -euo pipefail
cd "$(dirname "$0")"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is not installed. Install it with:  brew install php" >&2; exit 1
fi
if ! php -m | grep -qi '^pdo_mysql$'; then
  echo "PHP's pdo_mysql extension is missing. Reinstall PHP with:  brew reinstall php" >&2; exit 1
fi
if [ ! -f .env ] && [ ! -f config.php ]; then
  cp .env.example .env
  echo "Created .env from .env.example. Check the database settings in it, then run this again."; exit 0
fi

HOST=localhost; PORT=8000
if [ -f .env ]; then
  v=$(grep -E '^\s*APP_HOST=' .env | tail -1 | cut -d= -f2- | tr -d "\"' ") || true; [ -n "${v:-}" ] && HOST=$v
  v=$(grep -E '^\s*APP_PORT=' .env | tail -1 | cut -d= -f2- | tr -d "\"' ") || true; [ -n "${v:-}" ] && PORT=$v
fi

echo "Solution Summary Builder: http://$HOST:$PORT  (first run: http://$HOST:$PORT/setup.php)"
echo "Press Ctrl+C to stop."
( sleep 1; command -v open >/dev/null && open "http://$HOST:$PORT/" ) >/dev/null 2>&1 &
exec php -d upload_max_filesize=24M -d post_max_size=24M -S "$HOST:$PORT" router.php

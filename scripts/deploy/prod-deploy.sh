#!/usr/bin/env bash
set -Eeuo pipefail

REMOTE="${1:-prod}"
BRANCH="${2:-main}"
CURRENT_BRANCH="$(git branch --show-current)"

if [ -z "$CURRENT_BRANCH" ]; then
    echo "Cannot deploy from detached HEAD"
    exit 1
fi

npm run build

# Плагины RichEditor собираются отдельным esbuild-скриптом; результат едет
# через git (resources/js/dist) и публикуется в public/js артизаном.
npm run build:rich-content-plugins

if [ -n "$(git status --porcelain -- resources/js/dist)" ]; then
    php artisan filament:assets
fi

if [ ! -f public/build/manifest.json ]; then
    echo "Missing public/build/manifest.json after npm run build"
    exit 1
fi

if [ -n "$(git status --porcelain -- public/build resources/js/dist public/js)" ]; then
    git add public/build resources/js/dist public/js
    git commit -m "build: compile frontend assets"
fi

# --no-pager обязателен: с LESS без флага F git-pager ждёт нажатия q
# даже на пустом выводе — деплой «зависает».
git --no-pager diff --check
git push "$REMOTE" "HEAD:$BRANCH"

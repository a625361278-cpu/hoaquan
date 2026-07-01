#!/usr/bin/env bash
set -Eeuo pipefail

APP_ROOT="${APP_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
BRANCH="${BRANCH:-master}"
REMOTE="${REMOTE:-origin}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"
SKIP_CLIENT_BUILD="${SKIP_CLIENT_BUILD:-0}"
SKIP_DB_SYNC="${SKIP_DB_SYNC:-0}"

log() {
  printf '[hoaquan-update] %s\n' "$*"
}

run() {
  log "$*"
  "$@"
}

cd "$APP_ROOT"

if [ ! -d .git ]; then
  echo "当前目录不是 Git 仓库：$APP_ROOT" >&2
  exit 1
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "工作区存在未提交修改，已停止更新，避免覆盖线上改动。" >&2
  git status --short
  exit 1
fi

run git fetch "$REMOTE" "$BRANCH"
run git checkout "$BRANCH"
run git pull --ff-only "$REMOTE" "$BRANCH"

if [ ! -f "$APP_ROOT/server/.env" ]; then
  echo "缺少 $APP_ROOT/server/.env。请先复制 server/.env.example 并填写服务器数据库、Redis 连接信息。" >&2
  exit 1
fi

run "$COMPOSER_BIN" --working-dir="$APP_ROOT/server" install --no-dev --prefer-dist --optimize-autoloader

if [ "$SKIP_CLIENT_BUILD" != "1" ]; then
  pushd "$APP_ROOT/client" >/dev/null
  run "$NPM_BIN" ci
  run "$NPM_BIN" run build:h5
  popd >/dev/null
else
  log "已跳过前端构建：SKIP_CLIENT_BUILD=1"
fi

if [ "$SKIP_DB_SYNC" != "1" ]; then
  pushd "$APP_ROOT/server" >/dev/null
  run "$PHP_BIN" scripts/sync_database.php
  run "$PHP_BIN" scripts/sync_admin.php
  popd >/dev/null
else
  log "已跳过数据库同步：SKIP_DB_SYNC=1"
fi

pushd "$APP_ROOT/server" >/dev/null
if [ -f runtime/webman.pid ]; then
  run "$PHP_BIN" start.php restart -d
else
  run "$PHP_BIN" start.php start -d
fi
run "$PHP_BIN" start.php status
popd >/dev/null

log "更新完成。H5 构建目录：$APP_ROOT/client/dist/build/h5"

#!/usr/bin/env bash
# 下载 Windows 运行时二进制到 runtime/ （供 Linux/macOS 预览或 CI 使用）
# 用法：bash desktop/fetch.sh
set -euo pipefail

NGINX_VER=1.26.2
PHP_VER=8.3.12
MARIADB_VER=11.4.4

RUNTIME="$(cd "$(dirname "$0")" && pwd)/runtime"
mkdir -p "$RUNTIME"
TMP="$(mktemp -d)"

fetch() {
  local name="$1" url="$2" top="$3" dest="$4"
  echo "下载 $name: $url"
  curl -fSL "$url" -o "$TMP/$name.zip"
  rm -rf "$TMP/$name"
  mkdir -p "$TMP/$name"
  unzip -q "$TMP/$name.zip" -d "$TMP/$name"
  local src="$TMP/$name/$top"
  [ -d "$src" ] || src="$TMP/$name"
  mkdir -p "$dest"
  cp -a "$src/." "$dest/"
}

fetch nginx   "https://nginx.org/download/nginx-$NGINX_VER.zip" "nginx-$NGINX_VER"   "$RUNTIME/nginx"
fetch php     "https://windows.php.net/downloads/releases/php-$PHP_VER-nts-Win32-vs16-x64.zip" "php" "$RUNTIME/php"
fetch mariadb "https://archive.mariadb.org/mariadb-$MARIADB_VER/winx64-packages/mariadb-$MARIADB_VER-winx64.zip" "mariadb-$MARIADB_VER-winx64" "$RUNTIME/mariadb"

rm -rf "$TMP"
echo "完成。运行时已就位：$RUNTIME"

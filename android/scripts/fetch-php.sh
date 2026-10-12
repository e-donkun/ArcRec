#!/usr/bin/env bash
# 静的ビルドの PHP(Linux aarch64、static-php-cli の common セット)を取得し、
# アプリのネイティブライブラリ app/src/main/jniLibs/arm64-v8a/libphp.so として置く。
#
#   android/scripts/fetch-php.sh
#
# 環境変数(任意):
#   PHP_SERIES       取得する PHP の系列(既定 8.3。その系列の最新版を取る)
#   PHP_TARBALL_URL  取得する tar.gz の URL を直接指定する(一覧を見ない)
#   PHP_SHA256       取得したファイルの SHA-256。指定すると照合する
set -euo pipefail

android_dir="$(cd "$(dirname "$0")/.." && pwd)"
dest="$android_dir/app/src/main/jniLibs/arm64-v8a/libphp.so"
series="${PHP_SERIES:-8.3}"
base="https://dl.static-php.dev/static-php-cli/common"
url="${PHP_TARBALL_URL:-}"

if [ -z "$url" ]; then
    pattern="php-${series//./\\.}\\.[0-9]+-cli-linux-aarch64\\.tar\\.gz"
    file="$(curl -fsSL "$base/" | grep -oE "$pattern" | sort -u -V | tail -n 1 || true)"
    if [ -z "$file" ]; then
        echo "PHP $series (linux-aarch64) が $base/ に見つかりません。" >&2
        echo "PHP_TARBALL_URL に tar.gz の URL を指定して実行してください。" >&2
        exit 1
    fi
    url="$base/$file"
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
echo "取得: $url"
curl -fsSL "$url" -o "$tmp/php.tar.gz"
if [ -n "${PHP_SHA256:-}" ]; then
    echo "${PHP_SHA256}  $tmp/php.tar.gz" | sha256sum -c -
fi
mkdir "$tmp/x"
tar -xzf "$tmp/php.tar.gz" -C "$tmp/x"
bin="$(find "$tmp/x" -type f -name php | head -n 1)"
if [ -z "$bin" ]; then
    echo "tar.gz の中に php がありません" >&2
    exit 1
fi
if command -v file >/dev/null 2>&1; then
    info="$(file -b "$bin")"
    echo "$info"
    case "$info" in
        *ARM\ aarch64*statically\ linked* | *ARM\ aarch64*static-pie\ linked*) ;;
        *) echo "aarch64 の静的リンクの実行ファイルではありません" >&2; exit 1 ;;
    esac
fi

mkdir -p "$(dirname "$dest")"
install -m 0755 "$bin" "$dest"
sha256sum "$dest"
echo "配置: $dest"

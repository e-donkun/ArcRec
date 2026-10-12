#!/usr/bin/env bash
# アプリに入れる PHP(libphp.so)を確かめる。
#   1. 必要な拡張があるか
#   2. ArcRec のテスト(tests/run.php)がこの PHP で通るか
#   3. アプリと同じ起動方法(組み込みサーバー + router.php)で画面と API が動き、
#      見せない場所(src/ data/ など)が 404 になるか
# x86_64 の PC や GitHub Actions では、qemu(binfmt)で aarch64 の PHP を動かす。
#
#   android/scripts/check-php.sh [PHP のパス]
set -euo pipefail

android_dir="$(cd "$(dirname "$0")/.." && pwd)"
repo="$(dirname "$android_dir")"
php="${1:-$android_dir/app/src/main/jniLibs/arm64-v8a/libphp.so}"
php="$(cd "$(dirname "$php")" && pwd)/$(basename "$php")"

echo "== PHP"
"$php" -n -v

echo "== 拡張"
mods="$("$php" -n -m)"
missing=0
for m in pdo_sqlite mbstring zlib session json pcre ctype; do
    if printf '%s\n' "$mods" | grep -qix "$m"; then
        echo "ok   $m"
    else
        echo "NG   $m がありません" >&2
        missing=1
    fi
done
[ "$missing" -eq 0 ] || exit 1

echo "== ArcRec のテスト"
(cd "$repo" && "$php" -n -d memory_limit=512M tests/run.php)

echo "== 組み込みサーバー"
work="$(mktemp -d)"
mkdir -p "$work/www" "$work/data" "$work/tmp"
(cd "$repo" && tar cf - index.php Api admin src lib data/samples LICENSE.txt NOTICE.md README.md) | tar xf - -C "$work/www"
port=18080
LITE_DATA_DIR="$work/data" TMPDIR="$work/tmp" HOME="$work" PHP_CLI_SERVER_WORKERS=4 \
    "$php" -n -d output_buffering=4096 -d memory_limit=256M -d display_errors=0 -d log_errors=1 \
    -d date.timezone=Asia/Tokyo -d default_charset=UTF-8 \
    -d sys_temp_dir="$work/tmp" -d upload_tmp_dir="$work/tmp" -d session.save_path="$work/tmp" \
    -S "127.0.0.1:$port" -t "$work/www" "$android_dir/app/src/main/assets/router.php" >"$work/server.log" 2>&1 &
cleanup() {
    pkill -f "$work/www" 2>/dev/null || true
    rm -rf "$work"
}
trap cleanup EXIT

for _ in $(seq 1 100); do
    curl -s -o /dev/null "http://127.0.0.1:$port/" && break
    sleep 0.2
done

fail=0
expect() {   # expect コード パス [curl の追加引数...]
    local want="$1" path="$2"
    shift 2
    local got
    got="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: 192.168.43.1:$port" "$@" "http://127.0.0.1:$port$path")"
    if [ "$got" = "$want" ]; then
        echo "ok   $want $path"
    else
        echo "NG   $path は $want のはずが $got" >&2
        fail=1
    fi
}
expect 200 /
expect 200 /admin/
expect 200 /admin/style.css
expect 200 /Api/ISK-NG/ -X POST -d 'requests=[]'
expect 404 /src/Db.php
expect 404 /lib/tcpdf/tcpdf.php
expect 404 /data/samples/5A4D0001.sqlite
expect 404 /config.php
if [ "$fail" -ne 0 ]; then
    echo "---- server.log" >&2
    tail -n 40 "$work/server.log" >&2
    exit 1
fi
ls "$work/data/competitions" >/dev/null
echo "すべて OK"

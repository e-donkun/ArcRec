#!/usr/bin/env bash
# Android arm64 PHP and its runtime libraries from Termux packages.
set -euo pipefail

android_dir="$(cd "$(dirname "$0")/.." && pwd)"
app_dir="$android_dir/app"
repo="https://packages.termux.dev/apt/termux-main"
version="${TERMUX_PHP_VERSION:-8.5.1}"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

curl -fsSL "$repo/dists/stable/main/binary-aarch64/Packages" -o "$tmp/Packages"
python3 - "$tmp/Packages" "$version" > "$tmp/packages.tsv" <<'PY'
import re
import sys

index_path, requested_version = sys.argv[1:]
records = {}
with open(index_path, encoding="utf-8") as index:
    for block in index.read().split("\n\n"):
        fields = {}
        current = None
        for line in block.splitlines():
            if line.startswith(" ") and current:
                fields[current] += " " + line.strip()
            elif ": " in line:
                current, value = line.split(": ", 1)
                fields[current] = value
        if fields.get("Architecture") in ("aarch64", "all"):
            records[fields.get("Package", "")] = fields

php = records.get("php")
if not php or php.get("Version") != requested_version:
    actual = php.get("Version", "missing") if php else "missing"
    raise SystemExit(f"Termux PHP {requested_version} is unavailable (current: {actual})")

selected = set()
queue = ["php"]
while queue:
    name = queue.pop()
    if name in selected:
        continue
    package = records.get(name)
    if not package:
        raise SystemExit(f"Termux dependency is missing from package index: {name}")
    selected.add(name)
    for field in ("Pre-Depends", "Depends"):
        for clause in package.get(field, "").split(","):
            options = clause.split("|")
            candidates = []
            for option in options:
                match = re.match(r"\s*([A-Za-z0-9+_.:-]+)", option)
                if match:
                    candidates.append(match.group(1).split(":", 1)[0])
            dependency = next((item for item in candidates if item in records), None)
            if dependency:
                queue.append(dependency)

for name in sorted(selected):
    package = records[name]
    print("\t".join((name, package["Filename"], package["SHA256"])))
PY

mkdir -p "$tmp/root" "$tmp/runtime/lib"
while IFS=$'\t' read -r name filename sha256; do
    archive="$tmp/$(basename "$filename")"
    curl -fsSL "$repo/$filename" -o "$archive"
    printf '%s  %s\n' "$sha256" "$archive" | sha256sum -c - >/dev/null
    dpkg-deb -x "$archive" "$tmp/root"
done < "$tmp/packages.tsv"

prefix="$tmp/root/data/data/com.termux/files/usr"
php="$prefix/bin/php"
if [[ ! -f "$php" ]]; then
    echo "Termux PHP executable is missing from the downloaded packages." >&2
    exit 1
fi

python3 - "$php" "$prefix/lib" "$tmp/runtime/lib" "$tmp/packages.tsv" "$tmp/root" <<'PY'
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

php, lib_root, output, package_list, extracted_root = map(Path, sys.argv[1:])
output.mkdir(parents=True, exist_ok=True)
system_libraries = {
    "libandroid.so", "libc.so", "libdl.so", "libEGL.so", "libGLESv2.so",
    "libjnigraphics.so", "liblog.so", "libm.so", "libnativewindow.so",
    "libOpenSLES.so", "libvulkan.so",
}
copied = set()
queue = [php]
while queue:
    binary = queue.pop()
    dynamic = subprocess.check_output(["readelf", "-d", str(binary)], text=True)
    needed = re.findall(r"Shared library: \[(.+?)\]", dynamic)
    for name in needed:
        if name in system_libraries or name in copied:
            continue
        matches = list(lib_root.rglob(name))
        if not matches:
            raise SystemExit(f"Missing Termux runtime library {name} required by {binary}")
        source = matches[0]
        target = output / name
        shutil.copyfile(source, target)
        copied.add(name)
        queue.append(source)

licenses = output.parent / "licenses"
for license_file in extracted_root.rglob("copyright"):
    if "/share/doc/" not in str(license_file) or not license_file.is_file():
        continue
    relative = license_file.relative_to(extracted_root)
    destination = licenses / relative
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(license_file, destination)

print(f"Resolved {len(copied)} Android shared libraries")
PY

mkdir -p "$app_dir/src/main/jniLibs/arm64-v8a" "$app_dir/src/main/assets"
install -m 0755 "$php" "$app_dir/src/main/jniLibs/arm64-v8a/libphpandroid.so"
rm -f "$app_dir/src/main/assets/php-android-runtime.zip"
python3 - "$tmp/runtime" "$app_dir/src/main/assets/php-android-runtime.zip" <<'PY'
import sys
import zipfile
from pathlib import Path

root, archive = map(Path, sys.argv[1:])
with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as output:
    for path in sorted(root.rglob("*")):
        if path.is_file():
            output.write(path, path.relative_to(root).as_posix())
PY

echo "Termux Android PHP $version"
echo "Binary: $app_dir/src/main/jniLibs/arm64-v8a/libphpandroid.so"
echo "Runtime: $app_dir/src/main/assets/php-android-runtime.zip"
file "$app_dir/src/main/jniLibs/arm64-v8a/libphpandroid.so"
du -h "$app_dir/src/main/assets/php-android-runtime.zip"
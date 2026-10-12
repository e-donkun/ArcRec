#!/usr/bin/env bash
set -euo pipefail

android_dir="$(cd "$(dirname "$0")/.." && pwd)"
repo_root="$(git -C "$android_dir/.." rev-parse --show-toplevel)"
app_dir="$android_dir/app"
termux_commit="a329973311438a5ab2be5fae9deb6c07a3eb90ba"
builder_image="ghcr.io/termux/package-builder@sha256:44999857244619e25bc8e3427904a16d36cc0c0e087704af039e5bdf94814ac9"
arcrec_commit="$(git -C "$repo_root" rev-parse HEAD)"
tmp="$(mktemp -d)"
termux_repo="${TERMUX_PACKAGE_WORKDIR:-${TMPDIR:-/tmp}/arcrec-termux-packages-$termux_commit}"
container_name="arcrec-termux-builder-$$"
build_cache="${TERMUX_BUILD_CACHE:-${TMPDIR:-/tmp}/arcrec-termux-build}"
data_cache="${TERMUX_DATA_CACHE:-${TMPDIR:-/tmp}/arcrec-termux-data}"
rustup_cache="${TERMUX_RUSTUP_CACHE:-${TMPDIR:-/tmp}/arcrec-termux-rustup}"
cargo_cache="${TERMUX_CARGO_CACHE:-${TMPDIR:-/tmp}/arcrec-termux-cargo}"
trap 'docker rm -f "$container_name" >/dev/null 2>&1 || true; rm -rf "$tmp"' EXIT

if [[ ! -e "$termux_repo/.git" ]]; then
    git clone --quiet --filter=blob:none --no-checkout https://github.com/termux/termux-packages.git "$termux_repo"
fi
git -C "$termux_repo" fetch --quiet --depth 1 origin "$termux_commit"
git -C "$termux_repo" checkout --quiet --detach "$termux_commit"
git -C "$termux_repo" reset --hard "$termux_commit" >/dev/null
git -C "$termux_repo" apply "$android_dir/patches/termux-php-without-readline.patch"

mkdir -p "$build_cache" "$data_cache" "$rustup_cache" "$cargo_cache"
export ARCREC_ANDROID_APP_DIR="$app_dir"
output="$termux_repo/output"
package_cache="$build_cache/_cache-aarch64"
package_cache_all="$build_cache/_cache-all"
if [[ -n "${TERMUX_PHP_PACKAGE:-}" ]]; then
    php_deb="$(realpath "$TERMUX_PHP_PACKAGE")"
else
    export TERMUX_BUILDER_IMAGE_NAME="$builder_image"
    export CONTAINER_NAME="$container_name"
    export TERMUX_DOCKER_RUN_EXTRA_ARGS="--volume $build_cache:/home/builder/.termux-build --volume $data_cache:/data --volume $rustup_cache:/home/builder/.rustup --volume $cargo_cache:/home/builder/.cargo"
    (
        cd "$termux_repo"
        ./scripts/run-docker.sh ./build-package.sh -I -f -a aarch64 php
    )
    php_deb="$(find "$output" -maxdepth 1 -type f -name 'php_*_aarch64.deb' -print -quit)"
fi
if [[ -z "$php_deb" ]]; then
    echo "Termux PHP package was not produced." >&2
    exit 1
fi

mkdir -p "$tmp/root" "$tmp/runtime/lib" "$tmp/runtime/licenses"
python3 - "$output" "$package_cache" "$package_cache_all" "$php_deb" "$tmp/root" "$tmp/runtime" "$termux_repo" "$termux_commit" "$builder_image" "$arcrec_commit" <<'PY'
import hashlib
import os
import re
import shutil
import subprocess
import sys
import urllib.request
import zipfile
from pathlib import Path

output, package_cache, package_cache_all, php_deb, root, runtime, termux_repo = map(Path, sys.argv[1:8])
termux_commit, builder_image, arcrec_commit = sys.argv[8:11]

def control(deb):
    return subprocess.check_output(["dpkg-deb", "-f", str(deb)], text=True)

def fields(deb):
    result = {}
    current = None
    for line in control(deb).splitlines():
        if line.startswith(" ") and current:
            result[current] += " " + line.strip()
        elif ": " in line:
            current, value = line.split(": ", 1)
            result[current] = value
    return result

debs = (
    list(output.glob("*_aarch64.deb"))
    + list(output.glob("*_all.deb"))
    + list(package_cache.glob("*_aarch64.deb"))
    + list(package_cache.glob("*_all.deb"))
    + list(package_cache_all.glob("*_all.deb"))
)
by_name = {fields(deb).get("Package"): deb for deb in debs}
selected = {}
queue = [fields(php_deb)["Package"]]
while queue:
    name = queue.pop()
    if name in selected:
        continue
    deb = by_name.get(name)
    if not deb:
        raise SystemExit(f"Built dependency package is missing: {name}")
    metadata = fields(deb)
    selected[name] = (deb, metadata)
    for clause in (metadata.get("Pre-Depends", "") + "," + metadata.get("Depends", "")).split(","):
        choices = []
        for option in clause.split("|"):
            match = re.match(r"\s*([A-Za-z0-9+_.:-]+)", option)
            if match:
                choices.append(match.group(1).split(":", 1)[0])
        dependency = next((choice for choice in choices if choice in by_name), None)
        if dependency:
            queue.append(dependency)

if "readline" in selected:
    raise SystemExit("The PHP runtime dependency closure still includes readline.")

manifest = [
    "ArcRec source revision\t" + arcrec_commit,
    "ArcRec source URL\thttps://github.com/e-donkun/ArcRec/tree/" + arcrec_commit,
    "Termux source revision\t" + termux_commit,
    "Termux source URL\thttps://github.com/termux/termux-packages/tree/" + termux_commit,
    "Builder image\t" + builder_image,
    "PHP source\thttps://github.com/php/php-src/tree/php-8.5.1",
    "PHP source SHA256\t846f7c5bdb8c2ebb313c4ec25d92339bcbaf733743c9f6a7646e9a5134b90a8e",
    "Patch\tandroid/patches/termux-php-without-readline.patch",
    "",
    "Package\tVersion\tLicense\tTermux recipe\tUpstream source\tSource SHA256\tPackage SHA256",
]

def recipe_value(recipe, key):
    match = re.search(r"^" + re.escape(key) + r"=(?:\"([^\"]*)\"|'([^']*)'|([^\s#]+))", recipe, re.M)
    if not match:
        return ""
    return next((part for part in match.groups() if part is not None), "")

def recipe_assignment(recipe, key):
    match = re.search(r"^" + re.escape(key) + r"=(.*)$", recipe, re.M)
    if not match:
        return ""
    return match.group(1).strip().strip("\"").strip("'")

for name, (deb, metadata) in sorted(selected.items()):
    digest = hashlib.sha256(deb.read_bytes()).hexdigest()
    recipe_path = termux_repo / "packages" / name / "build.sh"
    if not recipe_path.is_file():
        recipes = list((termux_repo / "packages").glob(name + "/*.subpackage.sh"))
        recipe_path = recipes[0] if recipes else recipe_path
    if not recipe_path.is_file():
        raise SystemExit(f"Termux source recipe is missing for package {name}")
    recipe = recipe_path.read_text(encoding="utf-8")
    license_name = recipe_value(recipe, "TERMUX_PKG_LICENSE") or metadata.get("License", "")
    source_url = recipe_assignment(recipe, "TERMUX_PKG_SRCURL")
    source_sha = recipe_value(recipe, "TERMUX_PKG_SHA256")
    source_version = recipe_value(recipe, "TERMUX_PKG_VERSION") or metadata.get("Version", "")
    source_version = source_version.split(":", 1)[-1]
    source_url = source_url.replace("${_SQLITE_YEAR}", recipe_value(recipe, "_SQLITE_YEAR"))
    source_url = source_url.replace(
        "$(sed 's/\\./''/; s/\\./0/' <<< \"$TERMUX_PKG_VERSION\")00",
        source_version.replace(".", "") + "000",
    )
    source_url = source_url.replace(
        "$(sed 's/\\./-/g' <<< ${TERMUX_PKG_VERSION:2})", source_version[2:].replace(".", "-")
    )
    source_url = source_url.replace("${TERMUX_PKG_VERSION[0]}", source_version[:1])
    source_url = source_url.replace("${TERMUX_PKG_VERSION//./_}", source_version.replace(".", "_"))
    source_url = source_url.replace("${TERMUX_PKG_VERSION:2}", source_version[2:])
    source_url = source_url.replace("${TERMUX_PKG_VERSION%.*}", source_version.rsplit(".", 1)[0])
    source_url = source_url.replace("${TERMUX_PKG_VERSION}", source_version).replace("$TERMUX_PKG_VERSION", source_version)
    recipe_url = "https://github.com/termux/termux-packages/blob/" + termux_commit + "/" + recipe_path.relative_to(termux_repo).as_posix()
    if not source_url:
        source_url = "Termux repository source only (no upstream archive)"
        source_sha = "Pinned by Termux commit " + termux_commit
    manifest.append("\t".join((name, metadata.get("Version", ""), license_name, recipe_url, source_url, source_sha, digest)))
    subprocess.run(["dpkg-deb", "-x", str(deb), str(root)], check=True)

prefix = root / "data/data/com.termux/files/usr"
php = prefix / "bin/php"
if not php.is_file():
    raise SystemExit("Termux PHP executable is missing.")

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
    for name in re.findall(r"Shared library: \[(.+?)\]", dynamic):
        if name in system_libraries or name in copied:
            continue
        matches = list(prefix.rglob(name))
        if not matches:
            raise SystemExit(f"Missing runtime library {name}, required by {binary}")
        source = matches[0]
        shutil.copyfile(source, runtime / "lib" / name)
        copied.add(name)
        queue.append(source)

php_needed = subprocess.check_output(["readelf", "-d", str(php)], text=True)
if re.search(r"Shared library: \[libreadline(?:\.so[^]]*)?\]", php_needed):
    raise SystemExit("PHP binary unexpectedly links against GNU Readline.")

license_root = runtime / "licenses"
manifest.extend(["", "Bundled runtime shared libraries"])
for name in sorted(copied):
    manifest.append(name)

for package_name, (deb, metadata) in sorted(selected.items()):
    doc_root = prefix / "share/doc" / package_name
    candidates = []
    if doc_root.is_dir():
        candidates = [
            path for path in doc_root.rglob("*")
            if (path.is_file() or path.is_symlink())
            and re.search(r"(^|/)(copyright|license(?:\..*)?|copying(?:\..*)?|notice(?:\..*)?)$", str(path.relative_to(doc_root)), re.I)
        ]
    if package_name == "php":
        candidates.append(prefix / "share/doc/php/LICENSE")
    if not candidates:
        raise SystemExit(f"No packaged license or copyright text found for {package_name}.")
    for source in sorted(set(candidates)):
        relative = source.relative_to(prefix)
        destination = license_root / package_name / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        if source.is_symlink() and not source.exists():
            target = termux_repo / "packages/termux-licenses/LICENSES" / Path(os.readlink(source)).name
            if not target.is_file():
                raise SystemExit(f"Broken license link for {package_name}: {source}")
            shutil.copyfile(target, destination)
        elif source.is_file():
            shutil.copyfile(source, destination)

if "zstd" in selected:
    zstd_recipe = (termux_repo / "packages/zstd/build.sh").read_text(encoding="utf-8")
    zstd_version = recipe_value(zstd_recipe, "TERMUX_PKG_VERSION")
    zstd_license_url = f"https://raw.githubusercontent.com/facebook/zstd/v{zstd_version}/LICENSE"
    zstd_license = urllib.request.urlopen(zstd_license_url).read()
    zstd_license_path = license_root / "zstd/libzstd-BSD-3-Clause.txt"
    zstd_license_path.parent.mkdir(parents=True, exist_ok=True)
    zstd_license_path.write_bytes(zstd_license)
    zstd_license_hash = hashlib.sha256(zstd_license).hexdigest()
    manifest.extend([
        "",
        "Runtime component licensing",
        f"libzstd.so\tBSD-3-Clause\t{zstd_license_url}\t{zstd_license_hash}",
        "zstd CLI\tGPL-2.0\tnot included in the APK runtime",
    ])

php_license = license_root / "php" / "share/doc/php/LICENSE"
if not php_license.is_file():
    raise SystemExit("PHP LICENSE is missing from the runtime license bundle.")

(runtime / "PACKAGE-MANIFEST.tsv").write_text("\n".join(manifest) + "\n", encoding="utf-8")

app_dir = Path(os.environ["ARCREC_ANDROID_APP_DIR"])
binary_dest = app_dir / "src/main/jniLibs/arm64-v8a/libphpandroid.so"
runtime_dest = app_dir / "src/main/assets/php-android-runtime.zip"
binary_dest.parent.mkdir(parents=True, exist_ok=True)
runtime_dest.parent.mkdir(parents=True, exist_ok=True)
shutil.copyfile(php, binary_dest)
binary_dest.chmod(0o755)
with zipfile.ZipFile(runtime_dest, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
    for path in sorted((runtime / "lib").glob("*")):
        archive.write(path, "lib/" + path.name)
    for path in sorted((runtime / "licenses").rglob("*")):
        if path.is_file():
            archive.write(path, path.relative_to(runtime).as_posix())
    archive.write(runtime / "PACKAGE-MANIFEST.tsv", "PACKAGE-MANIFEST.tsv")

runtime_hash = hashlib.sha256(runtime_dest.read_bytes()).hexdigest()
runtime_dest.with_suffix(runtime_dest.suffix + ".sha256").write_text(
    runtime_hash + "  " + runtime_dest.name + "\n", encoding="ascii"
)

print(f"Termux PHP {fields(php_deb).get('Version')} from {termux_commit}")
print(f"Runtime dependencies: {len(selected) - 1} packages, {len(copied)} shared libraries")
print(f"No Readline dependency; PHP license included")
print(f"Binary: {binary_dest}")
print(f"Runtime: {runtime_dest}")
print(f"Runtime SHA256: {runtime_hash}")
PY
#!/usr/bin/env bash
# Builds the install package: dist/api-endpoints-v<version>.zip
#
#   ./tools/build-zip.sh                         # version read from plugin.php
#   ./tools/build-zip.sh 2.3.6                   # explicit version, must match plugin.php
#   ./tools/build-zip.sh --source <dir> 2.3.6    # package a checkout elsewhere (release workflow)
#
# What goes into the archive is decided by .releaseinclude in the source tree
# and by nothing else: one path per line, directories with a trailing slash,
# "#" starts a comment. Every listed path must exist, and every PHP file of the
# repository outside tests/ and vendor/ must end up in the package, otherwise
# the build stops. The plugin has no runtime dependencies, so no vendor/ ships.
#
# The archive is nested in an `api-endpoints/` folder so it can be unzipped
# straight into `include/plugins/`. File timestamps are set to the commit date
# of the packaged sources and the entries are sorted, so building the same
# commit twice yields the same bytes.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT"
VERSION=""

while [ $# -gt 0 ]; do
  case "$1" in
    --source)
      [ $# -ge 2 ] || { echo "--source needs a directory." >&2; exit 2; }
      SRC="$2"
      shift 2
      ;;
    -h|--help)
      sed -n '2,17p' "$0"
      exit 0
      ;;
    -*)
      echo "Unknown option: $1" >&2
      exit 2
      ;;
    *)
      [ -z "$VERSION" ] || { echo "Only one version argument is allowed." >&2; exit 2; }
      VERSION="$1"
      shift
      ;;
  esac
done

[ -d "$SRC" ] || { echo "Source directory not found: $SRC" >&2; exit 1; }
SRC="$(cd "$SRC" && pwd)"

for required in plugin.php .releaseinclude; do
  [ -f "$SRC/$required" ] || { echo "Missing $SRC/$required" >&2; exit 1; }
done

MANIFEST_VERSION="$(grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" "$SRC/plugin.php" | head -1 | sed -E "s/.*'([^']+)'\$/\1/")"
[ -n "$MANIFEST_VERSION" ] || { echo "Could not read 'version' from $SRC/plugin.php" >&2; exit 1; }
VERSION="${VERSION:-$MANIFEST_VERSION}"

if [ "$VERSION" != "$MANIFEST_VERSION" ]; then
  echo "Version mismatch: requested '$VERSION', plugin.php declares '$MANIFEST_VERSION'." >&2
  exit 1
fi

command -v zip >/dev/null || { echo "zip is not installed." >&2; exit 1; }
command -v unzip >/dev/null || { echo "unzip is not installed." >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
PKG="$STAGE/api-endpoints"
mkdir -p "$PKG"

# --- Stage exactly what .releaseinclude lists ------------------------------
INCLUDED=()
while IFS= read -r line || [ -n "$line" ]; do
  entry="${line%%#*}"
  entry="$(printf '%s' "$entry" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
  [ -n "$entry" ] || continue

  case "$entry" in
    /*|*..*)
      echo ".releaseinclude: refusing path '$entry' (absolute or contains '..')." >&2
      exit 1
      ;;
  esac

  if [ "${entry%/}" != "$entry" ]; then
    dir="${entry%/}"
    [ -d "$SRC/$dir" ] || { echo ".releaseinclude lists directory '$entry' but it does not exist in $SRC." >&2; exit 1; }
    mkdir -p "$PKG/$dir"
    cp -R "$SRC/$dir/." "$PKG/$dir/"
  else
    [ -f "$SRC/$entry" ] || { echo ".releaseinclude lists '$entry' but it does not exist in $SRC." >&2; exit 1; }
    mkdir -p "$PKG/$(dirname "$entry")"
    cp "$SRC/$entry" "$PKG/$entry"
  fi
  INCLUDED+=("$entry")
done < "$SRC/.releaseinclude"

[ "${#INCLUDED[@]}" -gt 0 ] || { echo ".releaseinclude lists nothing." >&2; exit 1; }

# --- Every production PHP file must be in the package -----------------------
missing=0
while IFS= read -r -d '' f; do
  rel="${f#"$SRC"/}"
  if [ ! -f "$PKG/$rel" ]; then
    echo "PHP file not covered by .releaseinclude: $rel" >&2
    missing=1
  fi
done < <(find "$SRC" -type f -name '*.php' -not -path "$SRC/tests/*" -not -path "$SRC/vendor/*" -not -path "$SRC/.git/*" -print0 2>/dev/null)
if [ "$missing" -ne 0 ]; then
  echo "Refusing to build: the package would be incomplete." >&2
  exit 1
fi

# --- Nothing that must never ship ------------------------------------------
if find "$PKG" -type d \( -name .git -o -name tests -o -name node_modules \) | grep -q .; then
  echo "Refusing to build: development directory staged." >&2
  exit 1
fi

# Literal credentials, private keys, hosting-provider tokens, local absolute paths.
if grep -rnIiE \
    "(password|passwd|secret|api[_-]?key|private[_-]?key|access[_-]?token|auth[_-]?token)[[:space:]]*(=|=>|:)[[:space:]]*['\"][^'\"]{4,}" \
    "$PKG" \
  || grep -rnIE "BEGIN (RSA |EC |OPENSSH |DSA )?PRIVATE KEY" "$PKG" \
  || grep -rnIE "(ghp_|github_pat_|glpat-)[A-Za-z0-9_-]{16,}|AKIA[0-9A-Z]{16}" "$PKG" \
  || grep -rnIE "(/Users/[A-Za-z]|/home/[a-z]|[A-Za-z]:\\\\Users\\\\)" "$PKG"; then
  echo "Refusing to build: secret or local path found in the staged files (see above)." >&2
  exit 1
fi

# --- Reproducible archive --------------------------------------------------
if EPOCH="$(git -C "$SRC" log -1 --format=%ct 2>/dev/null)" && [ -n "$EPOCH" ]; then
  :
else
  EPOCH="$(date +%s)"
  echo "Note: $SRC is not a git checkout, using the current time for file dates." >&2
fi
find "$STAGE" -exec touch -d "@$EPOCH" {} +

OUT="dist/api-endpoints-v${VERSION}.zip"
mkdir -p "$ROOT/dist"
rm -f "$ROOT/$OUT"
(
  cd "$STAGE"
  find api-endpoints -type f | LC_ALL=C sort | TZ=UTC zip -X -q "$ROOT/$OUT" -@
)

echo "Built $OUT ($(wc -c < "$ROOT/$OUT") bytes)"
unzip -l "$ROOT/$OUT"
sha256sum "$ROOT/$OUT"

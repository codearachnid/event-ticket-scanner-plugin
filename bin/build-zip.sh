#!/usr/bin/env bash
# Build the distributable plugin ZIP: the repo minus everything in .distignore.
#
# Plugin Check rejects hidden files, shell scripts, and stray markdown in a
# release, so the ZIP is built from a clean export rather than the working tree —
# and CI runs Plugin Check against that export, not the checkout.
#
# Usage: bin/build-zip.sh [--verify <expected-version>]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# The slug is the plugin's own bootstrap filename, NOT the directory name: in CI
# the checkout is named after the GitHub repo, which need not match the slug.
BOOTSTRAP="$(grep -lE '^\s*\*\s*Plugin Name:' "${ROOT}"/*.php | head -1)"

if [[ -z "${BOOTSTRAP}" ]]; then
	echo "error: no plugin bootstrap file (one with a 'Plugin Name:' header) in ${ROOT}" >&2
	exit 1
fi

SLUG="$(basename "${BOOTSTRAP}" .php)"
VERSION="$(grep -E '^\s*\*\s*Version:' "${BOOTSTRAP}" | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
STABLE="$(grep -E '^Stable tag:' "${ROOT}/readme.txt" | head -1 | sed -E 's/Stable tag:[[:space:]]*//' | tr -d '[:space:]')"

# The plugin header and readme.txt must agree, or wordpress.org serves a version
# of the plugin nobody intended to release.
if [[ "${VERSION}" != "${STABLE}" ]]; then
	echo "error: plugin header version (${VERSION}) != readme.txt stable tag (${STABLE})" >&2
	exit 1
fi

if [[ "${1:-}" == "--verify" ]]; then
	EXPECTED="${2:?--verify needs a version}"
	EXPECTED="${EXPECTED#v}"

	if [[ "${VERSION}" != "${EXPECTED}" ]]; then
		echo "error: tag ${EXPECTED} does not match plugin version ${VERSION}" >&2
		exit 1
	fi
fi

OUT="${ROOT}/build"
STAGE="${OUT}/${SLUG}"

rm -rf "${OUT}"
mkdir -p "${STAGE}"

rsync -a --exclude-from="${ROOT}/.distignore" --exclude 'build' "${ROOT}/" "${STAGE}/"

( cd "${OUT}" && zip -qr "${SLUG}.zip" "${SLUG}" )

# Fail loudly rather than shipping a ZIP Plugin Check would reject.
if find "${STAGE}" -name '.*' -o -name '*.sh' | grep -q .; then
	echo "error: hidden or executable files leaked into the build:" >&2
	find "${STAGE}" -name '.*' -o -name '*.sh' >&2
	exit 1
fi

echo "slug=${SLUG}"
echo "version=${VERSION}"
echo "zip=${OUT}/${SLUG}.zip"
echo "dir=${STAGE}"

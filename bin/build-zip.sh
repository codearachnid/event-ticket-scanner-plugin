#!/usr/bin/env bash
# Build the distributable plugin ZIP: the repo minus everything in .distignore.
# Plugin Check rejects hidden files and stray markdown in a release, so the ZIP
# is built from a clean export rather than the working tree.
set -euo pipefail

SLUG="$(basename "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)")"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${ROOT}/build"
STAGE="${OUT}/${SLUG}"

rm -rf "${OUT}"
mkdir -p "${STAGE}"

rsync -a --exclude-from="${ROOT}/.distignore" --exclude 'build' "${ROOT}/" "${STAGE}/"

( cd "${OUT}" && zip -qr "${SLUG}.zip" "${SLUG}" )
echo "${OUT}/${SLUG}.zip"

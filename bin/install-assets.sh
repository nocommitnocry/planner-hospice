#!/usr/bin/env bash
#
# Scarica Bootstrap 5 self-hosted in public/css e public/js.
# Eseguire una volta sola dopo il clone della repo (e al cambio versione).
#
set -euo pipefail

BS_VERSION="${BS_VERSION:-5.3.3}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CSS_DIR="${ROOT_DIR}/public/css"
JS_DIR="${ROOT_DIR}/public/js"

mkdir -p "${CSS_DIR}" "${JS_DIR}"

echo "Scarico Bootstrap ${BS_VERSION}..."

CSS_URL="https://cdn.jsdelivr.net/npm/bootstrap@${BS_VERSION}/dist/css/bootstrap.min.css"
JS_URL="https://cdn.jsdelivr.net/npm/bootstrap@${BS_VERSION}/dist/js/bootstrap.bundle.min.js"

curl -fsSL "${CSS_URL}" -o "${CSS_DIR}/bootstrap.min.css"
curl -fsSL "${JS_URL}"  -o "${JS_DIR}/bootstrap.bundle.min.js"

echo "Asset installati in:"
echo "  ${CSS_DIR}/bootstrap.min.css"
echo "  ${JS_DIR}/bootstrap.bundle.min.js"

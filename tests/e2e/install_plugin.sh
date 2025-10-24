#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SHOPWARE_LOCAL_DIR="${SHOPWARE_LOCAL_DIR:-${ROOT_DIR}/../shopware-local}"
PLUGIN_NAME="FoerdeClickCollect"
PLUGIN_SOURCE="${ROOT_DIR}"
PLUGIN_DEST_CONTAINER="/var/www/html/custom/plugins/${PLUGIN_NAME}"

error() { echo "[install-plugin] $*" >&2; exit 1; }

for dep in docker tar python3; do
  command -v "$dep" >/dev/null 2>&1 || error "Required command not found: $dep"
done

[[ -d "${SHOPWARE_LOCAL_DIR}" ]] || error "shopware-local not found at ${SHOPWARE_LOCAL_DIR}"

run_compose() { (cd "${SHOPWARE_LOCAL_DIR}" && docker compose "$@"); }
run_compose_exec() { (cd "${SHOPWARE_LOCAL_DIR}" && docker compose exec -T "$@"); }

# Ensure shop is up
if [[ -x "${SHOPWARE_LOCAL_DIR}/scripts/e2e/shop-up.sh" ]]; then
  SHOPWARE_LOCAL_DIR="${SHOPWARE_LOCAL_DIR}" "${SHOPWARE_LOCAL_DIR}/scripts/e2e/shop-up.sh"
else
  (cd "${SHOPWARE_LOCAL_DIR}" && make up)
fi

# Sync plugin sources (exclude dev-only files)
(
  cd "${PLUGIN_SOURCE}" && \
  COPYFILE_DISABLE=1 tar \
    --exclude=.git/ \
    --exclude=.github/ \
    --exclude=.idea/ \
    --exclude=.vscode/ \
    --exclude=node_modules/ \
    --exclude=tests/ \
    --exclude=.DS_Store \
    -cf - .
) | (
  cd "${SHOPWARE_LOCAL_DIR}" && \
  docker compose exec -T shop bash -lc "set -euo pipefail; mkdir -p ${PLUGIN_DEST_CONTAINER}; find ${PLUGIN_DEST_CONTAINER} -mindepth 1 -maxdepth 1 -exec rm -rf {} +; tar -C ${PLUGIN_DEST_CONTAINER} -xf -; find ${PLUGIN_DEST_CONTAINER} -name '._*' -delete"
)
echo "[install-plugin] Synchronized plugin sources to container path ${PLUGIN_DEST_CONTAINER}" >&2

# Install/activate and rebuild assets
run_compose_exec shop bash -lc 'set -e; bin/console plugin:refresh; if ! bin/console plugin:install -a FoerdeClickCollect; then bin/console plugin:activate FoerdeClickCollect; fi; bin/console assets:install --force && bin/console bundle:dump && bin/console cache:clear && bin/console theme:refresh && bin/console theme:compile'

# Optional: build admin extensions if make targets exist
if (cd "${SHOPWARE_LOCAL_DIR}" && make -n admin-build-extensions >/dev/null 2>&1); then
  (cd "${SHOPWARE_LOCAL_DIR}" && make admin-build-extensions || true)
fi
if (cd "${SHOPWARE_LOCAL_DIR}" && make -n admin-assets-compat >/dev/null 2>&1); then
  (cd "${SHOPWARE_LOCAL_DIR}" && make admin-assets-compat || true)
fi

echo "[install-plugin] Plugin installed and active" >&2

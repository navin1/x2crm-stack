#!/usr/bin/env bash
# Restores a backup (produced by backup.sh) onto a fresh host/cloud.
# Run this on the NEW server, after installing Docker + Docker Compose.
#
# Usage: ./scripts/restore.sh <timestamp>
#   e.g. ./scripts/restore.sh 20260712_140000

set -euo pipefail
source .env

TIMESTAMP="${1:?Usage: restore.sh <timestamp>}"
WORKDIR="/tmp/x2crm_restore_${TIMESTAMP}"
mkdir -p "$WORKDIR"

echo "==> Downloading backup ${TIMESTAMP} from ${BACKUP_S3_BUCKET}..."
aws s3 cp "${BACKUP_S3_BUCKET}/${TIMESTAMP}/db.sql" "${WORKDIR}/db.sql"
aws s3 cp "${BACKUP_S3_BUCKET}/${TIMESTAMP}/code.tar.gz" "${WORKDIR}/code.tar.gz"
aws s3 cp "${BACKUP_S3_BUCKET}/${TIMESTAMP}/stack_config.tar.gz" "${WORKDIR}/stack_config.tar.gz"

echo "==> Restoring stack config..."
tar xzf "${WORKDIR}/stack_config.tar.gz" -C .

echo "==> Bringing up fresh containers (db + volumes created empty)..."
docker compose up -d db
sleep 15  # let MySQL initialize

echo "==> Restoring database..."
docker exec -i x2crm_db mysql -u root -p"${DB_ROOT_PASSWORD}" "${DB_NAME}" < "${WORKDIR}/db.sql"

echo "==> Restoring X2CRM code volume..."
docker run --rm \
  -v x2crm-stack_x2crm_code:/data \
  -v "${WORKDIR}":/backup \
  alpine sh -c "cd /data && tar xzf /backup/code.tar.gz"

echo "==> Starting the rest of the stack..."
docker compose up -d --build

rm -rf "${WORKDIR}"
echo "==> Restore complete. X2CRM should be reachable on port 8080."

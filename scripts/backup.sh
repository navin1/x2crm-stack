#!/usr/bin/env bash
# Snapshots the DB + X2CRM code volume and uploads to S3-compatible storage
# (works identically with AWS S3, DigitalOcean Spaces, Backblaze B2, MinIO —
# just point --endpoint-url at the right provider, or omit it for AWS).
#
# Requires: docker, aws-cli (configured with your storage provider's keys)
#
# Usage: ./scripts/backup.sh

set -euo pipefail
source .env

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
WORKDIR="/tmp/x2crm_backup_${TIMESTAMP}"
mkdir -p "$WORKDIR"

echo "==> Dumping database..."
docker exec x2crm_db mysqldump -u root -p"${DB_ROOT_PASSWORD}" "${DB_NAME}" > "${WORKDIR}/db.sql"

echo "==> Dumping wa_messages table separately (if present)..."
set +e
docker exec x2crm_db mysqldump -u root -p"${DB_ROOT_PASSWORD}" "${DB_NAME}" wa_messages > "${WORKDIR}/wa_messages.sql"
set -e

echo "==> Dumping wa_admin_audit table separately (if present)..."
set +e
docker exec x2crm_db mysqldump -u root -p"${DB_ROOT_PASSWORD}" "${DB_NAME}" wa_admin_audit > "${WORKDIR}/wa_admin_audit.sql"
set -e

echo "==> Archiving X2CRM code volume..."
docker run --rm \
  -v x2crm-stack_x2crm_code:/data \
  -v "${WORKDIR}":/backup \
  alpine tar czf /backup/code.tar.gz -C /data .

echo "==> Archiving stack config (compose file, .env, Dockerfiles)..."
tar czf "${WORKDIR}/stack_config.tar.gz" \
  docker-compose.yml .env Caddyfile integration/ x2crm-app/Dockerfile

echo "==> Uploading to ${BACKUP_S3_BUCKET}/${TIMESTAMP}/ ..."
aws s3 cp "${WORKDIR}/db.sql" "${BACKUP_S3_BUCKET}/${TIMESTAMP}/db.sql"
aws s3 cp "${WORKDIR}/code.tar.gz" "${BACKUP_S3_BUCKET}/${TIMESTAMP}/code.tar.gz"
aws s3 cp "${WORKDIR}/stack_config.tar.gz" "${BACKUP_S3_BUCKET}/${TIMESTAMP}/stack_config.tar.gz"

rm -rf "${WORKDIR}"
echo "==> Backup complete: ${BACKUP_S3_BUCKET}/${TIMESTAMP}/"

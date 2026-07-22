#!/usr/bin/env bash
# One-time migration: bring this stack up on a NEW server, seeded from an
# existing (legacy) production X2CRM database dump instead of an empty DB.
#
# Unlike restore.sh (which restores THIS stack's own S3 backup, including
# its code volume, onto a replacement server), this script is for moving
# INTO this stack from a different, pre-existing X2CRM install: the app
# code always comes fresh from this repo's Dockerfiles, and only the
# database is seeded from the production dump.
#
# Steps this script performs:
#   1. Start the db container with an empty volume.
#   2. Restore the production dump you supply into it as-is.
#   3. Layer this stack's custom schema on top (scripts/reconcile-custom-schema.sql):
#      WhatsApp Groups / MailerLite sync / iframe-lead-form tables, the
#      x2_web_forms.active/deactivateAt columns, and the guest-access RBAC
#      rows for the public lead-form + MailerLite poller endpoints.
#   4. Build and start the rest of the stack.
#
# What this script does NOT do (do these separately — see the printed
# reminders at the end):
#   - Copy uploaded files (contact photos/attachments) from the old
#     server's protected/uploads — those live on disk, not in the DB.
#   - Re-pair WhatsApp — the paired session lives in the wa_session
#     volume, which starts empty on a new server regardless of the DB.
#
# Usage: ./scripts/migrate-from-prod.sh /path/to/prod_full_dump.sql

set -euo pipefail
source .env

DUMP_FILE="${1:?Usage: migrate-from-prod.sh /path/to/prod_full_dump.sql}"
if [ ! -f "$DUMP_FILE" ]; then
  echo "File not found: $DUMP_FILE" >&2
  exit 1
fi

echo "==> Starting db container with a fresh, empty volume..."
docker compose up -d db

echo "==> Waiting for MySQL to accept connections..."
# mysqladmin ping alone isn't reliable here: the official mysql image runs a
# temporary internal mysqld during first-time init that answers ping before
# the real server (with the configured root password) takes over — so wait
# for an actual authenticated query to succeed instead.
until docker exec x2crm_db mysql -u root -p"${DB_ROOT_PASSWORD}" -e "SELECT 1" >/dev/null 2>&1; do
  sleep 2
done

echo "==> Restoring production dump into ${DB_NAME}..."
# Force FK checks off for the whole load: production dumps can contain
# pre-existing orphaned references (e.g. a row pointing at a since-deleted
# x2_actions row) that a strict reload would reject even though production
# itself has been running fine with that data for years.
#
# Root-caused on a real deployment (cost hours to track down, so leaving
# this documented in full): phpMyAdmin exports embed their own
# `CREATE DATABASE IF NOT EXISTS \`<original-db-name>\`;` and
# `USE \`<original-db-name>\`;` near the top of the file. mysql executes
# these just like any other statement in the stream, which silently
# switches every subsequent CREATE TABLE/INSERT to a DIFFERENT database
# than the one named on the command line — the whole dump loads
# completely successfully, with no error at all, just into the wrong
# database. Every symptom we chased (0 tables in x2crm, then "table
# already exists" on retry) was this, not a truncation/pipe/FK issue.
# Stripping these two statement types before feeding mysql makes every
# CREATE TABLE/INSERT apply to whatever database is named on the command
# line (\$DB_NAME) instead, regardless of what a given dump's own export
# tool assumed the database was called on the original server.
grep -v -E '^(CREATE DATABASE|USE \`)' "$DUMP_FILE" \
  | docker exec -i x2crm_db mysql --init-command="SET FOREIGN_KEY_CHECKS=0" -u root -p"${DB_ROOT_PASSWORD}" "${DB_NAME}"

echo "==> Layering this stack's custom schema on top..."
docker exec -i x2crm_db mysql -u root -p"${DB_ROOT_PASSWORD}" "${DB_NAME}" < scripts/reconcile-custom-schema.sql

echo "==> Building and starting the rest of the stack..."
docker compose up -d --build

echo "==> Removing X2CRM's installer files..."
# index.php's ENTIRE "does this need installing" check is just
# file_exists('install.php') in the webroot — it has nothing to do with
# whether the database actually has data. Since the image is built fresh
# from source on every deploy, install.php is always present after a
# build, and clicking through it against this already-populated (migrated)
# database would be destructive: every core table (x2_contacts, x2_users,
# x2_admin, x2_settings, etc.) gets DROP TABLE + recreated with no
# existence/data check at all, exactly like a normal successful install
# already deletes these same files from itself at the end of the wizard —
# we're just doing that cleanup up front instead, since this script's
# whole point is that installation (in the form of a real, existing
# database) already happened on the old server.
docker exec x2crm_app rm -f \
  /var/www/html/install.php \
  /var/www/html/installConfig.php \
  /var/www/html/initialize.php \
  /var/www/html/initialize_pro.php

cat <<'EOF'

==> Database migration complete. Two things still need doing by hand:

  1. Copy uploaded files from the OLD server (not part of any DB dump):
       # on the OLD server:
       tar czf uploads.tar.gz -C /path/to/old/protected uploads
       # copy uploads.tar.gz to this server, then:
       docker run --rm \
         -v x2crm-stack_x2crm_code:/data \
         -v "$(pwd)":/backup \
         alpine sh -c "cd /data/protected && tar xzf /backup/uploads.tar.gz"

  2. Re-pair WhatsApp: open WhatsApp Configuration in the app and scan the
     QR code again. The wa_session volume starts empty on a new server, so
     the old pairing can't carry over regardless of the DB migration.

EOF
echo "==> X2CRM should be reachable on port 8080."

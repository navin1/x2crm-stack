#!/usr/bin/env bash
# Sanity-checks a production mysqldump file BEFORE you migrate it in.
# Loads it into a disposable, throwaway MySQL container (never touches this
# stack's real x2crm_db container or data) and reports real numbers instead
# of guessing from the text file.
#
# Usage: ./scripts/verify-prod-dump.sh /path/to/prod_full_dump.sql

set -euo pipefail

DUMP_FILE="${1:?Usage: verify-prod-dump.sh /path/to/prod_full_dump.sql}"
if [ ! -f "$DUMP_FILE" ]; then
  echo "File not found: $DUMP_FILE" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="x2crm_dump_verify_$$"

cleanup() {
  docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> File size: $(du -h "$DUMP_FILE" | cut -f1)"

echo "==> Checking for mysqldump's end-of-file marker..."
if tail -c 2000 "$DUMP_FILE" | grep -q "Dump completed on"; then
  echo "    OK: dump file has the normal end-of-export marker."
else
  echo "    WARNING: no 'Dump completed on' trailer near the end of the file."
  echo "    This is the single biggest tell for a truncated export (common with"
  echo "    phpMyAdmin browser timeouts on large databases) — treat the numbers"
  echo "    below with suspicion if you see this."
fi

echo "==> Starting a throwaway MySQL container..."
docker run -d --name "$CONTAINER" \
  -e MYSQL_ROOT_PASSWORD=verify -e MYSQL_DATABASE=verify \
  mysql:8.0 >/dev/null

echo "==> Waiting for it to accept connections..."
# mysqladmin ping alone isn't enough here: the official mysql image runs a
# temporary internal mysqld during first-time init that answers ping before
# the real server (with the configured root password) takes over — so wait
# for an actual authenticated query to succeed instead.
until docker exec "$CONTAINER" mysql -uroot -pverify -e "SELECT 1" >/dev/null 2>&1; do
  sleep 2
done

echo "==> Loading the dump (this can take a while for a large database)..."
# Force FK checks off for the whole load: production dumps can contain
# pre-existing orphaned references (e.g. a row pointing at a since-deleted
# x2_actions row) that a strict reload would reject even though production
# itself has been running fine with that data for years.
#
# Uses --init-command instead of piping a prepended SET statement through
# `cat` — confirmed on a real deployment that the `{ echo ...; cat ...; } |
# docker exec -i` pipe chain can exit 0 with no visible error while
# actually loading almost nothing. Redirecting the file directly into
# mysql's stdin removes that whole pipe chain.
if ! docker exec -i "$CONTAINER" mysql --init-command="SET FOREIGN_KEY_CHECKS=0" -uroot -pverify verify < "$DUMP_FILE" 2>/tmp/verify_dump_load_errors.log; then
  echo "    FAILED to load cleanly — the dump has a SQL error or was cut off mid-statement."
  echo "    Last error output:"
  tail -20 /tmp/verify_dump_load_errors.log
  exit 1
fi
if [ -s /tmp/verify_dump_load_errors.log ]; then
  echo "    Loaded, but with warnings/errors along the way:"
  cat /tmp/verify_dump_load_errors.log
fi

echo
echo "==> x2_contacts row count:"
docker exec "$CONTAINER" mysql -uroot -pverify verify -N -e "SELECT COUNT(*) FROM x2_contacts;" 2>/dev/null \
  || echo "    x2_contacts table not found in this dump!"

echo
echo "==> Diffing x2_contacts columns against the known production column list..."
docker exec "$CONTAINER" mysql -uroot -pverify verify -N -e \
  "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema='verify' AND table_name='x2_contacts' ORDER BY ORDINAL_POSITION;" \
  2>/dev/null | sort > /tmp/dump_actual_columns.txt
sort "$SCRIPT_DIR/expected-prod-contacts-columns.txt" > /tmp/dump_expected_columns.txt

MISSING=$(comm -23 /tmp/dump_expected_columns.txt /tmp/dump_actual_columns.txt)
EXTRA=$(comm -13 /tmp/dump_expected_columns.txt /tmp/dump_actual_columns.txt)

if [ -z "$MISSING" ] && [ -z "$EXTRA" ]; then
  echo "    OK: all expected columns present, no unexpected extras."
else
  if [ -n "$MISSING" ]; then
    echo "    MISSING columns (expected but not in this dump):"
    echo "$MISSING" | sed 's/^/      - /'
  fi
  if [ -n "$EXTRA" ]; then
    echo "    EXTRA columns (in this dump but not in the earlier known list — may be fine, just new):"
    echo "$EXTRA" | sed 's/^/      + /'
  fi
fi

echo
echo "==> Total tables in dump:"
docker exec "$CONTAINER" mysql -uroot -pverify verify -N -e "SHOW TABLES;" 2>/dev/null | wc -l | xargs echo "   "

rm -f /tmp/dump_actual_columns.txt /tmp/dump_expected_columns.txt /tmp/verify_dump_load_errors.log
echo
echo "==> Verification complete. Throwaway container will now be removed."

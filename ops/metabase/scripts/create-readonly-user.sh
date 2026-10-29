#!/usr/bin/env bash
set -Eeuo pipefail

secret=/opt/swaapin-metabase/secrets/mariadb_ro_password
client_file=/opt/swaapin-metabase/secrets/mariadb_ro_client.cnf
password=$(tr -d '\r\n' < "$secret")
[[ "$password" =~ ^[0-9a-f]{64}$ ]] || {
  echo 'Invalid MariaDB analytics password format.' >&2
  exit 1
}

printf "CREATE USER IF NOT EXISTS 'metabase_ro'@'127.0.0.1' IDENTIFIED BY '%s';\nGRANT SELECT ON swapin.* TO 'metabase_ro'@'127.0.0.1';\n" "$password" | mariadb --batch

umask 077
{
  printf '[client]\n'
  printf 'user=metabase_ro\n'
  printf 'password=%s\n' "$password"
  printf 'host=127.0.0.1\n'
  printf 'protocol=tcp\n'
} > "$client_file"
chown root:root "$client_file"
chmod 0600 "$client_file"
unset password

mariadb --defaults-file="$client_file" --batch --skip-column-names swapin -e 'SELECT COUNT(*) FROM categories' > /dev/null
echo 'SELECT_TEST=PASS'

probe="__mb_ro_probe_$$"
test_blocked() {
  local label=$1
  local sql=$2
  if mariadb --defaults-file="$client_file" --batch swapin -e "$sql" > /dev/null 2>&1; then
    echo "$label=UNEXPECTEDLY_ALLOWED" >&2
    if [[ "$label" == CREATE_TEST ]]; then
      mariadb --batch swapin -e "DROP TABLE IF EXISTS $probe" > /dev/null
    fi
    exit 1
  fi
  echo "$label=BLOCKED"
}

test_blocked INSERT_TEST 'INSERT INTO categories (id, name, slug) SELECT -1, "probe", "probe" WHERE 1=0'
test_blocked UPDATE_TEST 'UPDATE categories SET name=name WHERE 1=0'
test_blocked DELETE_TEST 'DELETE FROM categories WHERE 1=0'
test_blocked CREATE_TEST "CREATE TABLE $probe (id INT)"

mariadb --batch --skip-column-names -e "SELECT TABLE_SCHEMA, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = '\`metabase_ro\`@\`127.0.0.1\`' ORDER BY TABLE_SCHEMA, PRIVILEGE_TYPE"

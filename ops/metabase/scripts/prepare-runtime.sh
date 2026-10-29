#!/usr/bin/env bash
set -Eeuo pipefail

runtime=/opt/swaapin-metabase
install -d -m 0750 -o root -g root "$runtime"
install -d -m 0700 -o root -g root "$runtime/secrets" "$runtime/backups" "$runtime/scripts"

umask 077

create_secret() {
  local path=$1
  if [[ ! -e "$path" ]]; then
    openssl rand -hex 32 > "$path"
  fi
  chown root:root "$path"
  chmod 0600 "$path"
  [[ $(stat -c %s "$path") -ge 64 ]] || {
    printf 'Secret file is too short: %s\n' "$path" >&2
    exit 1
  }
}

create_secret "$runtime/secrets/postgres_password"
create_secret "$runtime/secrets/mariadb_ro_password"
create_secret "$runtime/secrets/metabase_admin_password"

if [[ ! -e "$runtime/.env" ]]; then
  {
    printf 'MB_ENCRYPTION_SECRET_KEY=%s\n' "$(openssl rand -hex 32)"
    printf 'MB_SESSION_SECRET_KEY=%s\n' "$(openssl rand -hex 32)"
  } > "$runtime/.env"
fi
chown root:root "$runtime/.env"
chmod 0600 "$runtime/.env"

for path in "$runtime/.env" "$runtime/secrets/"*; do
  stat -c '%a %U:%G %n' "$path"
done

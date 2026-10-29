#!/usr/bin/env bash
set -Eeuo pipefail

swap_file=/swapfile
expected_bytes=2147483648

if [[ -e "$swap_file" ]]; then
  actual_bytes=$(stat -c %s "$swap_file")
  if [[ "$actual_bytes" != "$expected_bytes" ]]; then
    printf 'Refusing to reuse %s: unexpected size %s\n' "$swap_file" "$actual_bytes" >&2
    exit 1
  fi
else
  fallocate -l 2G "$swap_file"
fi

chown root:root "$swap_file"
chmod 0600 "$swap_file"

if ! swapon --noheadings --show=NAME | grep -Fxq "$swap_file"; then
  if ! file -b "$swap_file" | grep -q 'swap file'; then
    mkswap "$swap_file" >/dev/null
  fi
  swapon "$swap_file"
fi

if ! grep -Eq '^/swapfile[[:space:]]+none[[:space:]]+swap[[:space:]]' /etc/fstab; then
  printf '%s\n' '/swapfile none swap sw 0 0' >> /etc/fstab
fi

stat -c 'SWAP_FILE=%n SIZE=%s MODE=%a OWNER=%U:%G' "$swap_file"
free -h
swapon --show

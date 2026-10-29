#!/usr/bin/env bash
set -Eeuo pipefail

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  echo 'Docker Engine and Compose are already installed.'
  docker --version
  docker compose version
  exit 0
fi

. /etc/os-release
[[ "$ID" == debian && "$VERSION_CODENAME" == trixie ]] || {
  echo 'This installation script is limited to Debian 13 (trixie).' >&2
  exit 1
}

for conflicting in docker.io docker-compose docker-doc docker-buildx podman-docker containerd runc; do
  if dpkg-query -W -f='${Status}' "$conflicting" 2>/dev/null | grep -qx 'install ok installed'; then
    printf 'Conflicting package installed: %s\n' "$conflicting" >&2
    exit 1
  fi
done

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc

cat > /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: $VERSION_CODENAME
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker

docker --version
docker compose version
systemctl is-enabled docker
systemctl is-active docker

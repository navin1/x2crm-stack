#!/usr/bin/env bash
# Run once on a fresh Ubuntu 22.04/24.04 VM — works identically on AWS EC2,
# Google Compute Engine, Azure VM, and Oracle OCI Compute, since all four
# offer a stock Ubuntu image and this only touches Docker, not anything
# provider-specific. This is also what you can paste into "user data" /
# "cloud-init" / "custom data" at VM-creation time on any of the four.
#
# Usage: curl -fsSL <raw-url-to-this-file> | bash
#    or: scp this file to the VM and run it directly.

set -euo pipefail

echo "==> Installing Docker Engine + Compose plugin..."
curl -fsSL https://get.docker.com | sh
usermod -aG docker "${SUDO_USER:-$USER}" || true

echo "==> Installing git and awscli (for backup/restore scripts)..."
apt-get update -y
apt-get install -y git awscli

echo "==> Opening firewall for HTTP/HTTPS/CRM ports (ufw, if present)..."
if command -v ufw >/dev/null 2>&1; then
  ufw allow 22/tcp || true
  ufw allow 80/tcp || true
  ufw allow 443/tcp || true
  ufw allow 8080/tcp || true
fi

cat <<'EOF'

==> Bootstrap complete.

Remember: on cloud provider consoles you also need to open the equivalent
inbound rules at the network layer (these are the four to check):
  - AWS:    EC2 > Security Groups        -> allow 80, 443, 8080, 22
  - GCP:    VPC network > Firewall rules  -> allow 80, 443, 8080, 22
  - Azure:  Network Security Group        -> allow 80, 443, 8080, 22
  - Oracle: VCN > Security Lists          -> allow 80, 443, 8080, 22

Next steps:
  git clone <your-repo-url> x2crm-stack && cd x2crm-stack
  cp .env.example .env   # fill in real values
  ./scripts/restore.sh <timestamp>   # if migrating an existing instance
  # OR, for a brand-new instance:
  docker compose up -d --build

EOF

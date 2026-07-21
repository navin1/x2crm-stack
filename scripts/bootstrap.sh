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

# Works whether this runs as root (always true under cloud-init, which is
# the primary documented usage above) or as a regular sudo-capable user
# running it manually via `bash bootstrap.sh`/`scp`+run without an
# enclosing `sudo` (also explicitly documented above as supported) — every
# privileged command below is prefixed with $SUDO, which resolves to
# nothing if already root (no redundant/no-op sudo call) or "sudo"
# otherwise. Previously these commands ran bare: fine under cloud-init,
# but silently failed (masked by `|| true` on the ones that had it, hard
# errors on the ones that didn't) when run manually as a non-root user —
# `curl ... | sh` happened to still work in that case since Docker's own
# install script self-elevates internally, misleadingly suggesting the
# whole script had succeeded when usermod/apt-get/ufw had actually just
# silently done nothing.
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
  SUDO="sudo"
fi

echo "==> Installing Docker Engine + Compose plugin..."
curl -fsSL https://get.docker.com | $SUDO sh

# "${SUDO_USER:-$USER}" doesn't reliably resolve to the real login user when
# this runs the way it's actually documented above to be used: pasted into
# cloud-init/user-data. cloud-init executes as root with no SUDO_USER set
# (that's only populated when a command is invoked via `sudo` from an
# interactive login shell) and $USER resolving to root or nothing in that
# minimal execution context — so this silently added the docker group to
# the wrong account (or no-op'd) on every cloud this script targets, and
# the trailing `|| true` swallowed the failure with no indication anything
# was wrong. All four clouds named above default to a login user named
# "ubuntu" on their official Canonical Ubuntu images, so add that
# explicitly too, in addition to still handling $SUDO_USER for the case of
# someone running this by hand via sudo under a different account.
for candidate in "${SUDO_USER:-}" ubuntu; do
  if [ -n "$candidate" ] && id "$candidate" >/dev/null 2>&1; then
    $SUDO usermod -aG docker "$candidate" || true
  fi
done

echo "==> Installing git and awscli (for backup/restore scripts)..."
$SUDO apt-get update -y
$SUDO apt-get install -y git awscli

echo "==> Opening firewall for HTTP/HTTPS/CRM ports (ufw, if present)..."
if command -v ufw >/dev/null 2>&1; then
  $SUDO ufw allow 22/tcp || true
  $SUDO ufw allow 80/tcp || true
  $SUDO ufw allow 443/tcp || true
  $SUDO ufw allow 8080/tcp || true
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

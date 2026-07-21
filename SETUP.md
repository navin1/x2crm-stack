# SETUP: Local → Cloud, Step by Step

This is the exact sequence to run. Everything else in this repo
(`README.md`) is reference detail — this file is what to actually type.

The whole design point: **local and cloud run the identical Docker
stack.** You're not building something different for each — you build it
once, test it on your laptop, then move the same containers to whichever
cloud you pick later, and again to a different cloud after that if you
ever want to.

---

## Part 1 — Local setup

### Prerequisites
- Docker + Docker Compose installed (Docker Desktop on Mac/Windows, Docker Engine on Linux)
- Git

### Steps

```bash
# 1. Get this stack and the X2CRM source code
git clone <your-repo-url> x2crm-stack
cd x2crm-stack
git clone --branch 7.1 --depth 1 https://github.com/X2Engine/X2CRM.git x2crm-app/src
```
Use the `7.1` tag specifically, not `master` — `master` is a moving
development branch, and at time of writing it has a real bug (a missing
`X2UrlManager` class reference during error handling) that breaks the
`api2` REST endpoints entirely. `7.1` is a proper tagged release and has
been confirmed working end-to-end against this stack.

Sanity check before moving on — X2CRM's repo puts the actual application
inside an `x2engine/` subfolder (alongside `build/`, `README.md`, etc at
the repo root), so confirm it landed where the Dockerfile expects:
```bash
ls x2crm-app/src/x2engine/index.php
```
That file should exist. The Dockerfile copies from `src/x2engine/`
specifically for this reason — nothing to move or rearrange by hand.

```bash
# 2. Configure
cp .env.example .env
# Now edit .env and fill in:
#   - DB_ROOT_PASSWORD, DB_PASSWORD  → pick real passwords
#   - leave CRM_DOMAIN=http://localhost for now
#   - MAILERLITE_API_KEY             → from MailerLite: Integrations > API
#   - leave X2CRM_API_USERNAME / X2CRM_API_KEY blank for now — you'll fill these in after install (step 4)

# 3. Build and start everything
make up
# (equivalent to: docker compose up -d --build)

# 4. Finish the X2CRM installer in your browser
```
Open **http://localhost** and complete X2CRM's web installer. When it
asks for database details, use:
- Host: `db`
- Database: value of `DB_NAME` in `.env`
- User: value of `DB_USER` in `.env`
- Password: value of `DB_PASSWORD` in `.env`

After install, get your API credentials: log in as an admin, go to the
**Users** module, open your own (or another admin) user's record, click
**Update**, and find the **API Key** field — copy it. (Don't use the
built-in "API User" account; it only supports a legacy API version.)
Put your username in `.env` as `X2CRM_API_USERNAME` and the key as
`X2CRM_API_KEY` — X2CRM uses HTTP Basic auth (username + key), not a
bearer token. Then restart the integration service so it picks up the
new credentials:
```bash
docker compose restart integration
```

### Test it's working
```bash
curl http://localhost:3000/health
# should return {"status":"ok"}
```
Then create a contact in X2CRM's UI directly and check `docker compose
logs -f integration` — within `POLL_INTERVAL_MS` (60s default) you
should see it attempt to sync to MailerLite.

### Test webhooks locally (optional, before going to the cloud)
MailerLite needs a public URL to send webhooks to, which `localhost`
isn't. Use a tunnel for testing:
```bash
ngrok http 3000
```
Then temporarily point MailerLite's webhook setting at the
`https://xxxx.ngrok.app/webhooks/mailerlite` URL ngrok gives you, trigger
a MailerLite event, and confirm it shows up as an Action on the contact
in X2CRM. Switch this back to your real domain once you're on the cloud
(step 3 below).

---

## Part 2 — Going live on the cloud

You said you'll assign DNS once deployed — here's exactly where that
plugs in.

### 1. Provision a VM (any provider — AWS, GCP, Azure, Oracle all work identically)

Create a small Ubuntu 22.04/24.04 VM. At creation time, paste the
contents of `scripts/bootstrap.sh` into the provider's "user data" /
"custom data" / "cloud-init" field (all four support this) — it installs
Docker, git, and awscli automatically. Or SSH in after and run it by
hand.

Open inbound ports **22, 80, 443** in whichever firewall layer that
cloud uses (Security Group / Firewall Rule / NSG / Security List).

### 2. Move your stack + data to the new VM

From your **local machine**:
```bash
make backup
# uploads a timestamped snapshot to BACKUP_S3_BUCKET (set this in .env
# first — any S3-compatible storage: AWS S3, DigitalOcean Spaces,
# Backblaze B2, etc.)
```

On the **new cloud VM**:
```bash
git clone <your-repo-url> x2crm-stack && cd x2crm-stack
git clone --branch 7.1 --depth 1 https://github.com/X2Engine/X2CRM.git x2crm-app/src
cp .env.example .env
# fill in the same values as local — DB passwords, MailerLite key,
# X2CRM_API_KEY, BACKUP_S3_BUCKET

make restore TS=<timestamp-from-backup-step>
```
This restores your database and X2CRM install exactly as they were
locally — same contacts, same config, nothing to redo.

### 3. Point DNS at the new VM, then set the domain

Once your DNS A record for e.g. `crm.yourcompany.com` points at the new
VM's IP:
```bash
# on the cloud VM, edit .env:
CRM_DOMAIN=crm.yourcompany.com

docker compose up -d --build
```
Caddy will automatically request and renew a Let's Encrypt HTTPS
certificate for that domain — no manual cert setup.

### 4. Point MailerLite at the real domain

Update the webhook URL in the MailerLite dashboard from your ngrok test
URL to:
- MailerLite: `https://crm.yourcompany.com/webhooks/mailerlite`

### 5. Schedule ongoing backups
On the cloud VM:
```bash
crontab -e
# add:
0 2 * * * cd /home/<user>/x2crm-stack && make backup
```

That's it — you're live, and `make backup` / `make restore` is the same
two-command move to any other cloud in the future, no changes to the
stack itself required.

---

## Appendix: Oracle Cloud Free Tier, complete beginner walkthrough

This walks through standing up a brand-new Oracle Cloud (OCI) account and VM
for this stack from scratch, using only **Always Free** resources (these
never expire — distinct from the separate 30-day/$300 free trial credit,
which is a different thing bundled into the same signup). Written for the
specific scenario of a fresh deployment seeded from your production
database via `scripts/migrate-from-prod.sh`, not from your local dev
stack's own backup. No prior OCI experience assumed.

**Before you start**, have ready: your already-verified production dump
(`scripts/verify-prod-dump.sh` came back clean), and a domain name you can
point a DNS A record at once the VM exists.

### 0. A few OCI terms you'll see in the console

- **Tenancy** — your whole account. You only get one per signup.
- **Compartment** — a folder for organizing resources. For a single VM you
  don't need to create one; everything defaults to the root compartment,
  which is fine here.
- **Availability Domain (AD)** — roughly "which physical facility" within
  the region. Matters later only if you hit a capacity error (see step 2).

### 1. Sign up and pick a region carefully

Go to oracle.com/cloud/free and create an account (email verification,
then your address and a credit card — required even for Always Free, but
you won't be charged as long as you stay within the limits below). Two
things matter here that you can't change after the fact:
- **Home region is permanent** for the account — Always Free resources are
  only available in your home region, so don't pick one just because it's
  geographically closest without checking it's not a heavily-loaded region
  first (heavily-used regions run out of free Ampere capacity more often —
  see step 2).
- OCI is explicit in the console about which resources are "Always Free"
  vs. part of the temporary trial credit — stick to the Always Free ones
  called out below and this deployment costs nothing, indefinitely.

Once logged in, the **☰ hamburger menu** (top-left) is how you get
everywhere in the console — that's referenced below as "☰ →".

### 2. Generate an SSH key pair (on your own machine, not the VM)

You're on a Mac, so:
```bash
ssh-keygen -t ed25519 -f ~/.ssh/oci_x2crm -C "oci-x2crm"
```
Press enter through the passphrase prompt (or set one if you prefer).
This creates `~/.ssh/oci_x2crm` (private key — never share this) and
`~/.ssh/oci_x2crm.pub` (public key — this is what you paste into OCI).

### 3. Create the compute instance

**☰ → Compute → Instances → Create Instance.**

- **Name:** anything, e.g. `x2crm-prod`.
- **Compartment:** leave as the default root compartment.
- **Image:** click "Edit" next to image/shape → **Canonical Ubuntu**,
  version **22.04** (or 24.04) — keeps the Docker/bootstrap steps below
  identical to the rest of this guide.
- **Shape:** in that same edit panel → **Ampere → VM.Standard.A1.Flex**
  (ARM64). Set **2 OCPUs / 12 GB memory** (an "OCPU" is roughly one
  physical CPU core) — still notably more than the alternative Always Free
  x86 shape (VM.Standard.E2.1.Micro, only 1/8 OCPU (burstable) and 1GB RAM
  per instance, max 2 of them — too small to run this whole multi-container
  stack on).
  **Do not provision more than this**, even though the console may let
  you during an active trial: per
  [Oracle's own Always Free documentation](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier.htm),
  the permanent Always Free ceiling for Ampere A1 is 2 OCPUs / 12GB total
  across *all* A1 instances in a tenancy — going over it is tolerated only
  while trial credits are active, and once the trial ends (or if you don't
  upgrade to paid), anything over that ceiling gets disabled and then
  deleted after a 30-day grace period. Not something to risk on a
  production CRM. This stack has no ARM compatibility issues either way:
  `php:7.4-apache`, `mysql:8.0`, `node:20-alpine`, and `caddy:2-alpine` are
  all official multi-arch images with arm64 builds, so nothing needs to
  change in the Dockerfiles.
  - **Idle reclamation risk, separate from the sizing limit above:**
    Oracle can reclaim an Always Free instance if CPU, network, and
    memory utilization all stay below 20% for 7 consecutive days — a real
    scenario for a CRM with light/sporadic usage, not just a hypothetical.
    If you expect long idle stretches, keep something lightweight polling
    the instance (e.g. an external uptime monitor hitting it every few
    minutes) to keep utilization from flatlining.
  - **If you get a capacity/"out of host capacity" error** creating an
    A1.Flex instance, that's a known, common Always-Free-tier issue
    (Ampere capacity is popular and limited per region) — go back and try
    a different Availability Domain in the same region (there are usually
    2-3), or try again in a few hours. This is the single most common
    thing that trips up new OCI users on this shape — it's not something
    you did wrong.
- **Boot volume:** under "Boot volume" → expand from the default (~47GB)
  to around 100GB. Always Free includes 200GB total block storage, and
  this leaves room to spare.
- **SSH keys:** choose "Upload public key file" and select
  `~/.ssh/oci_x2crm.pub` from step 2 (or paste its contents — `cat
  ~/.ssh/oci_x2crm.pub` to view it).
- **Networking:** leave it on the default "Create new virtual cloud
  network" — it auto-creates a VCN with an internet gateway, a public
  subnet, and a default Security List, which is everything you need for a
  single public-facing VM.
- **Cloud-init / user data:** expand **"Show advanced options"** → the
  **Management** tab → paste the full contents of `scripts/bootstrap.sh`
  into the box there. This runs automatically on first boot and installs
  Docker, git, and awscli — you won't need to install these by hand.

Click **Create**. The instance takes a minute or two to reach the
**RUNNING** state — refresh the instance details page until it does.

### 4. Find its address and connect

On the instance's details page, copy the **Public IP Address** shown
there. Connect to it:
```bash
ssh -i ~/.ssh/oci_x2crm ubuntu@<public-ip>
```
The username is `ubuntu` specifically because you picked a Canonical
Ubuntu image (Oracle Linux images instead use `opc`). Type `yes` if asked
to confirm the host key fingerprint — that's normal on a first connection.

**Confirm the bootstrap script actually finished** before moving on:
```bash
cloud-init status --wait
docker --version
docker compose version
```
If `docker --version` fails (command not found), the cloud-init paste
didn't take — run it by hand instead:
```bash
curl -fsSL https://raw.githubusercontent.com/<your-repo>/main/scripts/bootstrap.sh | sudo bash
# or, if the repo is private: scp scripts/bootstrap.sh to the VM first, then:
sudo bash bootstrap.sh
```

### 5. Give it a permanent public IP

The IP from step 4 is *ephemeral* — it can change if the instance is
stopped and started again. Go to **☰ → Networking → IP Management →
Reserved Public IPs**, create one (Always Free includes this), then go to
the instance's attached VNIC and swap its public IP to this reserved one.
Do this now, before you point DNS at anything, to avoid redoing that later.

### 6. Open the ports — in *two* places, not one

This is the single most common OCI stumbling block: opening ports only
gets you halfway there.

**a) Security List** (☰ → Networking → Virtual Cloud Networks → click your
new VCN → Security Lists → the default one): **Add Ingress Rules** for TCP
**22, 80, 443** from source CIDR `0.0.0.0/0` (22 is usually there already
from the wizard; add 80 and 443).

**b) The OS firewall on the VM itself** — Oracle's stock Ubuntu image ships
with `iptables` pre-configured to reject unlisted incoming traffic, on top
of whatever the Security List allows. Back in your SSH session:
```bash
sudo iptables -I INPUT -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save
```
Skip this and Caddy will work fine locally on the VM (`curl localhost`
succeeds) while every external request silently times out — that
mismatch (works on the VM, unreachable from outside) is the tell that this
step specifically was missed, as opposed to the Security List.

### 7. Clone the repo and configure

Still in the SSH session:
```bash
git clone <your-repo-url> x2crm-stack && cd x2crm-stack
git clone --branch 7.1 --depth 1 https://github.com/X2Engine/X2CRM.git x2crm-app/src
cp .env.example .env
nano .env   # fill in DB_ROOT_PASSWORD, DB_PASSWORD, MAILERLITE_API_KEY — same values you'd use anywhere
```
If `docker` commands complain about permissions here, either log out and
back in once (the group membership from bootstrap.sh needs a fresh login
to take effect) or prefix commands with `sudo` for this session.

### 8. Copy your production dump here, then migrate

From your **local machine** (a second terminal, not the SSH session):
```bash
scp -i ~/.ssh/oci_x2crm prod_full_dump.sql ubuntu@<reserved-ip>:~/x2crm-stack/
```

Back in the SSH session on the VM:
```bash
./scripts/migrate-from-prod.sh prod_full_dump.sql
```
This restores the production dump, layers this stack's custom schema on
top (`scripts/reconcile-custom-schema.sql`), and builds/starts everything.
It'll take a while the first time (building 4 images + loading your full
database) — let it run. Follow the two manual steps it prints at the end:
copying `protected/uploads` from the old server, and re-pairing WhatsApp
(its session data isn't part of any DB dump and starts empty on a new VM).

**Sanity check it's actually reachable** before moving to DNS:
```bash
curl -I http://<reserved-ip>
```
from your local machine. A response (even a redirect) means steps 6a/6b
both worked; a hang/timeout means go back and recheck both firewall layers.

### 9. DNS, HTTPS, and ongoing backups

From here it's the same as Part 2, steps 3–5 above: point your DNS A
record at the reserved IP, set `CRM_DOMAIN` in `.env`, run
`docker compose up -d --build` so Caddy issues its Let's Encrypt cert, then
schedule `make backup` via cron.

One Oracle-specific option worth knowing for that last step: OCI Object
Storage has an S3-compatible API and Always Free includes 20GB of it — you
can point `BACKUP_S3_BUCKET` at an OCI bucket instead of AWS S3 if you'd
rather keep backups on the same cloud, with no code changes needed since
`backup.sh` already just uses the S3-compatible `aws` CLI.

### Troubleshooting quick reference

- **Can't SSH in at all:** check port 22 is in the Security List (step 6a),
  and that your key file has the right permissions: `chmod 400
  ~/.ssh/oci_x2crm`. Also double check you copied the *reserved* IP from
  step 5, not a stale ephemeral one.
- **Site works from the VM (`curl localhost`) but not from outside:**
  you're missing step 6b — the VM's own `iptables` is still blocking it
  even though the Security List allows it.
- **Site doesn't work from the VM either:** `docker compose ps` to check
  all 5 containers are `Up`; `docker compose logs caddy` if `caddy` isn't.
- **`docker: permission denied`:** log out of SSH and back in once (group
  membership needs a fresh login), or prefix with `sudo` in the meantime.
- **"Out of host capacity" creating the instance:** covered in step 3 —
  try a different Availability Domain, or retry later. Very common on the
  free Ampere shape specifically, not an error in what you did.
- **`migrate-from-prod.sh` fails on a foreign key error:** see the FK
  troubleshooting already covered for `verify-prod-dump.sh` earlier in
  this project — the same fix (forcing `FOREIGN_KEY_CHECKS=0` for the
  load) is already built into both scripts, so if you still hit this,
  make sure you're running the current version of the script.

---

## Where to look for more detail

Everything below lives in `README.md`:
- How the MailerLite integration and master-data strategy work
- How engagement history (email, both directions) gets logged into X2CRM
- Multiple emails per contact, and custom field syncing
- The X2Flow optional-instant-trigger setup

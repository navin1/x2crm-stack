# X2CRM Portable Cloud Stack + MailerLite Integration

## Why this structure

X2CRM's official install path (upload PHP files to a server, run a web
installer) bakes state into whatever server you install it on. To make it
migratable between clouds, everything here is:

1. **Containerized** — X2CRM (PHP/Apache), MySQL, and the integration
   middleware each run in Docker, defined by `docker-compose.yml`. Any cloud
   with Docker installed can run this identically.
2. **Config-as-code** — all secrets/URLs live in `.env`, not hardcoded.
3. **Stateless outside two volumes** — `db_data` (MySQL) and `x2crm_code`
   (the X2CRM install). Migration = snapshot these two things + your `.env`
   and config files, restore them elsewhere.

## One-time setup

1. Get X2CRM source:
   ```
   git clone --branch 7.1 --depth 1 https://github.com/X2Engine/X2CRM.git x2crm-app/src
   ```
2. Copy `.env.example` to `.env` and fill in real values (DB passwords,
   MailerLite API key).
3. Bring the stack up:
   ```
   docker compose up -d --build
   ```
4. Visit `http://your-server:8080` and complete the X2CRM web installer
   (DB host = `db`, using the credentials from `.env`) — this only needs
   to be done once; it's captured in the volume from then on.
5. Put a reverse proxy (Caddy or nginx + Let's Encrypt) in front of port
   8080 for HTTPS — not included here since it's usually managed at the
   cloud/DNS level.

## Integrating MailerLite

- **Inbound**: In MailerLite, add a webhook under *Integrations →
  Webhooks* pointed at `https://your-domain/webhooks/mailerlite`. It's
  handled in `integration/server.js`, which upserts the contact into
  X2CRM.
- **Outbound**: call `POST /sync/new-lead` (from an X2Flow automation,
  a cron job, or a signup form handler) to push a new lead into
  MailerLite.
- Before going live, double check the exact X2CRM REST API endpoint paths
  for your installed version under *Admin → API* in the X2CRM UI — older
  builds vary slightly, and the placeholder path in `server.js` may need
  a small adjustment.

## Local-first, then migrate to any cloud

The whole point of the Docker structure is that **local and cloud run the
identical stack** — same `docker-compose.yml`, same images, same volumes.
There is no "local version" vs "cloud version" to keep in sync.

1. **Build and run locally** (Docker Desktop on Mac/Windows, or Docker
   Engine on Linux):
   ```
   git clone <your-repo-url> x2crm-stack && cd x2crm-stack
   git clone --branch 7.1 --depth 1 https://github.com/X2Engine/X2CRM.git x2crm-app/src
   cp .env.example .env   # fill in real values
   make up
   ```
   Visit `http://localhost:8080`, run the X2CRM installer once. Test your
   MailerLite integration locally using a tunnel tool (e.g. `ngrok
   http 3000`) so MailerLite can reach your laptop's webhook URL
   temporarily during testing.

2. **When ready to go live, pick any cloud** — AWS, Google Cloud, Azure,
   or Oracle Cloud all work the same way here, because the only
   requirement is "a VM running Ubuntu with Docker." Spin up a small
   Ubuntu VM on whichever one you choose:

   | Cloud | Where to create the VM |
   |---|---|
   | AWS | EC2 > Launch Instance (Ubuntu AMI) |
   | Google Cloud | Compute Engine > Create Instance (Ubuntu image) |
   | Azure | Virtual Machines > Create (Ubuntu image) |
   | Oracle Cloud | Compute > Create Instance (Ubuntu image, Always Free eligible) |

   Paste `scripts/bootstrap.sh` into the VM's "user data" / "custom data"
   / "cloud-init" field at creation time (all four providers support
   this), or SSH in and run it manually. It installs Docker, git, and
   awscli identically regardless of provider.

3. **Move your data over**:
   ```
   ./scripts/backup.sh          # on your local machine
   # on the new cloud VM:
   git clone <your-repo-url> x2crm-stack && cd x2crm-stack
   cp .env.example .env         # same values as local, or new DB passwords
   ./scripts/restore.sh <timestamp>
   ```
4. Point your domain's DNS at the new VM's IP, add HTTPS (Caddy/nginx +
   Let's Encrypt), and update the MailerLite webhook URL to the new
   domain.

Because `docker-compose.yml` never references anything cloud-specific
(no AWS RDS, no GCP-managed anything), you can repeat step 3 again later
to hop to a *different* cloud with zero changes to the stack itself.

## Reverse proxy / HTTPS

The `caddy` service gives you automatic HTTPS on any cloud with zero
manual cert management. Set your domain before starting the stack:
```
export CRM_DOMAIN=crm.yourcompany.com
docker compose up -d --build
```
Point the domain's DNS A record at your VM's IP *before* starting Caddy,
so Let's Encrypt can verify it. Locally, leave `CRM_DOMAIN` unset and
Caddy serves plain HTTP on `localhost`.

Only `/webhooks/*`, `/sync/*`, `/trigger/*`, and `/health` on the
middleware are exposed publicly (see `Caddyfile`) — the rest of the
integration service stays internal to the Docker network.

## What you still need to do to finish the MailerLite integration

1. **MailerLite**: create an API key (Integrations → API), and in the
   MailerLite UI build at least one Automation with a "Joins a group" (or
   "Updated field") trigger and a "Send email" step — this is the email
   your X2CRM workflow will actually cause to go out. Note the group ID.
2. Fill the API key into `.env`.
3. Test each direction once with `curl` before wiring up X2Flow, so you
   know the middleware itself works before adding CRM automation on top.

## Can X2CRM trigger a MailerLite email?

Not directly — MailerLite has no "send this one email now" API call.
What it does have is Automations, which fire on triggers like a
subscriber joining a group or a custom field changing. So the working
path is:

```
X2CRM workflow → POST /trigger/mailerlite-email → middleware calls
MailerLite API (adds subscriber to a group / updates a field) →
that MailerLite Automation (built once, in their UI) sends the email
```

This is a one-time setup per email you want to trigger this way: build
the Automation in MailerLite, then any X2CRM workflow just needs to hit
`/trigger/mailerlite-email` with the right `groupId`.

## How sync actually works (and why)

There are two mechanisms in play, and they're not equally important:

**The always-on poller is the primary mechanism.** It runs in the
background every `POLL_INTERVAL_MS` (default 60s) with zero setup
required inside X2CRM's admin UI, and covers *any* change to a contact
— new records and edits to existing ones alike. This is what makes
"updates in X2CRM reflect automatically in MailerLite" true without you
touching X2Flow at all.

**X2Flow / the `/sync/new-lead` and `/trigger/mailerlite-email` routes
are for instant, event-driven actions** — "add them to a group the
second this form is submitted," not "keep everything eventually in
sync." Set these up later if you want snappier timing on specific
triggers; they're optional, not required for the system to work.

Because the poller is unconditional, you get automatic propagation of
*any* field change — name edits, opt-out flags, added phone numbers —
without maintaining fragile automation rules inside X2CRM's UI for
routine syncing. The tradeoff is up-to-60-second latency instead of
instant, which is usually the right trade for contact data (versus, say,
a welcome message where the timing matters more).

## Multiple emails per contact

Short answer: **X2CRM can likely store more than one, but MailerLite
fundamentally can't represent them as a single record** — a MailerLite
subscriber is keyed by exactly one email address. So "multiple per
contact" needs a deliberate design rather than just syncing everything:

- **In X2CRM**: I don't have verified specifics on your version's exact
  schema — some builds have a single `email` field, others add an
  `alternateEmail`-style custom field. Check your contact's edit screen
  or Admin → Studio to see what's actually there.
- **In the middleware**: set `EMAIL_FIELDS` in `.env` to a
  comma-separated list of whatever columns your schema has (e.g.
  `EMAIL_FIELDS=email,alternateEmail`). The poller reads all of them.
- **MailerLite**: every email in the list gets its own subscriber
  record, each tagged with `x2crm_contact_id` and `is_primary_email` so
  you can tell they belong to the same person even though MailerLite
  sees them as separate subscribers. This is unavoidable given how
  MailerLite is built — there's no "one subscriber, many emails" concept
  there.
- **Recommendation**: if you can, designate a clear "primary" email in
  X2CRM (first in the `EMAIL_FIELDS` list) — this keeps MailerLite's
  reporting clean (one subscriber = one real person in the common case)
  while still capturing secondary contact info in the CRM itself.

## Custom fields

Since you're likely to add your own custom fields in X2CRM, the same
pattern used for email extends to anything else: set `CUSTOM_FIELD_MAP`
in `.env` to `x2crmColumn:destinationFieldName` pairs (comma-separated),
and those columns get pulled by the poller and pushed to MailerLite as
subscriber custom fields automatically — no code changes needed for new
fields.

Two things worth checking as you add fields, since they affect what the
poller can do cleanly:

- **Field type matters for sync, not just storage.** Free-text and
  single-select/dropdown custom fields map straight across as a single
  value. Multi-select/checkbox-style fields (where a contact can have
  several values for one field at once) usually get stored differently
  under the hood and may need a small tweak to `extractCustomFields()`
  to split them out — flag it if you add one of these and it's not
  syncing as expected.
- **MailerLite custom fields need to exist there first.** MailerLite
  requires a custom field to be created in their UI (Subscribers →
  Fields) before the API can write to it — sending a field name that
  doesn't exist yet there either gets ignored or errors, depending on
  their current API behavior, so create the field on the MailerLite side
  before adding it to `CUSTOM_FIELD_MAP`.

## Master data strategy: X2CRM as system of record

Since you want one authoritative contact list, treat this as a
**hub-and-spoke model, not a three-way sync**:

- **X2CRM is the only source of truth for identity data** — name, email,
  company, lifecycle stage. MailerLite is a *destination* that receives
  copies, never the origin of new identity data.
- **Outbound is one-way**: X2CRM → middleware → MailerLite, via the
  `/sync/new-lead` and `/trigger/mailerlite-email` routes (called from
  X2Flow or the DB-polling fallback already in `server.js`).
- **Inbound is narrow by design**: webhooks from MailerLite update only
  *engagement* fields back into X2CRM (email opened/clicked,
  unsubscribed) — never identity fields like name or email. This is why
  the single `/webhooks/mailerlite` endpoint's unsubscribe branch only
  ever writes `doNotEmail: true`, never touches the contact's name.
- **Consent status is the one exception** — unsubscribe/opt-out events
  are allowed to be authoritative from MailerLite's side, since only they
  know what the recipient actually did. Once set, your outbound sync
  logic should check this flag and skip re-adding that contact.
- **Idempotency**: always upsert by email, never create-then-check — this
  is what `upsertMailerliteSubscriber` and `upsertX2crmContact` already
  do, so re-running a sync is safe.

This avoids the classic "which system wins" conflict entirely: there's
only ever one direction identity data flows, so there's nothing to
reconcile.

## Storing engagement history inside X2CRM

Every MailerLite subscriber event (open/click/campaign sent/unsubscribe)
now gets written to X2CRM's **Actions module** — the same module used for
logged calls, tasks, and notes — attached to the relevant contact. That
means the full communication history is visible directly on the contact
record inside X2CRM, not scattered across a separate dashboard.

- `logX2crmAction()` in `server.js` is the single place this happens;
  every webhook handler calls it after upserting the contact.
- **MailerLite emails are tracked**, tagged `Email OUT` / `Email IN`:
  - Outbound: when the `campaign.sent` webhook fires for a recipient,
    the middleware fetches the real subject + a plain-text content
    preview from MailerLite's API (`getMailerliteCampaignSummary`) and
    logs that, not just "a campaign was sent."
  - Inbound (engagement): link clicks, bounces, and unsubscribes are
    logged as `Email IN` events — clicks include the actual URL clicked
    and, where known, the subject of the email it came from.
  - Triggered sends (`/trigger/mailerlite-email`): since the real send
    happens asynchronously once MailerLite's automation fires, this logs
    a "queued" note immediately (pass `emailSubjectPreview` if you
    already know the content), and the real subject/content gets logged
    for real once the `campaign.sent` webhook confirms it went out.
  - **Caveat**: MailerLite's webhook events reliably cover link clicks,
    campaign-sent, bounces, and unsubscribes — a distinct "email opened"
    event isn't consistently available the way clicks are, so don't rely
    on "IN" entries to mean "they opened it" unless they also clicked
    something.
- **Endpoint conventions confirmed via live testing** (against a real
  X2CRM `7.1` instance): `GET`/`POST` to `index.php/api2/Contacts` (no
  `.json`) for listing/creating — returns a JSON array; `PUT` to
  `index.php/api2/Contacts/{id}.json` (with `.json`) for updating a
  specific record — returns a JSON dict. `server.js` follows this
  pattern. The `/Actions` endpoint used for engagement logging follows
  the same convention by inference but hasn't been individually live-
  tested the way `/Contacts` has — create one test Action via the API
  and confirm it shows up correctly on a contact before relying on it in
  production.
- **MailerLite events**: opens/clicks aren't in MailerLite's default
  webhook event list — go to Integrations → Webhooks in MailerLite and
  explicitly enable the click/campaign-sent/bounce/unsubscribe event
  types you want logged, in addition to subscriber created/updated.
- **Volume note**: if you have high message/email volume, logging every
  single event as an individual Action could get noisy. If that becomes
  a problem, an easy adjustment is batching (e.g. one Action per
  contact per day summarizing the day's messages) rather than one per
  event — but start with per-event logging since it's simpler and you
  can always roll it up later.



## Optional: instant triggers via X2Flow

The always-on poller (see "How sync actually works" above) already
keeps everything in sync automatically — this section is only for cases
where you want something to happen *the instant* a contact is created
or updated, rather than within the next `POLL_INTERVAL_MS`. Skip this
entirely if 60-second latency is fine for your use case.

X2CRM's X2Flow automation engine can make remote API calls as part of an
automation sequence. In the X2CRM admin UI:

1. Go to **Admin → Automation** (labeled "Processes" or "Workflows"
   depending on your version) and create a new Process on the
   Contact/Lead module, triggered on Create or Update.
2. Add a **Remote Action / API Call** step (naming varies slightly by
   version — look for anything that lets you specify a URL + HTTP method
   + JSON body).
3. Point it at `http://integration:3000/sync/new-lead` (the containers
   share a Docker network, so this internal hostname works without
   exposing the middleware publicly) with a JSON body mapping the
   Contact's email/phone/name fields.

If your X2CRM version's automation UI doesn't expose a clean remote-call
option, that's fine — nothing breaks. The poller was already covering
this contact regardless; X2Flow just would have made it faster.


## Ongoing backups

Schedule `scripts/backup.sh` via cron (e.g. nightly) so you always have a
recent snapshot ready, independent of any planned migration.

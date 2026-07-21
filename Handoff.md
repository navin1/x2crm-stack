# X2CRM Stack — Status Handoff

## Added: IP rate limiting on the public lead forms (anti-spam, layer 1 of 3 discussed)
Prompted by "what if someone spams my database" — audited the actual exposure
first: no CAPTCHA enabled on any lead form, no rate limiting anywhere, and a
spammed submission on a salesperson form doesn't just bloat the DB, it fires
a real WhatsApp message at that salesperson via the ~30s poll. Three layers
were discussed (Caddy rate limit / CAPTCHA / a notification-volume safety
valve in the poll itself); this round implements just the first, per the
user's request — the other two are still open.
- **Stock `caddy:2-alpine` has no rate-limiting support** — needed the
  community `github.com/mholt/caddy-ratelimit` plugin, which only ships via
  a custom build (not available as a Caddyfile directive on the prebuilt
  image). Added `caddy/Dockerfile` (multi-stage: `caddy:2-builder-alpine` +
  `xcaddy build --with github.com/mholt/caddy-ratelimit`, then copy just the
  resulting binary into a plain `caddy:2-alpine` runtime image) and switched
  `docker-compose.yml`'s `caddy` service from `image: caddy:2-alpine` to
  `build: { context: ./caddy }` — **this is the one service in the stack
  that changed from a pulled image to a local build**, worth remembering
  next time `docker compose pull` vs `--build` matters.
- `Caddyfile`: new `@leadforms` matcher (`/leadform*.html` and
  `/index.php/contacts/contacts/weblead*`) wrapping a `rate_limit` block
  with two zones keyed on `{remote_host}` (i.e., by IP) — `leadform_burst`
  (5 requests/minute) catches scripted rapid-fire submission,
  `leadform_sustained` (30/hour) catches slower, persistent single-IP abuse.
  Everything else in the CRM (`site/login`, the admin panel, etc.) is
  untouched — scoped narrowly on purpose so this can't accidentally lock
  out a real user's normal CRM session.
- **Verified for real**: fired 7 rapid requests at the weblead endpoint —
  first 5 returned 200, 6th and 7th returned 429. Confirmed `leadform.html`
  shares the *same* IP budget (hit it right after exhausting the quota via
  the weblead requests — also 429'd). Confirmed unrelated pages
  (`site/login`, hit 3x back to back) were completely unaffected.
- **Still open, not built this round**: (1) enabling X2CRM's built-in
  CAPTCHA (`requireCaptcha` on the `WebForm` rows — exists, just currently
  `0` on every form this session created — plus wiring a `CCaptcha` widget
  into the custom-generated pages, which don't render one today); (2) a
  submission-volume safety valve inside `pollForNewProspects()` itself, so
  a salesperson can't get WhatsApp-bombed even if the first two layers are
  somehow bypassed.

## Added: per-salesperson personal lead forms, notified via WhatsApp per prospect
Big one — three design decisions were confirmed with the user first (field
customization = fixed catalog not a free-form builder; notification timing
= polling, not an instant custom X2Flow action; scope = repeatable
per-salesperson generation, not a one-off).

- **New concept: "salesperson" ≠ CRM user.** New table `x2_salespersons`
  (name, phone, active) — someone with no X2CRM login at all, just a name
  and WhatsApp number, can have their own lead form.
- **Admin flow** (Lead Forms page, new top panel "Create a Salesperson's
  Personal Lead Form"): pick an existing salesperson or create one inline,
  name the form, checkbox-pick optional fields from a fixed catalog (phone,
  company, job title, city, website, message — all real `x2_contacts`
  columns, confirmed via `DESCRIBE` before picking them so every checkbox
  maps to a field `Contacts[x]` will actually save). firstName/lastName/
  email are always included, not optional.
- **Generation, on submit** (`actionCreateSalespersonForm` in
  `WhatsappGroupsController.php`):
  1. Insert the registry row first (`x2_custom_lead_forms`) to get an id.
  2. Create a **dedicated** `x2_web_forms` row per form — own
     `leadSource = "SalesForm-<registryId>"` (this is *the* correlation
     key the polling step below keys off), `generateLead=1`,
     `redirectUrl=/leadform-thanks.html`.
  3. Render a full standalone HTML page (`renderLeadFormTemplate()` —
     same visual design/CSS as `leadform.html`, same CSRF-fetch-then-POST
     and form-status-check JS, but with the field list and `webFormId`
     baked in per-call) and `file_put_contents()` it straight into the
     X2CRM docroot as `leadform-<slug>-<registryId>.html`. Same
     same-origin-avoids-CORS-and-guest-permissions reasoning as
     `leadform.html` — see below for why this *has* to be a real file, not
     a dynamically-served PHP action.
  4. Fires the existing "notify me a new form was created" WhatsApp
     message (unchanged, admin-to-self).
- **Why a written file, not a dynamic PHP page**: any *new* guest-facing
  action added to `WhatsappGroupsController` would hit the same
  guest-permission wall `/form-status/:id` hit before — X2CRM's
  `X2ControllerPermissionsBehavior::beforeAction` redirects guests to login
  unless a specific auth item grants that exact action to guests, and
  rendering a whole HTML *page* (not just a JSON status check) can't be
  moved to wa-hub the way `/form-status/:id` was, because the browser needs
  to fetch a CSRF token same-origin from X2CRM and then POST there — doing
  that across origins (wa-hub's port vs. X2CRM's) hits CORS and cookie
  problems X2CRM's main app isn't configured for. Writing a real file next
  to `leadform.html` sidesteps all of it for free.
- **Per-prospect notification — polling, not instant** (`wa-hub/server.js`,
  `pollForNewProspects()`, every 30s): for every lead-form row with a
  `salespersonId`, queries `x2_x2leads` for rows with that form's
  `leadSource` and `createDate` past the form's own `lastPolledAt`
  watermark, and WhatsApps the salesperson the prospect's submitted
  details. The watermark **only advances past leads that were successfully
  sent** — a failed send (e.g. WhatsApp disconnected) leaves it unmoved so
  that lead gets retried next cycle instead of silently dropped. Reuses the
  same "poll on an interval" shape `integration/server.js` already uses for
  MailerLite/WATI, rather than hooking X2CRM's core weblead submission
  directly (would need either modifying core X2CRM code or writing a new
  custom X2Flow action class — both bigger, riskier builds than a 30s poll
  for this use case).
- **Verified for real, not just code review**: created a salesperson
  ("Jane Doe") + form with fields `[phone, company, backgroundInfo]`
  through the actual admin UI → confirmed the generated file has exactly
  those `Contacts[...]` fields plus the always-on three, correct `title`,
  correct `webFormId`/`LEAD_FORM_ID` baked in → submitted a real test
  prospect through it → confirmed the `X2Leads` row landed with
  `leadSource='SalesForm-2'` → confirmed the poll picked it up (visible
  in wa-hub's audit log, `notify_new_prospect`) and correctly retried
  **twice** ~30s apart rather than giving up, since WhatsApp happened to be
  disconnected at the time (same pre-existing session-logout issue as
  ever, unrelated to this feature) — confirms the watermark-holds-on-
  failure logic actually works, not just that it compiles. All test data
  (salesperson, form, generated file, lead, contact) cleaned up after.
- **Still pending, same as before**: WhatsApp needs to be re-paired
  (Admin → WhatsApp Configuration → Start Pairing) for any of this round's
  actual message sends to go through — nothing new broken, just still
  logged out from earlier in this session.

## Added: deactivate a lead form (forced now, or scheduled datetime)
- `x2_custom_lead_forms` gained `active TINYINT(1) DEFAULT 1` and
  `deactivateAt BIGINT NULL`. Effective status is computed on read, not by
  a background job: `active = stored_active AND (deactivateAt IS NULL OR
  deactivateAt > now)` — no cron needed.
- **New public, unauthenticated wa-hub endpoint**: `GET /form-status/:id`
  — reads straight from `x2_custom_lead_forms` (wa-hub already shares this
  MySQL DB), returns `{active, name, reason}`. Deliberately outside
  `requireAdmin` and with an open CORS header
  (`Access-Control-Allow-Origin: *`) — anonymous visitors on a static page
  need to call this before the form even renders, and it exposes nothing
  beyond a boolean. Distinct from the admin-only PHP-proxied endpoints.
- `leadform.html` now calls this on load: if inactive, hides the form
  entirely and shows a "no longer accepting submissions" (or "expired" for
  a past schedule) message instead — and re-checks again right before the
  actual submit fires, to close most (not all — see below) of the race
  window if someone deactivates it while a visitor already has the page
  open. Fails **open** on a network error checking status (doesn't want a
  status-check hiccup to block a real, working submission) but fails
  **closed** on an actual `active:false` response.
- **Known limitation, not fixed**: this is enforcement on our own custom
  page only. The underlying X2CRM `contacts/contacts/weblead?webFormId=2`
  endpoint itself has no concept of "deactivated" — a determined visitor
  could still POST to it directly, bypassing `leadform.html` entirely.
  Fine for the actual use case (campaign page lifecycle management, not
  an adversarial security boundary) but worth knowing the ceiling here.
- New admin-only controller actions: `actionDeactivateLeadForm` (forced,
  immediate), `actionReactivateLeadForm` (undo — also clears any schedule,
  since "reactivate" means "make it live now," not "reschedule"),
  `actionScheduleLeadFormDeactivation` (set/clear the datetime on an
  existing row without re-registering it). `actionRegisterLeadForm` also
  gained an optional `deactivateAt` field for setting a schedule at
  creation time.
- `leadForms.php` admin view: new Status column (Active / "Active until
  &lt;date&gt;" / Deactivated / Expired), a Deactivate-Now-or-Reactivate
  button that swaps based on current state, and a small inline
  `datetime-local` + "Set Schedule" form per row.
- Verified every state transition directly against the running system
  (not just code review): forced deactivate → `GET /form-status/1` flips
  to `active:false, reason:"deactivated"` → reactivate → back to
  `active:true`. Scheduled datetime in the past → `active:false,
  reason:"scheduled"`. Scheduled datetime in the future → stays
  `active:true`, admin list correctly shows "Active until Jan 1, 12:00 AM".
  Cleared the test schedule afterward, left `id=1` (leadform.html) plainly
  active.

## Fixed: tinyUrl always came back NULL on the Lead Forms notify
Root cause: `notifyAdminNewForm()` in `wa-hub/server.js` called
`axios.get('https://tinyurl.com/api-create.php', { params: { url } })` —
axios's default query-param serializer leaves `:` unencoded (produces
`url=http:%2F%2F...` instead of the correct `url=http%3A%2F%2F...`), and
tinyurl.com's API rejects that malformed encoding with a `400`. The catch
block around it swallowed the error silently (by design, so a tinyurl.com
hiccup wouldn't block the WhatsApp send) — which is exactly why it *looked*
like nothing happened rather than throwing visibly.
- Reproduced directly against the live API (both via `curl` and a raw
  `axios.get` inside the container) before touching anything, confirmed the
  exact malformed query string via a request interceptor, and confirmed a
  manually `encodeURIComponent()`-encoded, inlined URL succeeds.
- Fixed by building the query string manually
  (`'...api-create.php?url=' + encodeURIComponent(url)`) instead of using
  axios's `params` option for this specific call.
- Verified end-to-end through the real UI: re-triggered "Notify Again" on
  the existing `leadform.html` registry row (id=1) — `x2_custom_lead_forms.tinyUrl`
  now correctly shows `https://tinyurl.com/2267mrjo`, `notifiedAt` updated.

## Added: logo on lead form + "Lead Forms" registry with WhatsApp notify (QR + tinyURL)
- `leadform.html` now has a logo slot above the heading (currently a
  placeholder gradient icon — swap the `.logo-placeholder` div for
  `<img src="/logo.png" class="logo">` once a real logo file exists; CSS
  for both is already in place).
- New **"Lead Forms" admin page**: `Admin → Administration Tools → Web Lead
  Capture and Routing → "Lead Forms"` (route
  `/whatsappGroups/whatsappGroups/leadForms`). Purpose: since forms like
  `leadform.html` are now hand-built static pages (not X2CRM's dynamic
  form designer), there's no built-in way to track "what lead forms exist
  and what are their URLs" — this is that registry. Register a
  name+URL (+ optional `webFormId` if it posts to one), and it:
  1. Stores it in a new table, `x2_custom_lead_forms` (added to
     `modules/whatsappGroups/data/install.sql`, already applied live).
  2. Immediately sends a WhatsApp **message-to-self** (the same linked
     account, not a third party — deliberately chosen to sidestep the
     unsolicited-automated-messaging ban risk flagged earlier) containing
     the URL, a scannable QR code image, and a tinyurl.com short link.
  3. "Notify Again" button per row re-sends it (e.g. once WhatsApp is
     actually paired — see below).
- New in `wa-hub/server.js`: `sendWhatsAppMessage()` (generic text+image
  send, defaults to self if no phone given), `notifyAdminNewForm()` (QR +
  tinyurl.com lookup + send), and three endpoints: `POST
  /admin/notify-new-form`, `GET /admin/qr-for-url.png?url=...` (generic,
  distinct from `/admin/qr.png` which is specifically the *pairing* QR).
- New in `WhatsappGroupsController.php`: `actionLeadForms` / `actionRegisterLeadForm`
  / `actionNotifyLeadForm` / `actionQrForUrl` — all admin-only.
- **Verified real registration**: registered `leadform.html` itself
  (id=1, `http://localhost/leadform.html`, linked to `webFormId=2`) — the
  DB row was created correctly, but the WhatsApp send failed with a clean
  `"wa-hub error: WhatsApp not connected"` (expected — session was still
  logged out from earlier debugging). **Action needed**: once WhatsApp is
  re-paired via the WhatsApp Configuration page, click "Notify Again" on
  this row to actually get the QR/tinyURL message.

## Still open: email notification to the lead with their submitted details
Discussed but not built this round — X2CRM has a built-in, non-Pro-gated
way to do this with **zero custom code**: the `WebleadTrigger` X2Flow
trigger ("New Web Lead") already fires on every submission through
`leadform.html` (confirmed in `WebFormAction.php` — same trigger the
built-in form uses), and a paired "Send Email" workflow action can use the
lead's own submitted fields as merge tags.
- **Blocker**: checked `x2_credentials` — currently **zero** email-sending
  accounts configured anywhere in this CRM. Admin → Email Settings
  (`/admin/emailSetup`) needs a real account (Gmail SMTP or similar) set up
  before *any* automated email (to the lead, or to the admin) can send at
  all. Not something to configure blind via SQL (touches real credentials).
- Once that's done: either walk through building the workflow in the
  Process/Workflow builder UI, or have it configured directly — deferred
  pending the email account setup.

## Added: custom-styled public lead capture page (bypasses the plain default form)
X2CRM's built-in Web Lead Form iframe is very plain (real custom CSS is a
Pro-edition-only feature; this install is Open Source Edition — confirmed
via `contEd('pro')` / `getEdition()` in `ApplicationConfigBehavior.php`).
Rather than fight that, built two standalone static HTML pages that use
X2CRM's *existing, working* lead-capture backend directly:
- `x2crm-app/src/x2engine/leadform.html` — the actual form (deployed to
  the X2CRM docroot, reachable at `/leadform.html`). Fully custom CSS
  (gradient card layout), posts straight to X2CRM's real endpoint
  (`/index.php/contacts/contacts/weblead?webFormId=2`) with fields named
  `Contacts[firstName]` etc. — exactly what the built-in form posts, traced
  from `WebFormAction.php`.
- `x2crm-app/src/x2engine/leadform-thanks.html` — matching thank-you page
  at `/leadform-thanks.html`.
- **The CSRF problem, and how it's solved**: this is a static file with no
  PHP behind it, but Yii enforces CSRF on all POSTs app-wide
  (`enableCsrfValidation => true` in `config/main.php`, no per-action
  override for `weblead`). Since it's same-origin, `leadform.html`'s own JS
  does a `fetch()` GET to the *real* weblead endpoint on page load, regexes
  the `YII_CSRF_TOKEN` hidden-field value out of the returned HTML, and
  injects it into a hidden input before the (plain, non-AJAX) form submit
  — so the actual submission is a normal full-page POST, not fetch-based,
  which avoids having to scrape success/error state out of X2CRM's
  response HTML client-side.
- **DB row required**: a `WebForm` record (`x2_web_forms`, id **2**, name
  "Custom Lead Form") had to exist for `webFormId=2` to resolve — this is
  what supplies `generateLead=1`, `leadSource='Custom Web Form'`, and
  critically `redirectUrl='/leadform-thanks.html'`. On success,
  `webFormSubmit.php` (X2CRM core, not ours) does
  `window.top.location.href = redirectUrl` — that's what lands the visitor
  on our thank-you page. Inserted directly via SQL, not through the admin
  designer UI (faster, and the designer UI wasn't going to be used for
  styling anyway).
- Verified end-to-end for real (not just page-loads): replayed the exact
  fetch-token → POST flow via curl, confirmed a real `Contacts` row *and* a
  real `X2Leads` row (with `leadSource='Custom Web Form'`) were created,
  confirmed the `window.top.location` redirect script pointed at
  `leadform-thanks.html`. Test contact/lead deleted after verifying.
- **Not yet done**: WhatsApp notification to the lead + QR-code-to-creator
  flow discussed alongside this — deliberately deferred pending the earlier
  open questions (per-submission vs. once-at-creation QR timing, and the
  flagged risk of automated WhatsApp messaging to leads who haven't opted
  in). This round was scoped to just the "make the page beautiful" ask.

## Added: web-based WhatsApp pairing page (for production, not just local logs)
Previously the QR code only ever printed as ASCII art to `docker compose
logs wa_hub` — fine locally, but means anyone re-pairing in production needs
SSH access to the server. Added a proper web page instead.
- `wa-hub/server.js`: new `latestQr` in-memory variable, set on the 'qr'
  connection.update event, cleared on successful connect. Two new
  `requireAdmin`-protected endpoints:
  - `GET /admin/qr` — self-refreshing HTML page (polls `/admin/wa-status`
    every 4s): shows the live QR image while disconnected, switches to
    "Connected as +&lt;phone&gt;" once paired. This is now **the one place**
    to go to pair/re-pair WhatsApp, local or production alike — just open
    the URL in a browser (basic-auth prompt uses the same X2CRM API
    username/key as the rest of wa-hub's admin API) and scan.
  - `GET /admin/qr.png` — the actual QR rendered as a real PNG (via the new
    `qrcode` npm package, distinct from the pre-existing `qrcode-terminal`
    which only does ASCII and is still used for the log output too).
  - `/admin/wa-status` response gained a `hasQr` boolean the page's JS uses
    to decide what to show (connected / show QR / "waiting for QR").
- **Production note, not yet done**: `wa_hub`'s port 3001 is currently
  published directly (`ports: "3001:3001"` in docker-compose.yml) — fine
  for localhost, but for a real cloud deployment this should go through
  Caddy (path-based proxy + TLS) rather than exposing the raw port to the
  internet, same as x2crm/integration already do implicitly via port 80/443.
  Not implemented — flag if/when actually deploying.
- Verified: page loads (200) and enforces the same admin auth as everything
  else (401 without credentials); `/admin/qr.png` cleanly 404s when no QR
  is currently active (e.g. already connected, or session simply hasn't
  generated one yet) rather than erroring.
- **Also noticed while testing**: the WhatsApp session logged out again at
  some point after the last handoff entry (`"WhatsApp logged out"` in
  `docker compose logs wa_hub`, no auto-reconnect by design — see the
  "Fixed: wa-hub WhatsApp connection" entry below for why this can happen
  and isn't preventable from our side). Session hasn't been re-paired yet
  (user deferred it this round) — next person to need WhatsApp group
  features will need to clear `auth_info_baileys` and pair again via the
  new `/admin/qr` page.

## Added: WhatsApp Groups now also in the Contacts top-nav dropdown
Previously only reachable via the Contacts list page's "Actions" menu
(`ContactsController::insertMenu()`). Now also appears directly in the main
top-nav "Contacts" dropdown (hover/click "Contacts" in the top bar — same
place as "All Contacts", "Lists", "Create Contact", "Create List").
- Added in `protected/views/layouts/main.php`, right after the existing
  `foreach($moduleMenuItems as $moduleAction)` loop inside the
  `if ($name === 'contacts')` block (~line 332). Had to be a separate
  `array_push($moduleActions, ...)` rather than another entry in
  `$moduleMenuItems`, because that loop unconditionally prefixes every
  item's url with `$baseModuleUrl` ("contacts/") — fine for contacts' own
  actions, but WhatsApp Groups lives in a different module entirely.
- Edited via a small Python script rather than the Edit tool, after two
  failed attempts at exact-whitespace string matching — this file mixes
  trailing spaces inconsistently (leftover from years of hand-edits), so
  matching by line number was more reliable than matching by content.
- Deployed via `docker cp` to
  `protected/views/layouts/main.php` (this is a core layout file, not part
  of the whatsappGroups module directory — don't forget it on future
  deploys of nav-related changes).
- Verified with an authenticated session: the link
  (`<li class="top-bar-module-action-link"><a href="/index.php/whatsappGroups">WhatsApp Groups</a></li>`)
  now renders nested inside the Contacts dropdown's `<ul>`, right after
  "Saved Maps", and resolves 200.

## Added: manage/delete a WhatsApp group (rename + delete)
- `wa-hub/server.js`: `renameGroup()` (`sock.groupUpdateSubject`) and
  `leaveAndDeleteGroup()` (`sock.groupLeave` + drops `wa_groups`/
  `wa_group_members` rows) behind `POST /admin/groups/:groupId/rename` and
  `DELETE /admin/groups/:groupId`.
- **Important WhatsApp limitation, confirmed with the user before
  building**: there is no "delete for everyone" API for groups — an
  account can only leave. So "Delete Group" here = wa-hub's account leaves
  (which fully removes the group if wa-hub was the only member — true for
  every group the CRM itself creates — but leaves it intact for other real
  members if any exist) + X2CRM always drops its own tracking rows either
  way.
- X2CRM: `WhatsappGroupsController::actionRename()` /
  `actionDelete($groupId)`, both POST-only. UI: a "Rename group" inline
  form + red "Delete Group" button (with a confirm dialog explaining the
  leave-only caveat) on `views/whatsappGroups/view.php`, plus a "Delete"
  row-action on `index.php`. Both use X2CRM's `linkOptions =>
  array('submit' => ..., 'confirm' => ...)` convention (a plain `CHtml::link`
  with `url => '#'` — X2CRM's global JS turns this into a real POST with
  CSRF token, confirm-gated) rather than a full visible `<form>`.
- Verified end-to-end through the actual authenticated UI routes (not just
  direct wa-hub calls): submitted the rename form → flash "Group renamed" +
  updated `<h1>`; submitted delete → flash "Group deleted", group gone from
  both X2CRM's list and wa-hub's own `/admin/groups`.
- Note while testing: a "Sync from WhatsApp" run (presumably you, between
  messages) pulled in ~130 of the linked account's **real** WhatsApp groups
  (hundreds of members each) into `wa_groups` — confirms that feature works,
  but means the table now has real data mixed with any test groups. Only
  test/delete against groups you recognize as your own test ones.

## Fixed: "HTTP Error 500: {"error":"bad-request"}" on group create
Separate bug from the connection issue below — this happens *after* wa-hub
is genuinely connected, when `sock.groupCreate()` itself rejects the
request. Root cause: **including the linked WhatsApp account's own phone
number in the participants list** — WhatsApp rejects this since the account
is already the implicit group owner. Confirmed by testing directly against
wa-hub: a X2CRM test contact ("testfn testln", contact id 7) has phone
`+17603907974`, the exact same number as the linked account.
- Fixed in `wa-hub/server.js`: added `getOwnPhone()`/`excludeOwnPhone()`,
  called at the top of both `createWhatsAppGroup()` and
  `addMembersToGroup()` to silently strip the bot's own number out of any
  participants list before calling Baileys. Watch out if touching this:
  `sock.user.id` is formatted like `"17603907974:59@s.whatsapp.net"` — the
  `:59` is a per-device suffix, not part of the phone number; stripping
  non-digits without removing it first (my first attempt at this fix) makes
  the comparison silently never match.
- **Also confirmed separately**: a participant phone number that isn't a
  real WhatsApp-registered number (e.g. a placeholder/fake test number like
  `+15551234567`) *also* throws the same `bad-request` — WhatsApp validates
  all participants before allowing group creation, all-or-nothing. Not
  fixable from wa-hub's side; just don't seed test Contacts with fake phone
  numbers if you intend to add them to a WhatsApp group.
- Improved `WhatsappGroupsController::callWaHub()` so this class of error
  surfaces a clear, actionable message instead of the raw
  `HTTP Error 500: {"error":"bad-request"}` dump you saw.
- **Side effect**: created 4 real (mostly empty) test WhatsApp groups on the
  linked account while debugging this ("wa-hub verification test", "...
  test 2", "wa-hub own-number-only test" — 1 member, "wa-hub filter fix
  test"). wa-hub has no delete-group endpoint; clean up via the phone's
  WhatsApp app, or ask to have the `wa_groups`/`wa_group_members` DB rows
  removed (only stops them showing in X2CRM's list, doesn't touch the real
  WhatsApp group).

## Fixed: wa-hub WhatsApp connection ("WhatsApp not connected" on group create)
Chain of real bugs found and fixed in `wa-hub/server.js` + `docker-compose.yml`,
in the order discovered:
1. **Stale WhatsApp Web protocol version.** Baileys ships a protocol version
   baked in at library-publish time; WhatsApp deprecates old versions within
   weeks. Without calling `fetchLatestBaileysVersion()`, every connection
   attempt failed the noise handshake before a QR was ever issued (looked
   like an infinite "connected to WA" → "Connection Failure" loop). Fixed by
   fetching the live version and passing it into `makeWASocket()`.
2. **No persistent volume for the paired session.** `wa_hub`'s
   `auth_info_baileys` dir (Baileys creds) had no volume mount, so every
   `docker compose up --build wa_hub` silently wiped the pairing and forced
   a fresh QR scan — including from unrelated code changes. Added a named
   volume `wa_session:/app/auth_info_baileys` in `docker-compose.yml`.
3. **Root cause of "WhatsApp not connected" even while genuinely paired**:
   every connection-state check in the file did
   `sock.ws.readyState === 1`. On this Baileys version, `sock.ws` is
   Baileys' own `WebSocketClient` wrapper class, **not** a raw browser/`ws`
   WebSocket — it has no `readyState` property at all, so that comparison
   was `undefined === 1`, **always false**, regardless of whether the
   account was actually connected. This was found by adding a temporary
   `/admin/wa-debug` endpoint (since removed) that dumped the raw `sock`
   internals. Fixed by tracking a dedicated `isOpen` module-level flag, set
   from the `connection.update` event's `connection === 'open'/'close'`
   itself (the correct, idiomatic Baileys pattern) — replaced all 8
   `sock.ws.readyState` checks throughout the file with `isOpen`.
4. **Self-inflicted regression (introduced then reverted this session)**: a
   "watchdog" that force-reconnected whenever it saw the (at-the-time
   broken) disconnected state raced against the existing 5-second
   reconnect-on-close logic, causing two concurrent Baileys sessions to
   fight over the same linked device — WhatsApp correctly kicked both in a
   `Stream Errored (conflict)` loop. Removed; not needed once (3) was fixed.
   This also corrupted the then-current session's signal-protocol keys
   (concurrent writes to the same `auth_info_baileys` files), which needed
   a full wipe + re-pair (user confirmed) to recover from.
- Also disabled `syncFullHistory` (only need group metadata, not chat
  history) and raised `defaultQueryTimeoutMs` to 60s — worth keeping even
  though they weren't the actual root cause, since they reduce unnecessary
  work on connect.
- **Known benign noise, not a bug**: a Baileys-internal
  `fetchProps`/`executeInitQueries` call reliably times out about 60s after
  every connect (`"unexpected error in 'init queries'"`, logged at pino
  level 50/error). Confirmed via direct testing that this does **not**
  affect `isOpen` or actual functionality — group creation keeps working
  fine through and after it. Left as-is; it's an upstream Baileys quirk
  unrelated to the real connection state.
- **Side effect from testing**: created two real WhatsApp groups on the
  now-linked account ("wa-hub verification test", "wa-hub verification
  test 2", both empty/zero members) while confirming the fix — wa-hub has
  no delete-group endpoint, so removing them (if wanted) means leaving/
  deleting from the phone's WhatsApp app directly, or asking to have their
  `wa_groups`/`wa_group_members` DB rows removed to stop them showing in
  the X2CRM list (doesn't touch the real WhatsApp group, just the mirror).
- **Repeated gotcha this session**: `docker compose up -d --build <svc>`
  against this compose file recreates `x2crm_app` too (not just the target
  service), even though its image didn't change. Harmless since
  `x2crm_code` is a named volume (host module edits already deployed via
  `docker cp` survive), but don't be surprised by it, and re-verify
  `getenv('X2CRM_API_USERNAME'/'X2CRM_API_KEY')` are still set after.

## Confirmed working
- Local Docker stack: MySQL 8.0, X2CRM (PHP 7.4/Apache), Node.js integration middleware, Caddy reverse proxy
- X2CRM cloned from the `7.1` tag (NOT `master` — master has a real bug: missing `X2UrlManager` class breaks api2 error handling)
- Caddyfile matches both `localhost` and `127.0.0.1` as site addresses
- CRM_DOMAIN=http://localhost locally (explicit http:// disables Caddy's auto-HTTPS attempt)
- X2CRM REST API confirmed via live curl testing:
  - `GET/POST index.php/api2/Contacts` (no `.json`) → array, for listing/creating
  - `PUT index.php/api2/Contacts/{id}.json` (with `.json`) → dict, for updating one record
  - Auth is HTTP Basic (username:apikey), NOT bearer token
  - Get credentials from: Users module → your admin user → Update → API Key field
- `curl http://127.0.0.1/health` on the integration service returns `{"status":"ok"}` when working

## Still open / last thing being debugged
- Just updated `server.js`'s X2CRM helper functions to match the confirmed
  `/Contacts` (no .json) vs `/Contacts/{id}.json` convention
- Restarted the `integration` container after this change
- `curl http://127.0.0.1/health` immediately after restart returned nothing
  — likely just a race condition (container not fully up yet), retry with
  a few seconds' delay first
- If still failing after that: check `docker compose ps integration` (is
  it `Up` or crash-looping?) and `docker compose logs integration --tail=30`
  (any startup error, e.g. JS syntax error from the recent edit?)
- `Actions` endpoint (used for logging WhatsApp/email history into X2CRM)
  uses the same `/Actions` (no .json) convention by inference — NOT yet
  individually confirmed via live testing the way `/Contacts` was

## WhatsApp Groups module (X2CRM ↔ wa-hub) — now wired up end-to-end
- `wa-hub/` is a separate Node service (Baileys-based WhatsApp client) with
  its own container (`wa_hub` in docker-compose, port 3001). It owns and
  auto-creates the `wa_groups` / `wa_group_members` tables directly in the
  shared X2CRM MySQL database on startup (`initDb()` in `wa-hub/server.js`,
  `CREATE TABLE IF NOT EXISTS`, DATETIME columns, no `x2_` prefix — this is
  intentionally NOT the usual X2CRM `x2_`-prefixed/BIGINT-timestamp
  convention, because wa-hub reads/writes these tables directly with raw SQL).
- New X2CRM module at `x2crm-app/src/x2engine/protected/modules/whatsappGroups/`:
  - `models/WhatsAppGroups.php`, `models/WhatsAppGroupMembers.php` — X2Model
    subclasses mapped to the wa-hub-owned tables (scaffolding only — the
    controller currently talks to wa-hub's REST API directly via curl, not
    through these models)
  - `controllers/WhatsappgroupsController.php` — proxies to wa-hub's
    `/admin/groups*` endpoints using HTTP Basic auth with the X2CRM API
    username/key (wa-hub's `requireAdmin` explicitly accepts those same
    credentials — see `wa-hub/server.js` line ~528)
  - `views/whatsappGroups/{index,create,view}.php` — list/create/view UI,
    each with a contact search box + "Select all"/"Select none" bulk controls
  - `WhatsappGroupsModule.php` + `register.php` + `data/install.sql` —
    X2CRM modules are **not** registered via `config/main.php`; a module is
    "installed" by having a row in the `x2_modules` DB table (menu/nav is
    built from that table in `views/layouts/main.php`). `install.sql` has
    already been run against the running stack's DB — `x2_modules` has a
    `whatsappGroups` row (`visible=1, menuPosition=90`) and both tables exist.
  - Files were `docker cp`'d into the running `x2crm_app` container (its
    `/var/www/html` is a **named Docker volume**, populated once from the
    image build — host edits under `x2crm-app/src/...` do NOT auto-sync to
    the running container; re-`docker cp` after further edits, or rebuild).
- Fixed: `docker-compose.yml`'s `x2crm` service was missing `env_file: .env`,
  so `getenv('X2CRM_API_USERNAME'/'X2CRM_API_KEY')` in the controller
  returned nothing and every wa-hub call would have 403'd. Added `env_file`
  and recreated the container (`docker compose up -d x2crm`) — confirmed via
  `php -r "getenv(...)"` inside the container that both are now set.
- Verified `GET /index.php/whatsappGroups/whatsappgroups/index` resolves and
  redirects to login (302, no PHP errors in `docker compose logs x2crm`) —
  i.e. the module loads correctly. **Not yet verified**: an actual logged-in
  admin session hitting the page and seeing groups sync from a real
  WhatsApp connection (no browser/session test was done this round).
- Also fixed a bug in `view.php`'s "Add Members" modal: it had a broken
  duplicate `<form>` (a `CActiveForm` widget plus a second unused
  `CHtml::form` after it) — collapsed into a single properly-wired form.

## WhatsApp Groups ↔ Contacts action menu + dynamic list filtering (round 2)
- Added a **"WhatsApp Groups" entry to the Contacts action menu**
  (`ContactsController::insertMenu()` + `views/contacts/index.php`
  `$menuOptions`), linking to `/whatsappGroups/whatsappgroups/index`.
- Contact filtering for group membership reuses **X2CRM's own Lists engine**
  (`X2List`/`X2ListCriterion`, the same mechanism behind Contacts > Create
  List) rather than a hand-rolled filter UI, per explicit user choice:
  **dynamic/live** membership (re-evaluates the list's criteria every sync,
  vs. a static one-time snapshot) and a **list-picker dropdown** (vs.
  embedding the full attribute/comparison/value criteria-builder widget
  inline). `WhatsappgroupsController::getAccessibleContactLists()` /
  `getListPhones()` wrap `X2List::load()`/`queryCriteria()` for this.
- `wa_groups` gained a `listId` column (nullable, added via idempotent
  `information_schema` check + `ALTER TABLE` in `wa-hub/server.js`'s
  `initDb()`, since wa-hub owns this table — see prior section). New wa-hub
  endpoints: `POST /admin/groups/:groupId/link-list` (set/unset the linked
  list) and `POST /admin/groups/:groupId/sync-members` (given a phone list,
  diffs against current WhatsApp members and adds/removes to match —
  `syncGroupMembersToPhones()`).
- New controller actions: `actionSyncMembers($groupId)` (pulls the linked
  list's *current* live criteria results, phones them to wa-hub's
  sync-members endpoint) and `actionLinkList()` (link/unlink an existing
  group to a list from the view page). `create.php` now has a "Filter by
  List" dropdown that, when set, supersedes the manual contact checkboxes.
- **Gotcha hit while deploying**: `docker cp host_dir container:existing_dir`
  does **not** merge/overwrite — since `.../modules/whatsappGroups` already
  existed in the container from round 1, it nested the new copy inside
  itself (`.../whatsappGroups/whatsappGroups/...`), silently leaving stale
  files in place at the real path. Fix: `docker exec rm -rf` the target dir
  in the container first, then `docker cp` fresh. Watch for this on every
  future re-deploy of a directory that already exists in the container.
- Verified via curl (no login session available in this session, so no
  actual browser walkthrough was done): `/whatsappGroups/whatsappgroups/index`
  and `/create` both 302 to login cleanly; `wa_groups.listId` column
  confirmed present via `DESCRIBE`; no errors in either container's logs.
## Fixed: WhatsApp Groups menu link 404'd (module/controller id casing mismatch)
- Root cause found by logging in as admin (password from user, not derivable
  from `.env` — `X2CRM_API_KEY` and DB passwords are NOT the web login
  password) and diffing the actually-rendered `<a href>` against what worked
  via curl.
- `config/main.php`'s urlManager has a **generic shorthand rule** (line
  ~226/230): `'<module:\w+>' => '<module>/<module>/index'` and
  `'<module:\w+>/<action:\w+>' => '<module>/<module>/<action>'`. This is
  what X2CRM's own auto-generated top-nav module tab relies on
  (`views/layouts/main.php` ~line 274/913 builds tab links as just
  `"/$name/$defaultAction"`, e.g. `/products/index`) — it **assumes the
  module id and its main controller's id are the identical string**.
- My module directory/id was `whatsappGroups` (capital G) but the controller
  class was `WhatsappgroupsController`, which Yii turns into controller id
  `whatsappgroups` (**all lowercase** — Yii only lowercases the *first*
  letter of a class name to derive a controller id, it doesn't preserve
  internal capitals specially, so a class with no internal capitals other
  than the leading one collapses case). `whatsappGroups` ≠ `whatsappgroups`,
  so the shorthand substitution produced a route pointing at a controller id
  that didn't exist → 404, for **both** the auto-generated top-nav tab and
  my own Contacts-menu link (which hardcoded the old lowercase id).
- **Fix**: renamed the controller class + file to
  `WhatsappGroupsController.php` (capital G matches the module id exactly),
  updated `WhatsappGroupsModule.php`'s `$defaultController` and
  `ContactsController.php`'s menu-item route to match. Views already lived
  under `views/whatsappGroups/` (capital G, matching the module name from
  the start), so no view-path change was needed.
- Verified with an authenticated session (not just curl-without-cookies):
  fetched the real rendered Contacts page, extracted the actual `<a href>`
  values for "WhatsApp Groups" (there are two: the top-nav tab and the
  Contacts action-menu item), and hit both directly — both now 200 with the
  real page content (`<h1>WhatsApp Groups</h1>`, no PHP errors in
  `docker compose logs x2crm`). Also verified the Create Group page renders
  (200, includes the "Filter by List" dropdown, no error markers).
- **Residual, deliberately not chased**: a hand-typed **all-lowercase bare**
  URL (`/index.php/whatsappgroups`, no path elsewhere in the app renders
  this) still 404s, since module id lookup is case-sensitive and the real
  module id is `whatsappGroups`. This was the literal string a live browser
  session hit earlier in testing (visible in the access log), but it's not
  a value that any real link in the app produces — not worth special-casing.
- **General lesson for any future custom module here**: keep the module
  directory name and the main controller's derived id **identical strings**
  (case included), or this whole class of shorthand-routing rule breaks
  silently with a plain 404 and no error log entry (`createController()`
  just returns null on a resolution miss — no exception, no logged message).

## Not yet configured
- MailerLite webhook events beyond the default set (clicks, campaign-sent,
  bounces, unsubscribes need explicit enabling in MailerLite's dashboard)
- Cloud deployment (local setup only so far)
- WA_ADMIN_TOKEN / WA_ADMIN_USERS still default to `changeme` placeholders
  in docker-compose (`wa_hub` service) — fine for local dev, must be set
  for anything beyond localhost

## Key files
- `docker-compose.yml` — full stack definition
- `Caddyfile` — reverse proxy routing
- `integration/server.js` — the sync middleware (X2CRM ↔ MailerLite). WATI
  support was removed from this file — see "WATI removed" note below.
- `wa-hub/server.js` — WhatsApp (Baileys) service; owns `wa_groups`/
  `wa_group_members` tables and the `/admin/groups*` REST API
- `x2crm-app/src/x2engine/protected/modules/whatsappGroups/` — X2CRM-side
  WhatsApp Groups admin module (see section above)
- `.env` — all credentials/config (not committed, has real values locally)
- `SETUP.md` — step-by-step local→cloud instructions
- `README.md` — detailed reference on integration design decisions

## WATI removed
The WATI (WhatsApp Business API) integration described in earlier
sections of this file was fully removed by user request: `WATI_API_URL`/
`WATI_API_KEY` were never actually filled in (see "Not yet configured"
above, when that was still true), so nothing operational broke. Removed:
`integration/server.js`'s WATI helpers, the `/webhooks/wati` and
`/send/wati-message` routes, the WATI branch inside `/sync/new-lead`, the
WATI half of `pollForChangedContacts()` (and the now-unused
`PHONE_FIELDS`/`primaryPhone` extraction that only fed it), the
`WATI_API_URL`/`WATI_API_KEY` env vars, and the WATI references across
`Caddyfile`, `.env`, `README.md`, and `SETUP.md`. The self-hosted
Baileys WhatsApp integration (`wa-hub/server.js`) is unaffected — it
never depended on WATI at all; WATI and wa-hub were two independent
paths to WhatsApp that happened to coexist in this stack.
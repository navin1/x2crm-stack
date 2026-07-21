require('dotenv').config();
const express = require('express');
const axios = require('axios');
const crypto = require('crypto');
const mysql = require('mysql2/promise');

const app = express();
app.use(express.json());

const {
  PORT = 3000,
  X2CRM_API_URL,        // e.g. http://x2crm:80/index.php/api2
  X2CRM_API_USERNAME,   // a real admin username (NOT the built-in "API User")
  X2CRM_API_KEY,        // that user's API Key, from Users > (their record) > Update
  MAILERLITE_API_URL = 'https://connect.mailerlite.com/api',
  // Fallback only — once a key is saved via the "MailerLite Configuration"
  // admin page, the DB value (see getMailerliteApiKey()) always wins. This
  // just keeps an existing .env-configured install working unchanged.
  MAILERLITE_API_KEY: MAILERLITE_API_KEY_ENV,
  NEW_CONTACT_MAILERLITE_GROUP = 'X2CRM - New Contacts',
  INTEGRATION_SHARED_SECRET,
} = process.env;

// Small dedicated pool just for integration_settings (the live-editable
// MailerLite API key) — kept separate from pollForChangedContacts' own
// one-connection-per-poll style below so this doesn't touch already-working
// code; this one's queried on every MailerLite API call, so a persistent
// pool avoids a connect/disconnect round trip each time.
let settingsPool = null;
async function ensureIntegrationSettingsTable() {
  if (!process.env.DB_HOST) return;
  settingsPool = await mysql.createPool({
    host: process.env.DB_HOST,
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_NAME,
    waitForConnections: true,
    connectionLimit: 3,
  });
  await settingsPool.execute(`
    CREATE TABLE IF NOT EXISTS integration_settings (
      id TINYINT PRIMARY KEY,
      -- MailerLite's API keys are long JWT-style tokens (~1000 chars),
      -- nowhere close to fitting a typical VARCHAR(255) API-key column.
      mailerliteApiKey TEXT NULL,
      updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  `);
  // Tracks every Contacts list ever synced to MailerLite from the
  // "MailerLite Configuration" admin page: which MailerLite group it maps
  // to, when it last synced, and whether pollAutoSyncLists() below should
  // keep re-syncing it automatically. Also defensively created PHP-side
  // (MailerliteController::ensureMailerliteListSyncTable) so neither side
  // hard-depends on the other having started first.
  await settingsPool.execute(`
    CREATE TABLE IF NOT EXISTS mailerlite_list_sync (
      id INT PRIMARY KEY AUTO_INCREMENT,
      listId INT NOT NULL UNIQUE,
      listName VARCHAR(255) NOT NULL,
      groupName VARCHAR(255) NOT NULL,
      groupId VARCHAR(64) NULL,
      autoSync TINYINT(1) NOT NULL DEFAULT 0,
      lastSyncedAt DATETIME NULL,
      lastSyncCount INT NULL,
      createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  `);
}

// The MailerLite API key actually in effect: a key saved through the admin
// UI (stored in integration_settings) always overrides the .env value, so
// updating your MailerLite account from the "MailerLite Configuration" page
// takes effect immediately, with no container restart.
async function getMailerliteApiKey() {
  if (settingsPool) {
    try {
      const [rows] = await settingsPool.execute('SELECT mailerliteApiKey FROM integration_settings WHERE id = 1');
      // Once a row exists at all, the admin UI is authoritative — including
      // an explicit empty string from "Disconnect" (see
      // disconnectMailerlite()), which must override a still-present .env
      // value rather than silently falling back to it.
      if (rows[0]) return rows[0].mailerliteApiKey || '';
    } catch (err) {
      console.warn('integration: failed to read MailerLite API key from DB, falling back to env:', err.message);
    }
  }
  return MAILERLITE_API_KEY_ENV || '';
}

async function saveMailerliteApiKey(apiKey) {
  if (!settingsPool) throw new Error('Settings storage unavailable (DB not configured)');
  await settingsPool.execute(
    'INSERT INTO integration_settings (id, mailerliteApiKey) VALUES (1, ?) ' +
    'ON DUPLICATE KEY UPDATE mailerliteApiKey = VALUES(mailerliteApiKey)',
    [apiKey]
  );
}

// "Disconnect": stores an explicit empty key rather than deleting the row,
// so it overrides a still-present .env value too — otherwise
// getMailerliteApiKey() would just silently fall back to .env and nothing
// would actually appear disconnected.
async function disconnectMailerlite() {
  if (!settingsPool) throw new Error('Settings storage unavailable (DB not configured)');
  await settingsPool.execute(
    'INSERT INTO integration_settings (id, mailerliteApiKey) VALUES (1, \'\') ' +
    'ON DUPLICATE KEY UPDATE mailerliteApiKey = \'\''
  );
}

if (!INTEGRATION_SHARED_SECRET) {
  console.warn(
    'integration: INTEGRATION_SHARED_SECRET is not set — every webhook/trigger route ' +
    'below will reject all requests until it is. This is deliberate: these routes are ' +
    'public (see Caddyfile) and one of them (trigger/mailerlite-email) sends a real ' +
    'email, so failing closed beats failing open.'
  );
}

// These routes are reachable from the open internet (Caddy proxies
// /webhooks/*, /sync/*, /trigger/* publicly, since MailerLite's webhook and
// any external trigger caller need to reach them). Without this check,
// anyone who finds the URL could forge MailerLite events (fake Contacts,
// forged Action log entries, silent unsubscribes) or worse, make
// /trigger/mailerlite-email send a real email to any address they choose,
// using this account's own paid quota. MailerLite's webhook config only
// accepts a plain URL (no custom headers), so the secret travels as a
// query param for one consistent mechanism — set it as the last part of the
// webhook URL you paste into MailerLite's dashboard, e.g.
// ".../webhooks/mailerlite?secret=<value>".
function requireSharedSecret(req, res, next) {
  // Public-facing callers (MailerLite's webhook config, which only accepts
  // a plain URL) use ?secret=..., internal server-to-server callers
  // (X2CRM's PHP, for the /admin/* routes below) use a header instead.
  const provided = String(req.query.secret || req.get('X-Integration-Secret') || '');
  const expected = String(INTEGRATION_SHARED_SECRET || '');
  const ok = expected.length > 0
    && provided.length === expected.length
    && crypto.timingSafeEqual(Buffer.from(provided), Buffer.from(expected));
  if (!ok) {
    return res.status(401).json({ ok: false, error: 'missing or invalid secret' });
  }
  next();
}

// X2CRM's API uses HTTP Basic auth: base64(username:apiKey) — not a
// bearer token. axios's `auth` option handles the encoding for us.
const x2crmAuth = { username: X2CRM_API_USERNAME, password: X2CRM_API_KEY };

// ---------- X2CRM helpers ----------
// CONFIRMED via live testing against a real X2CRM 7.1 instance:
//   - GET  index.php/api2/Contacts          (no .json) → array, for listing/filtering
//   - POST index.php/api2/Contacts          (no .json) → create
//   - PUT  index.php/api2/Contacts/{id}.json (.json)   → update a specific record (dict)
// There's no built-in "upsert" endpoint, so this does a lookup-by-email/
// phone first via a filtered GET, then creates or updates accordingly.
function normalizeX2crmContact(contact) {
  const firstName = String(contact.firstName || contact.name || '').trim();
  const lastName = String(contact.lastName || 'Unknown').trim();
  const email = String(contact.email || '').trim();
  const phone = String(contact.phone || '').trim();
  const visibility = contact.visibility ?? 1;
  const payload = {
    firstName,
    lastName,
    visibility,
    ...contact,
  };
  if (email) payload.email = email;
  if (phone) payload.phone = phone;
  if (!payload.name) payload.name = [firstName, lastName].filter(Boolean).join(' ').trim() || email || phone || 'Unknown';
  return payload;
}

async function upsertX2crmContact(contact) {
  const normalizedContact = normalizeX2crmContact(contact);
  const lookupValue = normalizedContact.email || normalizedContact.phone;
  const lookupField = normalizedContact.email ? 'email' : 'phone';
  try {
    const existing = await axios.get(
      `${X2CRM_API_URL}/Contacts`,
      { params: { [lookupField]: lookupValue }, auth: x2crmAuth }
    );
    const existingId = existing.data?.[0]?.id;
    if (existingId) {
      await axios.put(`${X2CRM_API_URL}/Contacts/${existingId}.json`, normalizedContact, { auth: x2crmAuth });
      return existingId;
    }
  } catch (err) {
    // fall through to create if lookup fails/finds nothing
  }
  try {
    const created = await axios.post(`${X2CRM_API_URL}/Contacts`, normalizedContact, { auth: x2crmAuth });
    return created.data?.id;
  } catch (err) {
    console.error('X2CRM contact create error:', err.response?.status, JSON.stringify(err.response?.data));
    throw err;
  }
}

// Writes to X2CRM's Actions module — the same module used for logged
// calls/tasks/notes, so these show up directly in the contact's Actions
// tab/timeline inside the CRM. This is what makes engagement data
// ("they opened this email", "they clicked a link") visible to anyone
// browsing the contact record in X2CRM itself, not just in MailerLite's
// own dashboard.
async function logX2crmAction(contactId, { type, description, complete = true }) {
  if (!contactId) return; // nothing to attach the activity to
  try {
    return await axios.post(
      `${X2CRM_API_URL}/Actions`,
      {
        associationId: contactId,
        associationType: 'Contacts',
        type,                // e.g. 'WhatsApp', 'Email Open', 'Email Click', 'Unsubscribe'
        subject: description,
        actionDescription: description,
        complete,
        dueDate: new Date().toISOString(),
      },
      { auth: x2crmAuth }
    );
  } catch (err) {
    console.error('X2CRM action create error:', err.response?.status, JSON.stringify(err.response?.data));
    throw err;
  }
}

function logEmailEvent(contactId, direction, summary) {
  // direction: 'OUT' (a campaign/automation email was sent to them) or
  // 'IN' (they engaged — click, bounce, unsubscribe, etc)
  return logX2crmAction(contactId, {
    type: 'Email',
    description: `[Email ${direction}] ${summary}`,
  });
}

// ---------- MailerLite helpers ----------
async function upsertMailerliteSubscriber(email, fields = {}, groupId) {
  const apiKey = await getMailerliteApiKey();
  const sub = await axios.post(
    `${MAILERLITE_API_URL}/subscribers`,
    { email, fields },
    { headers: { Authorization: `Bearer ${apiKey}` } }
  );
  if (groupId) {
    await axios.post(
      `${MAILERLITE_API_URL}/subscribers/${sub.data.data.id}/groups/${groupId}`,
      {},
      { headers: { Authorization: `Bearer ${apiKey}` } }
    );
  }
  return sub;
}

// Finds a MailerLite group by exact name, creating it if it doesn't exist
// yet — used so "sync this X2CRM list" always lands in the same
// predictably-named group on repeat syncs instead of creating duplicates.
async function findOrCreateMailerliteGroup(groupName) {
  const apiKey = await getMailerliteApiKey();
  const existing = await axios.get(`${MAILERLITE_API_URL}/groups`, {
    // Literal bracket key, not a nested object — MailerLite's filter param
    // is "filter[name]", and axios's default serializer won't produce that
    // bracket form from a nested params object.
    params: { limit: 200, 'filter[name]': groupName },
    headers: { Authorization: `Bearer ${apiKey}` },
  });
  const match = (existing.data?.data || []).find((g) => g.name === groupName);
  if (match) return match.id;

  const created = await axios.post(
    `${MAILERLITE_API_URL}/groups`,
    { name: groupName },
    { headers: { Authorization: `Bearer ${apiKey}` } }
  );
  return created.data?.data?.id;
}

// Deletes a MailerLite group entirely — used by "Remove from MailerLite" on
// the synced-lists table. Per MailerLite's own docs this removes the group
// and its membership associations; it does not document whether the
// subscribers themselves are also deleted, so the admin UI is worded as
// "removes the group" rather than promising full subscriber deletion.
async function deleteMailerliteGroup(groupId) {
  const apiKey = await getMailerliteApiKey();
  await axios.delete(`${MAILERLITE_API_URL}/groups/${groupId}`, {
    headers: { Authorization: `Bearer ${apiKey}` },
  });
}

// Shared by /admin/sync-contacts-to-group and /admin/schedule-campaign: both
// need "make sure this group exists and has exactly these people in it"
// before doing anything else. A per-contact failure doesn't abort the rest
// of the list — one bad email shouldn't block everyone else.
async function syncContactsToGroup(groupName, contacts) {
  const groupId = await findOrCreateMailerliteGroup(groupName);
  let synced = 0;
  const failed = [];
  for (const contact of contacts) {
    try {
      await upsertMailerliteSubscriber(contact.email, { name: contact.name || '' }, groupId);
      synced++;
    } catch (err) {
      failed.push({ email: contact.email, error: err.response?.data?.message || err.message });
    }
  }
  return { groupId, synced, failed };
}

// Creates a MailerLite "regular" campaign targeting the given group and
// schedules it for a future date/time. MailerLite has no per-contact
// targeting or recurrence in its API (confirmed against their docs) — a
// group is the smallest addressable audience, and this is a one-time send
// only; there is no "repeat" concept to wire up here.
async function createAndScheduleCampaign({ groupId, name, subject, fromName, fromEmail, html, scheduleDate, scheduleHours, scheduleMinutes }) {
  const apiKey = await getMailerliteApiKey();
  const created = await axios.post(
    `${MAILERLITE_API_URL}/campaigns`,
    {
      name,
      type: 'regular',
      emails: [{
        subject,
        from_name: fromName,
        from: fromEmail,
        content: html,
      }],
      groups: [groupId],
    },
    { headers: { Authorization: `Bearer ${apiKey}` } }
  );
  const campaignId = created.data?.data?.id;

  await axios.post(
    `${MAILERLITE_API_URL}/campaigns/${campaignId}/schedule`,
    {
      delivery: 'scheduled',
      schedule: { date: scheduleDate, hours: scheduleHours, minutes: scheduleMinutes },
    },
    { headers: { Authorization: `Bearer ${apiKey}` } }
  );

  return campaignId;
}

// The webhook payload for campaign events usually only gives a campaign
// id/name, not the actual subject/body — fetch those separately so what
// gets logged in X2CRM is the real content, not just "a campaign fired."
// Small in-memory cache since the same campaign fires this webhook once
// per recipient, and refetching per-recipient would be wasteful.
const campaignCache = new Map();
async function getMailerliteCampaignSummary(campaignId) {
  if (!campaignId) return null;
  if (campaignCache.has(campaignId)) return campaignCache.get(campaignId);
  try {
    const resp = await axios.get(
      `${MAILERLITE_API_URL}/campaigns/${campaignId}`,
      { headers: { Authorization: `Bearer ${await getMailerliteApiKey()}` } }
    );
    const c = resp.data?.data || {};
    const subject = c.emails?.[0]?.subject || c.name || '(unknown subject)';
    // MailerLite returns full HTML content; keep only a short plain-text
    // preview for the CRM log rather than dumping raw HTML into a note.
    const rawHtml = c.emails?.[0]?.content || '';
    const preview = rawHtml.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
    const summary = { subject, preview };
    campaignCache.set(campaignId, summary);
    return summary;
  } catch (err) {
    console.error('getMailerliteCampaignSummary error:', err.message);
    return null;
  }
}

// ---------- Routes ----------

// MailerLite webhook: fires on subscriber/campaign/automation activity.
// Configure this URL under MailerLite > Integrations > Webhooks, and
// enable the event types you want (subscriber created/updated, campaign
// sent, link clicked, unsubscribed, etc — MailerLite sends a `type` field
// identifying which one fired).
app.post('/webhooks/mailerlite', requireSharedSecret, async (req, res) => {
  try {
    const events = Array.isArray(req.body) ? req.body : [req.body];
    for (const evt of events) {
      const email = evt?.data?.subscriber?.email;
      if (!email) continue;

      const contactId = await upsertX2crmContact({
        email,
        firstName: evt?.data?.subscriber?.fields?.name || '',
        source: 'MailerLite',
      });

      const eventType = evt.type || 'subscriber.updated';
      const campaignId = evt?.data?.campaign?.id;
      const campaign = campaignId ? await getMailerliteCampaignSummary(campaignId) : null;

      if (eventType.includes('sent') || eventType.includes('campaign.sent')) {
        // A campaign email actually went out to this contact — log the
        // real subject + a text preview, not just "campaign sent."
        const summary = campaign
          ? `"${campaign.subject}" — ${campaign.preview}`
          : (evt?.data?.campaign?.name || '(campaign name unavailable)');
        await logEmailEvent(contactId, 'OUT', summary);
      } else if (eventType.includes('click')) {
        const subjectNote = campaign ? ` (from "${campaign.subject}")` : '';
        await logEmailEvent(contactId, 'IN', `clicked ${evt?.data?.url || 'a link'}${subjectNote}`);
      } else if (eventType.includes('bounce')) {
        await logEmailEvent(contactId, 'IN', 'email bounced');
      } else if (eventType.includes('unsubscribe')) {
        await logEmailEvent(contactId, 'IN', 'unsubscribed');
        // doNotEmail is the real x2_contacts column (confirmed against this
        // instance's schema) — emailOptOut/source aren't real X2CRM fields,
        // so upsertX2crmContact's spread-through payload silently dropped
        // them before, meaning unsubscribes never actually flipped the
        // contact's do-not-email flag despite being logged as an Action.
        await upsertX2crmContact({ email, doNotEmail: true });
      } else {
        // subscriber created/updated/group-joined etc — lighter-weight log
        await logEmailEvent(contactId, 'IN', `[${eventType}]`);
      }
    }
    res.sendStatus(200);
  } catch (err) {
    console.error('MailerLite webhook error:', err.message);
    res.sendStatus(200);
  }
});

// Example outbound trigger: call this from an X2Flow action or a cron job
// whenever a new X2CRM lead should be pushed to MailerLite.
app.post('/sync/new-lead', requireSharedSecret, async (req, res) => {
  try {
    const { email, name, mailerliteGroupId } = req.body;
    if (email) await upsertMailerliteSubscriber(email, { name }, mailerliteGroupId);
    res.json({ ok: true });
  } catch (err) {
    console.error('sync/new-lead error:', err.message);
    res.status(500).json({ ok: false, error: err.message });
  }
});

// Trigger a MailerLite automation-driven email from an X2CRM workflow.
// MailerLite has no "send one email right now" API — automations fire
// off triggers like "joins a group" or "custom field updated". So this
// adds the contact to a group (or bumps a field) that you've already
// wired to a Send Email step inside a MailerLite Automation.
app.post('/trigger/mailerlite-email', requireSharedSecret, async (req, res) => {
  try {
    const { email, name, groupId, fieldUpdates, automationLabel, emailSubjectPreview } = req.body;
    if (!email) return res.status(400).json({ ok: false, error: 'email required' });
    await upsertMailerliteSubscriber(email, { name, ...fieldUpdates }, groupId);
    const contactId = await upsertX2crmContact({ email, firstName: name });
    // The actual send happens asynchronously inside MailerLite once the
    // automation trigger fires, so we can't fetch real content yet at
    // this point — if you know what the automation sends (you built it),
    // pass emailSubjectPreview and it gets logged now; otherwise the
    // /webhooks/mailerlite campaign.sent event will log the real content
    // once MailerLite actually sends it.
    await logEmailEvent(
      contactId,
      'OUT',
      emailSubjectPreview
        ? `queued: "${emailSubjectPreview}"`
        : `queued MailerLite automation${automationLabel ? ` "${automationLabel}"` : ''} (content will log when sent)`
    );
    res.json({ ok: true });
  } catch (err) {
    console.error('trigger/mailerlite-email error:', err.message);
    res.status(500).json({ ok: false, error: err.message });
  }
});

app.get('/health', (req, res) => res.json({ status: 'ok' }));

// ---------- Admin routes (server-to-server only, called from X2CRM's PHP
// MailerliteController — not proxied publicly by Caddy) ----------

// Confirms MAILERLITE_API_KEY is actually valid, for the "Configuration"
// admin page's connection-status display.
app.get('/admin/mailerlite-status', requireSharedSecret, async (req, res) => {
  const apiKey = await getMailerliteApiKey();
  if (!apiKey) {
    return res.json({ ok: true, configured: false, connected: false });
  }
  // Last 4 characters only — enough for the admin to recognize "yes, that's
  // the key I meant" without the full secret ever round-tripping back to
  // the browser once it's been saved.
  const keySuffix = apiKey.length > 4 ? apiKey.slice(-4) : '';
  try {
    // Not in MailerLite's published API docs, but a real, working endpoint —
    // confirmed by calling it directly against a live account. Used purely
    // to show which MailerLite account this key belongs to; if MailerLite
    // ever removes it, the catch below still reports "connected" from the
    // /groups call succeeding, just without the account name/email.
    let accountName = null;
    let accountEmail = null;
    try {
      const accountResp = await axios.get(`${MAILERLITE_API_URL}/account`, {
        headers: { Authorization: `Bearer ${apiKey}` },
      });
      accountName = accountResp.data?.data?.name || null;
      accountEmail = accountResp.data?.data?.sender_email || null;
    } catch (accountErr) {
      console.warn('integration: could not fetch MailerLite account info:', accountErr.message);
    }

    await axios.get(`${MAILERLITE_API_URL}/groups`, {
      params: { limit: 1 },
      headers: { Authorization: `Bearer ${apiKey}` },
    });
    res.json({ ok: true, configured: true, connected: true, keySuffix, accountName, accountEmail });
  } catch (err) {
    res.json({
      ok: true, configured: true, connected: false, keySuffix,
      error: err.response?.data?.message || err.message,
    });
  }
});

// Saves/updates the MailerLite API key from the admin UI — takes effect on
// the very next MailerLite API call, no restart needed (see
// getMailerliteApiKey()).
app.post('/admin/mailerlite-api-key', requireSharedSecret, async (req, res) => {
  try {
    const apiKey = String((req.body || {}).apiKey || '').trim();
    if (!apiKey) {
      return res.status(400).json({ ok: false, error: 'apiKey is required' });
    }
    await saveMailerliteApiKey(apiKey);
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ ok: false, error: err.message });
  }
});

// Clears the stored API key so the integration stops being able to reach
// MailerLite until a new key is saved. Background pollers (contact sync,
// auto-sync lists) just fail quietly and log a warning when there's no
// key — nothing crashes, they simply resume once reconnected.
app.post('/admin/mailerlite-disconnect', requireSharedSecret, async (req, res) => {
  try {
    await disconnectMailerlite();
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ ok: false, error: err.message });
  }
});

// Syncs a resolved list of {email, name} contacts (X2CRM has already
// resolved list membership PHP-side) into a MailerLite group, creating the
// group if it doesn't already exist. A per-contact failure doesn't abort
// the whole sync — one bad email shouldn't block the rest of the list.
app.post('/admin/sync-contacts-to-group', requireSharedSecret, async (req, res) => {
  try {
    const { groupName, contacts } = req.body || {};
    if (!groupName || !Array.isArray(contacts) || contacts.length === 0) {
      return res.status(400).json({ ok: false, error: 'groupName and a non-empty contacts array are required' });
    }
    const result = await syncContactsToGroup(groupName, contacts);
    res.json({ ok: true, ...result });
  } catch (err) {
    console.error('admin/sync-contacts-to-group error:', err.message);
    res.status(500).json({ ok: false, error: err.message });
  }
});

// One-time scheduled campaign: syncs the (already list-resolved, PHP-side)
// contacts into a group, then creates and schedules a MailerLite campaign
// against that group for the given future date/time. See
// createAndScheduleCampaign() for why there's no "repeat" option — MailerLite
// doesn't have one, and faking it would mean X2CRM owning a whole recurrence
// engine, deliberately out of scope for this first pass.
app.post('/admin/schedule-campaign', requireSharedSecret, async (req, res) => {
  try {
    const { groupName, contacts, campaign } = req.body || {};
    if (!groupName || !Array.isArray(contacts) || contacts.length === 0) {
      return res.status(400).json({ ok: false, error: 'groupName and a non-empty contacts array are required' });
    }
    if (!campaign || !campaign.name || !campaign.subject || !campaign.fromEmail || !campaign.html || !campaign.scheduleDate) {
      return res.status(400).json({ ok: false, error: 'campaign name, subject, fromEmail, html, and scheduleDate are required' });
    }

    const syncResult = await syncContactsToGroup(groupName, contacts);
    if (syncResult.synced === 0) {
      return res.status(400).json({ ok: false, error: 'No contacts could be synced to MailerLite; nothing to schedule.' });
    }

    const campaignId = await createAndScheduleCampaign({
      groupId: syncResult.groupId,
      name: campaign.name,
      subject: campaign.subject,
      fromName: campaign.fromName || campaign.name,
      fromEmail: campaign.fromEmail,
      html: campaign.html,
      scheduleDate: campaign.scheduleDate,
      scheduleHours: campaign.scheduleHours,
      scheduleMinutes: campaign.scheduleMinutes,
    });

    res.json({ ok: true, campaignId, synced: syncResult.synced, failed: syncResult.failed });
  } catch (err) {
    console.error('admin/schedule-campaign error:', err.response?.data || err.message);
    res.status(500).json({ ok: false, error: err.response?.data?.message || err.message });
  }
});

// Deletes a MailerLite group (see deleteMailerliteGroup() for what this
// does and doesn't guarantee about the subscribers in it).
app.post('/admin/delete-mailerlite-group', requireSharedSecret, async (req, res) => {
  try {
    const { groupId } = req.body || {};
    if (!groupId) return res.status(400).json({ ok: false, error: 'groupId is required' });
    await deleteMailerliteGroup(groupId);
    res.json({ ok: true });
  } catch (err) {
    console.error('admin/delete-mailerlite-group error:', err.response?.data || err.message);
    res.status(500).json({ ok: false, error: err.response?.data?.message || err.message });
  }
});

// ---------- Primary sync engine: DB polling ----------
// This — not X2Flow — is the primary mechanism keeping MailerLite in sync
// with X2CRM. It runs continuously in the background with zero manual
// configuration inside X2CRM's admin UI, which matters because X2Flow's
// automation UI varies between versions and is easy to misconfigure.
// X2Flow (or /sync/new-lead) is still useful for instant, event-driven
// actions like "add them to a group the second this form is submitted" —
// but routine "stay in sync" doesn't depend on it being set up correctly.
//
// EMAIL_FIELDS lets you tell the poller which columns on your X2CRM
// contacts table hold email data, since schemas vary by version and by
// what custom fields you've added — comma-separated, first one listed is
// treated as primary.
//
// CUSTOM_FIELD_MAP handles everything else: any other custom field you
// add in X2CRM (lead source, tier, industry, whatever) can be synced to
// MailerLite as a subscriber custom field just by adding an entry here —
// no code changes needed. Format is "x2crmColumn:destinationFieldName"
// pairs, comma-separated, e.g.
//   CUSTOM_FIELD_MAP=leadSource:lead_source,accountTier:tier
const { buildContactColumnList, getOptOutColumn } = require('./lib/contact-sync-schema');
const POLL_INTERVAL_MS = Number(process.env.POLL_INTERVAL_MS || 60000);
const EMAIL_FIELDS = (process.env.EMAIL_FIELDS || 'email').split(',').map(s => s.trim());
const CUSTOM_FIELD_MAP = (process.env.CUSTOM_FIELD_MAP || '')
  .split(',')
  .map(s => s.trim())
  .filter(Boolean)
  .map(pair => {
    const [x2crmColumn, destField] = pair.split(':').map(s => s.trim());
    return { x2crmColumn, destField: destField || x2crmColumn };
  });
let lastPolledAt = new Date(Date.now() - POLL_INTERVAL_MS);

function extractValues(row, fields) {
  // Collects non-empty values across all configured columns, de-duped —
  // this is how a contact with, say, `email` and `alternateEmail` both
  // filled in ends up with two addresses synced instead of just one.
  const values = fields.map(f => row[f]).filter(v => v && String(v).trim());
  return [...new Set(values)];
}

function extractCustomFields(row) {
  const out = {};
  for (const { x2crmColumn, destField } of CUSTOM_FIELD_MAP) {
    if (row[x2crmColumn] !== undefined && row[x2crmColumn] !== null && row[x2crmColumn] !== '') {
      out[destField] = row[x2crmColumn];
    }
  }
  return out;
}

async function pollForChangedContacts() {
  if (!process.env.DB_HOST) return; // skip if DB creds not wired to this service
  let conn;
  try {
    conn = await mysql.createConnection({
      host: process.env.DB_HOST,
      user: process.env.DB_USER,
      password: process.env.DB_PASSWORD,
      database: process.env.DB_NAME,
    });

    const [columnRows] = await conn.query('SHOW COLUMNS FROM x2_contacts');
    const availableColumns = columnRows.map((row) => row.Field);
    const optOutColumn = getOptOutColumn(availableColumns);
    const columns = buildContactColumnList(
      [
        'id', 'firstName', 'lastUpdated', 'createDate',
        ...EMAIL_FIELDS,
        ...CUSTOM_FIELD_MAP.map(m => m.x2crmColumn),
      ],
      availableColumns
    );
    const selectColumns = [...new Set([...(optOutColumn ? [optOutColumn] : []), ...columns])];
    const changeTrackingColumn = availableColumns.includes('lastUpdated') ? 'lastUpdated' : 'createDate';
    const sincePoll = Math.floor(lastPolledAt.getTime() / 1000);

    // Adjust the table name to match your X2CRM schema version (commonly
    // `x2_contacts`), and confirm `lastUpdated` is the right change-
    // tracking column for your version.
    const [rows] = await conn.execute(
      `SELECT ${selectColumns.join(', ')} FROM x2_contacts WHERE ${changeTrackingColumn} > ?`,
      [sincePoll]
    );

    // Contacts brand new since the last poll (not just edited) additionally
    // get dropped into a fixed MailerLite group, so a MailerLite Automation
    // with a "subscriber_joins_group" trigger — configured on MailerLite's
    // own dashboard, since their API can't create/configure automations —
    // can welcome-email them automatically. Resolved once per poll cycle
    // (not per contact) to avoid a redundant group lookup for every row.
    const newContacts = [];

    for (const row of rows) {
      const emails = extractValues(row, EMAIL_FIELDS);
      const primaryEmail = emails[0];
      const customFields = extractCustomFields(row);

      const isOptedOut = optOutColumn ? Boolean(row[optOutColumn]) : false;

      // MailerLite: a subscriber record is keyed by exactly one email
      // address, so a contact with multiple emails becomes multiple
      // MailerLite subscribers — each tagged with the shared X2CRM
      // contact id so you can tell they're the same person. Skip
      // entirely if opted out.
      if (!isOptedOut) {
        for (const email of emails) {
          await upsertMailerliteSubscriber(email, {
            name: row.firstName,
            x2crm_contact_id: row.id,
            is_primary_email: email === primaryEmail ? 'yes' : 'no',
            ...customFields,
          });
        }
      }

      const isNewContact = row.createDate && Number(row.createDate) > sincePoll;
      if (isNewContact && primaryEmail && !isOptedOut && NEW_CONTACT_MAILERLITE_GROUP) {
        newContacts.push({ email: primaryEmail, name: row.firstName || '' });
      }
    }

    if (newContacts.length > 0) {
      try {
        const groupId = await findOrCreateMailerliteGroup(NEW_CONTACT_MAILERLITE_GROUP);
        for (const nc of newContacts) {
          try {
            await upsertMailerliteSubscriber(nc.email, { name: nc.name }, groupId);
          } catch (err) {
            console.warn(`integration: failed to add new contact ${nc.email} to "${NEW_CONTACT_MAILERLITE_GROUP}":`, err.message);
          }
        }
      } catch (err) {
        console.warn(`integration: failed to resolve MailerLite group "${NEW_CONTACT_MAILERLITE_GROUP}":`, err.message);
      }
    }

    lastPolledAt = new Date();
  } catch (err) {
    console.error('Poll error:', err.message);
  } finally {
    if (conn) await conn.end();
  }
}
setInterval(pollForChangedContacts, POLL_INTERVAL_MS);

// ---------- Auto-sync for MailerLite-linked Contacts lists ----------
// A Contacts list's membership can change after its one-time "Sync to
// MailerLite" click (new matches for a dynamic list, people added/removed
// from a static one) — this keeps any list with autoSync enabled current,
// on its own slower interval since re-resolving list criteria + pushing a
// whole list's worth of subscribers is heavier than syncing one changed
// contact. List membership itself can only be resolved X2CRM/PHP-side
// (that's where X2List's query-criteria logic lives), so this calls back
// into MailerliteController::actionResolveListMembers over the internal
// docker network — the reverse direction of every other call in this file.
const LIST_AUTO_SYNC_INTERVAL_MS = Number(process.env.LIST_AUTO_SYNC_INTERVAL_MS || 5 * 60 * 1000);
const X2CRM_INTERNAL_URL = 'http://x2crm:80';

async function pollAutoSyncLists() {
  if (!settingsPool) return;
  try {
    const [lists] = await settingsPool.execute('SELECT * FROM mailerlite_list_sync WHERE autoSync = 1');
    for (const list of lists) {
      try {
        const resp = await axios.get(`${X2CRM_INTERNAL_URL}/index.php/mailerlite/mailerlite/resolveListMembers`, {
          params: { listId: list.listId, secret: INTEGRATION_SHARED_SECRET },
        });
        if (!resp.data?.ok) {
          console.warn(`integration: could not resolve members for list ${list.listId}:`, resp.data?.error);
          continue;
        }
        const subscribers = resp.data.subscribers || [];
        if (subscribers.length === 0) continue;

        const result = await syncContactsToGroup(list.groupName, subscribers);
        await settingsPool.execute(
          'UPDATE mailerlite_list_sync SET lastSyncedAt = NOW(), lastSyncCount = ? WHERE id = ?',
          [result.synced, list.id]
        );
      } catch (err) {
        console.warn(`integration: auto-sync failed for list ${list.listId}:`, err.message);
      }
    }
  } catch (err) {
    console.error('integration: pollAutoSyncLists failed:', err.message);
  }
}
setInterval(pollAutoSyncLists, LIST_AUTO_SYNC_INTERVAL_MS);

ensureIntegrationSettingsTable()
  .then(() => console.log('integration: settings table ready'))
  .catch((err) => console.warn('integration: could not set up settings table (MailerLite API key must come from .env only):', err.message))
  .finally(() => {
    app.listen(PORT, () => console.log(`Integration middleware listening on :${PORT}`));
  });
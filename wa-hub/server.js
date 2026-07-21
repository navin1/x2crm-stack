require('dotenv').config();
const express = require('express');
const axios = require('axios');
const morgan = require('morgan');
const rateLimit = require('express-rate-limit');
const { default: makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion } = require('@whiskeysockets/baileys');
const qrcode = require('qrcode-terminal');
const QRCode = require('qrcode');

process.on('unhandledRejection', (reason) => {
  console.error('wa-hub: unhandled rejection:', reason && reason.message || reason);
});

const app = express();
app.use(express.json());
app.use(morgan('tiny'));

const {
  PORT = 3001,
  X2CRM_API_URL,
  X2CRM_API_USERNAME,
  X2CRM_API_KEY,
  DB_HOST = 'db',
  DB_NAME = 'x2crm',
  DB_USER = 'root',
  DB_PASSWORD = '',
  WA_RETENTION_DAYS = '90',
  WA_ADMIN_TOKEN = 'changeme',
  WA_ADMIN_USERS = 'admin:changeme',
  WA_SESSION_DIR = './auth_info_baileys',
  WA_DEBUG = 'false',
} = process.env;

// Note: WA_ADMIN_USERS still used to seed DB at startup; auth uses DB hashes

if (!X2CRM_API_URL || !X2CRM_API_USERNAME || !X2CRM_API_KEY) {
  console.warn('X2CRM credentials missing in env; wa-hub will not function until configured');
}

const x2crmAuth = { username: X2CRM_API_USERNAME, password: X2CRM_API_KEY };

// MySQL connection for persisting wa messages
const mysql = require('mysql2/promise');
const crypto = require('crypto');
let dbPool;
let sock = null;
let isConnecting = false;
// Tracks real connection state from the 'connection.update' event itself.
// sock.ws.readyState is NOT reliable here: Baileys wraps the raw socket in
// its own WebSocketClient class which doesn't expose a standard readyState
// (it's always `undefined`), so every `sock.ws.readyState === 1` check in
// this file was silently always false, regardless of actual connectivity.
let isOpen = false;
// Latest unscanned QR string (raw, not yet rendered as an image) — set on
// the 'qr' connection.update event, cleared once actually connected. Lets
// /admin/qr serve it as a real scannable image over HTTP instead of only
// as ASCII art in the container's stdout logs (which needs server/SSH
// access — awkward for pairing in a production deployment).
let latestQr = null;

// Baileys WhatsApp socket management
async function initBaileysSocket() {
  if (sock && isOpen) {
    console.log('wa-hub: Baileys socket already connected');
    return sock;
  }

  if (isConnecting) {
    console.log('wa-hub: Baileys socket connection in progress, waiting...');
    let attempts = 0;
    while (isConnecting && attempts < 60) {
      await new Promise(r => setTimeout(r, 1000));
      attempts++;
    }
    if (sock && isOpen) return sock;
  }
  
  try {
    isConnecting = true;
    const { state, saveCreds } = await useMultiFileAuthState(WA_SESSION_DIR);

    // Baileys ships with a WhatsApp Web protocol version baked in at publish
    // time; WhatsApp deprecates old versions within weeks, and connecting
    // with a stale one fails the noise handshake before a QR is ever issued
    // (looks like an infinite "connected to WA" -> "Connection Failure"
    // loop, no QR, no useful error). Always ask WhatsApp what's current.
    const { version } = await fetchLatestBaileysVersion();

    const socketConfig = {
      auth: state,
      version,
      browser: ['wa-hub', 'Chrome', '5.0'],
      // Skip the full chat/media history sync on connect — we only need
      // group metadata, not chat history.
      syncFullHistory: false,
      defaultQueryTimeoutMs: 60000,
    };
    
    // Only add logger if debug is enabled
    if (WA_DEBUG === 'true') {
      try {
        const pino = require('pino');
        socketConfig.logger = pino();
      } catch (e) {
        console.warn('wa-hub: pino logger not available, continuing without debug logging');
      }
    }
    
    sock = makeWASocket(socketConfig);

    sock.ev.on('connection.update', async (update) => {
      const { connection, lastDisconnect, qr } = update;
      if (qr) {
        latestQr = qr;
        console.log('wa-hub: WhatsApp QR Code generated (scan with phone, or visit /admin/qr):');
        qrcode.generate(qr, { small: true });
      }
      if (connection === 'connecting') {
        console.log('wa-hub: Connecting to WhatsApp...');
      }
      if (connection === 'open') {
        isConnecting = false;
        isOpen = true;
        latestQr = null;
        console.log('wa-hub: WhatsApp connected successfully');
      }
      if (connection === 'close') {
        isConnecting = false;
        isOpen = false;
        const reason = new Error(lastDisconnect?.error)?.message;
        if (lastDisconnect?.error?.output?.statusCode !== DisconnectReason.loggedOut) {
          console.log('wa-hub: WhatsApp disconnected, reconnecting...', reason);
          setTimeout(initBaileysSocket, 5000);
        } else {
          console.log('wa-hub: WhatsApp logged out');
        }
      }
    });

    sock.ev.on('creds.update', saveCreds);

    return sock;
  } catch (err) {
    isConnecting = false;
    console.error('wa-hub: failed to initialize Baileys socket:', err.message || err);
    return null;
  }
}

// Group management functions

// The linked WhatsApp account is implicitly the group owner/creator —
// WhatsApp rejects groupCreate/groupParticipantsUpdate with "bad-request"
// if its own number is also passed in the participants list (e.g. because
// the CRM has a Contact record for the same person/number the bot account
// is linked to). Strip it out defensively rather than erroring.
function getOwnPhone() {
  // sock.user.id looks like "17603907974:59@s.whatsapp.net" — the ":59" is
  // a per-device suffix, not part of the phone number; strip it before the
  // digits-only clean or its digits (":59" -> "59") get appended to the
  // comparison value and the match against a cleaned participant number
  // (e.g. "17603907974") silently fails.
  if (!sock?.user?.id) return null;
  const jid = String(sock.user.id).split('@')[0].split(':')[0];
  return jid.replace(/\D/g, '');
}

function excludeOwnPhone(phoneNumbers) {
  const own = getOwnPhone();
  return own ? phoneNumbers.filter(p => String(p).replace(/\D/g, '') !== own) : phoneNumbers;
}

// Sends a WhatsApp text message, optionally with an attached image, to a
// given phone number. Passing null/omitted `toPhone` sends to the linked
// account's own number ("message yourself") — safe from the usual
// unsolicited-automated-messaging ban risk, since it's not contacting a
// third party at all.
async function sendWhatsAppMessage(toPhone, { text, imageBuffer, imageCaption } = {}) {
  if (!sock || !isOpen) {
    throw new Error('WhatsApp not connected');
  }
  const cleaned = toPhone ? String(toPhone).replace(/\D/g, '') : getOwnPhone();
  if (!cleaned) {
    throw new Error('No destination phone number available');
  }
  const jid = `${cleaned}@s.whatsapp.net`;

  if (imageBuffer) {
    await sock.sendMessage(jid, { image: imageBuffer, caption: imageCaption || text || '' });
  } else {
    await sock.sendMessage(jid, { text: text || '' });
  }
}

// Notifies the admin (message-to-self on the linked WhatsApp account) about
// a newly registered lead-capture form: its URL, a scannable QR code of
// that URL, and a shortened tinyurl.com link for easy sharing.
async function notifyAdminNewForm({ name, url }) {
  const qrBuffer = await QRCode.toBuffer(url, { type: 'png', width: 320, margin: 2 });

  let tinyUrl = null;
  try {
    // Deliberately not using axios's `params` option here: its default
    // query serializer leaves ':' unencoded (produces
    // "url=http:%2F%2F...", vs. the correct "url=http%3A%2F%2F...").
    // tinyurl.com's API rejects that malformed encoding with a 400, so the
    // URL is percent-encoded manually and inlined instead.
    const resp = await axios.get(
      'https://tinyurl.com/api-create.php?url=' + encodeURIComponent(url),
      { timeout: 8000 }
    );
    if (typeof resp.data === 'string' && resp.data.startsWith('http')) {
      tinyUrl = resp.data.trim();
    }
  } catch (e) {
    console.warn('wa-hub: tinyurl.com request failed, continuing without a short link:', e.message || e);
  }

  const lines = [
    `New lead form created: *${name}*`,
    '',
    url,
  ];
  if (tinyUrl) lines.push('', `Short link: ${tinyUrl}`);

  await sendWhatsAppMessage(null, {
    text: lines.join('\n'),
    imageBuffer: qrBuffer,
    imageCaption: lines.join('\n'),
  });

  return { tinyUrl };
}

// Polls for prospects who've submitted through a pracharak's personal
// lead form since it was last checked, and WhatsApps each pracharak
// about their own new leads. Deliberately polling-based rather than an
// instant hook into X2CRM's own submission flow: the actual POST hits
// X2CRM's core `contacts/contacts/weblead` action directly (not proxied
// through wa-hub), so there's no request of ours to hang a real-time
// notification off of without either modifying core X2CRM code or building
// a custom X2Flow action class — this reuses the same "poll on an
// interval" shape already used elsewhere in this stack (see
// integration/server.js's MailerLite sync) at the cost of the
// notification lagging up to one polling interval behind the real
// submission.
// Shared by both lead-form pollers below: WhatsApps `phone` about every
// x2_x2leads row tagged with `leadSource` created after `since`, and returns
// the new watermark to persist. Only ever advances past leads that were
// actually (successfully) notified — a send failure stops the loop without
// moving the watermark, so the failed lead gets retried next poll instead of
// silently skipped.
async function notifyNewLeadsSince({ leadSource, phone, formLabel, since, logParams = {} }) {
  let watermark = since;
  const [leads] = await dbPool.execute(
    'SELECT firstName, lastName, email, phone, company, title, backgroundInfo, createDate ' +
    'FROM x2_x2leads WHERE leadSource = ? AND createDate > ? ORDER BY createDate ASC',
    [leadSource, since]
  );

  for (const lead of leads) {
    const lines = [`New prospect from "${formLabel}":`, ''];
    const fullName = [lead.firstName, lead.lastName].filter(Boolean).join(' ');
    if (fullName) lines.push(`Name: ${fullName}`);
    if (lead.email) lines.push(`Email: ${lead.email}`);
    if (lead.phone) lines.push(`Phone: ${lead.phone}`);
    if (lead.company) lines.push(`Company: ${lead.company}`);
    if (lead.title) lines.push(`Title: ${lead.title}`);
    if (lead.backgroundInfo) lines.push(`Message: ${lead.backgroundInfo}`);

    try {
      await sendWhatsAppMessage(phone, { text: lines.join('\n') });
      await logAdminAction({ action: 'notify_new_prospect', params: { leadSource, ...logParams }, success: true });
      watermark = lead.createDate;
    } catch (e) {
      console.warn(`wa-hub: failed to notify for leadSource ${leadSource}:`, e.message || e);
      await logAdminAction({ action: 'notify_new_prospect', params: { leadSource, ...logParams }, success: false, error: e.message });
      break;
    }
  }

  return watermark;
}

// Polls for prospects who've submitted through a pracharak's personal
// lead form since it was last checked, and WhatsApps each pracharak
// about their own new leads. Deliberately polling-based rather than an
// instant hook into X2CRM's own submission flow: the actual POST hits
// X2CRM's core `contacts/contacts/weblead` action directly (not proxied
// through wa-hub), so there's no request of ours to hang a real-time
// notification off of without either modifying core X2CRM code or building
// a custom X2Flow action class — this reuses the same "poll on an
// interval" shape already used elsewhere in this stack (see
// integration/server.js's MailerLite sync) at the cost of the
// notification lagging up to one polling interval behind the real
// submission.
async function pollForNewProspects() {
  if (!dbPool) return;
  // Skip the whole cycle while WhatsApp isn't connected — sendWhatsAppMessage
  // fails immediately in that state, so without this guard every pending
  // lead gets a fresh "WhatsApp not connected" failure logged to
  // wa_admin_audit every 30s until reconnected (seen: 4000+ rows over a few
  // days from one stuck form). The watermark hasn't moved, so nothing is
  // lost — this just waits for a real connection before retrying.
  if (!sock || !isOpen) return;
  try {
    const [forms] = await dbPool.execute(
      'SELECT id, name, pracharakId, lastPolledAt FROM x2_custom_lead_forms WHERE pracharakId IS NOT NULL'
    );

    for (const form of forms) {
      const leadSource = `SalesForm-${form.id}`;
      const since = form.lastPolledAt || 0;

      // pracharakId is an x2_contacts.id — the pracharak roster is the
      // "Pracharak" Contact List, not a dedicated table (see
      // WhatsappGroupsController::getPracharakContacts).
      const [prRows] = await dbPool.execute(
        'SELECT firstName, lastName, phone FROM x2_contacts WHERE id = ? AND phone IS NOT NULL AND phone <> \'\'',
        [form.pracharakId]
      );
      const pr = prRows[0];
      if (!pr) continue; // contact gone, removed from the list, or has no phone — nothing to notify

      const watermark = await notifyNewLeadsSince({
        leadSource, phone: pr.phone, formLabel: form.name, since, logParams: { formId: form.id },
      });

      if (watermark !== since) {
        await dbPool.execute('UPDATE x2_custom_lead_forms SET lastPolledAt = ? WHERE id = ?', [watermark, form.id]);
      }
    }
  } catch (err) {
    console.warn('wa-hub: pollForNewProspects failed:', err.message || err);
  }
}

// Same idea as pollForNewProspects, but for X2CRM's native "Web Lead Form"
// builder (marketing/webleadForm) instead of this stack's custom
// per-pracharak forms: wa_webform_notify holds the admin's choice of which
// pracharak gets WhatsApped for a given x2_web_forms.id (set/changed from
// X2CRM's WhatsApp Groups > Web Form Notifications page). Like
// pollForNewProspects, pracharakId is an x2_contacts.id — the roster for
// both features is just the Contacts currently in the "Pracharak"
// Contact List (see WhatsappGroupsController::getPracharakContacts), not
// a dedicated pracharak table. The JOIN against x2_contacts deliberately
// excludes rows whose contact was deleted or removed from the list.
async function pollForNewWebFormLeads() {
  if (!dbPool) return;
  if (!sock || !isOpen) return;
  try {
    const [rows] = await dbPool.execute(
      `SELECT n.webFormId, n.lastPolledAt, f.name AS formName, f.leadSource, c.phone
       FROM wa_webform_notify n
       JOIN x2_web_forms f ON f.id = n.webFormId
       JOIN x2_contacts c ON c.id = n.pracharakId
       WHERE f.leadSource IS NOT NULL AND f.leadSource <> '' AND c.phone IS NOT NULL AND c.phone <> ''`
    );

    for (const row of rows) {
      const since = row.lastPolledAt || 0;
      const watermark = await notifyNewLeadsSince({
        leadSource: row.leadSource, phone: row.phone, formLabel: row.formName, since,
        logParams: { webFormId: row.webFormId },
      });

      if (watermark !== since) {
        await dbPool.execute('UPDATE wa_webform_notify SET lastPolledAt = ? WHERE webFormId = ?', [watermark, row.webFormId]);
      }
    }
  } catch (err) {
    console.warn('wa-hub: pollForNewWebFormLeads failed:', err.message || err);
  }
}

async function createWhatsAppGroup(groupName, participants = [], listId = null) {
  if (!sock || !isOpen) {
    throw new Error('WhatsApp not connected');
  }
  try {
    participants = excludeOwnPhone(participants);

    // participants: phone numbers like "1234567890@s.whatsapp.net"
    const participantIds = participants.map(p => {
      const cleaned = String(p).replace(/\D/g, '');
      return `${cleaned}@s.whatsapp.net`;
    });

    const group = await sock.groupCreate(groupName, participantIds);
    const groupId = group.id;

    // Store group in database
    await dbPool.execute(
      'INSERT INTO wa_groups (groupId, groupName, subject, isSynced, listId) VALUES (?, ?, ?, ?, ?)',
      [groupId, groupName, groupName, 1, listId || null]
    );

    // Store participants
    for (const phone of participants) {
      const cleaned = String(phone).replace(/\D/g, '');
      await dbPool.execute(
        'INSERT INTO wa_group_members (groupId, phone, name) SELECT id, ?, ? FROM wa_groups WHERE groupId = ?',
        [cleaned, cleaned, groupId]
      );
    }

    await logAdminAction({ action: 'create_group', params: { groupId, groupName, participantCount: participants.length, listId }, success: true });
    return { groupId, groupName, memberCount: participants.length };
  } catch (err) {
    console.error('wa-hub: failed to create group:', err.message || err);
    await logAdminAction({ action: 'create_group', params: { groupName }, success: false, error: err.message });
    throw err;
  }
}

// Reconciles a group's actual WhatsApp membership to match a desired phone
// list (computed CRM-side from a dynamic X2CRM list's live criteria) —
// adds phones missing from the group and removes phones no longer in the
// desired set, rather than a full clear-and-reinsert like syncGroupsFromWhatsApp.
async function syncGroupMembersToPhones(groupId, desiredPhones = []) {
  if (!sock || !isOpen) {
    throw new Error('WhatsApp not connected');
  }

  const desiredCleaned = new Set(desiredPhones.map(p => String(p).replace(/\D/g, '')).filter(Boolean));
  const current = await getGroupMembers(groupId);
  const currentPhones = new Set(current.map(m => m.phone));

  const toAdd = [...desiredCleaned].filter(p => !currentPhones.has(p));
  const toRemove = [...currentPhones].filter(p => !desiredCleaned.has(p));

  if (toAdd.length > 0) {
    await addMembersToGroup(groupId, toAdd);
  }
  for (const phone of toRemove) {
    await removeMemberFromGroup(groupId, phone);
  }

  await dbPool.execute(
    'UPDATE wa_groups SET lastSyncedAt = NOW() WHERE groupId = ?',
    [groupId]
  );

  await logAdminAction({ action: 'sync_group_members_to_list', params: { groupId, added: toAdd.length, removed: toRemove.length }, success: true });
  return { added: toAdd.length, removed: toRemove.length, total: desiredCleaned.size };
}

async function addMembersToGroup(groupId, phoneNumbers = []) {
  if (!sock || !isOpen) {
    throw new Error('WhatsApp not connected');
  }
  try {
    phoneNumbers = excludeOwnPhone(phoneNumbers);
    const participantIds = phoneNumbers.map(p => {
      const cleaned = String(p).replace(/\D/g, '');
      return `${cleaned}@s.whatsapp.net`;
    });
    
    await sock.groupParticipantsUpdate(groupId, participantIds, 'add');
    
    // Store in database
    for (const phone of phoneNumbers) {
      const cleaned = String(phone).replace(/\D/g, '');
      try {
        const [result] = await dbPool.execute(
          'INSERT INTO wa_group_members (groupId, phone, name) SELECT id, ?, ? FROM wa_groups WHERE groupId = ? ON DUPLICATE KEY UPDATE joinedAt = NOW()',
          [cleaned, cleaned, groupId]
        );
      } catch (e) {
        // Ignore duplicate key errors
      }
    }
    
    await logAdminAction({ action: 'add_group_members', params: { groupId, memberCount: phoneNumbers.length }, success: true });
    return { success: true, added: phoneNumbers.length };
  } catch (err) {
    console.error('wa-hub: failed to add members:', err.message || err);
    await logAdminAction({ action: 'add_group_members', params: { groupId }, success: false, error: err.message });
    throw err;
  }
}

async function removeMemberFromGroup(groupId, phone) {
  if (!sock || !isOpen) {
    throw new Error('WhatsApp not connected');
  }
  try {
    const cleaned = String(phone).replace(/\D/g, '');
    const participantId = `${cleaned}@s.whatsapp.net`;
    
    await sock.groupParticipantsUpdate(groupId, [participantId], 'remove');
    
    // Remove from database
    await dbPool.execute(
      'DELETE FROM wa_group_members WHERE groupId = (SELECT id FROM wa_groups WHERE groupId = ?) AND phone = ?',
      [groupId, cleaned]
    );
    
    await logAdminAction({ action: 'remove_group_member', params: { groupId, phone: cleaned }, success: true });
    return { success: true };
  } catch (err) {
    console.error('wa-hub: failed to remove member:', err.message || err);
    await logAdminAction({ action: 'remove_group_member', params: { groupId, phone }, success: false, error: err.message });
    throw err;
  }
}

async function renameGroup(groupId, newName) {
  if (!sock || !isOpen) {
    throw new Error('WhatsApp not connected');
  }
  try {
    await sock.groupUpdateSubject(groupId, newName);
    await dbPool.execute(
      'UPDATE wa_groups SET groupName = ?, subject = ? WHERE groupId = ?',
      [newName, newName, groupId]
    );
    await logAdminAction({ action: 'rename_group', params: { groupId, newName }, success: true });
    return { success: true };
  } catch (err) {
    console.error('wa-hub: failed to rename group:', err.message || err);
    await logAdminAction({ action: 'rename_group', params: { groupId }, success: false, error: err.message });
    throw err;
  }
}

// WhatsApp has no "delete for everyone" API for groups — the best an
// account can do is leave. If wa-hub's account is the only member (e.g. a
// test group), this effectively abandons/removes it; if real people are
// still in it, it keeps existing for them, just untracked by the CRM from
// here on (we drop our own DB rows regardless).
async function leaveAndDeleteGroup(groupId) {
  if (!sock || !isOpen) {
    throw new Error('WhatsApp not connected');
  }
  try {
    try {
      await sock.groupLeave(groupId);
    } catch (err) {
      // Already left / no longer a participant — fine, still clean up our records.
      console.warn('wa-hub: groupLeave failed (continuing to remove local records):', err.message || err);
    }
    const [groupRow] = await dbPool.execute('SELECT id FROM wa_groups WHERE groupId = ?', [groupId]);
    if (groupRow && groupRow[0]) {
      await dbPool.execute('DELETE FROM wa_group_members WHERE groupId = ?', [groupRow[0].id]);
    }
    await dbPool.execute('DELETE FROM wa_groups WHERE groupId = ?', [groupId]);
    await logAdminAction({ action: 'delete_group', params: { groupId }, success: true });
    return { success: true };
  } catch (err) {
    console.error('wa-hub: failed to delete group:', err.message || err);
    await logAdminAction({ action: 'delete_group', params: { groupId }, success: false, error: err.message });
    throw err;
  }
}

async function getGroupMembers(groupId) {
  try {
    const [members] = await dbPool.execute(
      'SELECT id, phone, name, isAdmin, joinedAt FROM wa_group_members WHERE groupId = (SELECT id FROM wa_groups WHERE groupId = ?) ORDER BY joinedAt ASC',
      [groupId]
    );
    return members || [];
  } catch (err) {
    console.error('wa-hub: failed to get group members:', err.message || err);
    throw err;
  }
}

async function syncGroupsFromWhatsApp() {
  if (!sock || !isOpen) {
    console.warn('wa-hub: WhatsApp not connected, cannot sync groups');
    return [];
  }
  
  try {
    const groups = await sock.groupFetchAllParticipating();
    const syncedGroups = [];
    
    for (const [groupJid, groupMetadata] of Object.entries(groups)) {
      const groupId = groupJid;
      const groupName = groupMetadata.subject || 'Unknown Group';
      
      // Upsert group
      await dbPool.execute(
        'INSERT INTO wa_groups (groupId, groupName, subject, isSynced, lastSyncedAt) VALUES (?, ?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE subject = ?, lastSyncedAt = NOW()',
        [groupId, groupName, groupName, groupName]
      );
      
      // Get internal group ID
      const [groupRow] = await dbPool.execute('SELECT id FROM wa_groups WHERE groupId = ?', [groupId]);
      if (!groupRow || !groupRow[0]) continue;
      
      const internalId = groupRow[0].id;
      
      // Clear and re-add members
      await dbPool.execute('DELETE FROM wa_group_members WHERE groupId = ?', [internalId]);
      
      for (const participant of groupMetadata.participants || []) {
        const phone = participant.id.replace('@s.whatsapp.net', '').replace(/\D/g, '');
        const isAdmin = ['admin', 'superadmin'].includes(participant.admin) ? 1 : 0;
        
        try {
          await dbPool.execute(
            'INSERT INTO wa_group_members (groupId, phone, name, isAdmin) VALUES (?, ?, ?, ?)',
            [internalId, phone, phone, isAdmin]
          );
        } catch (e) {
          // Ignore duplicates
        }
      }
      
      syncedGroups.push({ groupId, groupName, members: (groupMetadata.participants || []).length });
    }
    
    console.log(`wa-hub: synced ${syncedGroups.length} groups from WhatsApp`);
    return syncedGroups;
  } catch (err) {
    console.error('wa-hub: failed to sync groups:', err.message || err);
    return [];
  }
}
async function initDb() {
  try {
    dbPool = await mysql.createPool({
      host: DB_HOST,
      user: DB_USER,
      password: DB_PASSWORD,
      database: DB_NAME,
      waitForConnections: true,
      connectionLimit: 5,
    });

    // create messages table if not exists
    await dbPool.execute(`
      CREATE TABLE IF NOT EXISTS wa_messages (
        id BIGINT PRIMARY KEY AUTO_INCREMENT,
        contact_id INT NULL,
        phone VARCHAR(64) NULL,
        direction ENUM('in','out') NOT NULL,
        message TEXT,
        event_type VARCHAR(64),
        sent_at DATETIME NULL,
        received_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    `);
    // create admin audit table
    await dbPool.execute(`
      CREATE TABLE IF NOT EXISTS wa_admin_audit (
        id BIGINT PRIMARY KEY AUTO_INCREMENT,
        admin_user VARCHAR(128) NULL,
        ip VARCHAR(64) NULL,
        action VARCHAR(128) NOT NULL,
        params JSON NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    `);
    // create whatsapp groups table
    await dbPool.execute(`
      CREATE TABLE IF NOT EXISTS wa_groups (
        id INT PRIMARY KEY AUTO_INCREMENT,
        groupId VARCHAR(100) UNIQUE,
        groupName VARCHAR(250) NOT NULL,
        subject VARCHAR(250),
        phoneNumber VARCHAR(20),
        isSynced TINYINT(1) DEFAULT 0,
        description TEXT,
        listId INT NULL,
        createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        lastSyncedAt DATETIME NULL,
        updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    `);
    // migrate: add listId to pre-existing wa_groups tables created before this
    // column was introduced (X2CRM's x2_lists.id — links a group to a dynamic
    // X2CRM contact list whose criteria drives its membership)
    const [listIdCol] = await dbPool.execute(
      "SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'wa_groups' AND COLUMN_NAME = 'listId'",
      [DB_NAME]
    );
    if (!listIdCol[0] || listIdCol[0].cnt === 0) {
      await dbPool.execute('ALTER TABLE wa_groups ADD COLUMN listId INT NULL');
    }
    // create whatsapp group members table
    await dbPool.execute(`
      CREATE TABLE IF NOT EXISTS wa_group_members (
        id INT PRIMARY KEY AUTO_INCREMENT,
        groupId INT NOT NULL,
        contactId INT NULL,
        phone VARCHAR(20) NOT NULL,
        name VARCHAR(250),
        isAdmin TINYINT(1) DEFAULT 0,
        joinedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (groupId) REFERENCES wa_groups(id) ON DELETE CASCADE,
        KEY (phone),
        KEY (contactId)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    `);
    // WhatsApp-notify assignment for X2CRM's native Web Lead Forms
    // (marketing/webleadForm), one row per x2_web_forms.id that has a
    // notification recipient set — managed from X2CRM's own admin UI
    // (WhatsApp Groups > Web Form Notifications), read here by
    // pollForNewWebFormLeads().
    await dbPool.execute(`
      CREATE TABLE IF NOT EXISTS wa_webform_notify (
        webFormId INT PRIMARY KEY,
        pracharakId INT NOT NULL,
        lastPolledAt BIGINT NOT NULL DEFAULT 0,
        updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    `);
    // Note: admin users are managed by X2CRM's `x2_users` table. No local admin users table.
    console.log('wa-hub: DB pool initialized and tables ensured');
  } catch (err) {
    console.warn('wa-hub: could not initialize DB pool; messages will not be persisted:', err.message || err);
  }
}

async function saveMessage({ contactId = null, phone = null, direction = 'in', message = '', eventType = null, sentAt = null, receivedAt = null }) {
  if (!dbPool) return;
  try {
    const q = `INSERT INTO wa_messages (contact_id, phone, direction, message, event_type, sent_at, received_at) VALUES (?, ?, ?, ?, ?, ?, ?)`;
    await dbPool.execute(q, [contactId, phone, direction, message, eventType, sentAt, receivedAt]);
  } catch (err) {
    console.error('wa-hub: failed to save message:', err.message || err);
  }
}

async function purgeMessagesOlderThan(days) {
  if (!dbPool) throw new Error('db unavailable');
  const d = Math.max(0, Number(days) || 0);
  const [result] = await dbPool.execute(`DELETE FROM wa_messages WHERE created_at < (NOW() - INTERVAL ? DAY)`, [d]);
  // result may be OkPacket
  const affected = result.affectedRows || (result && result.affected_rows) || 0;
  return affected;
}

function normalizeContactFromWebhook({ name = '', phone = '', email = '' }) {
  const firstName = (name || '').split(' ')[0] || '';
  const lastName = (name || '').split(' ').slice(1).join(' ') || 'Unknown';
  const visibility = 1;
  const payload = { firstName, lastName, visibility };
  if (email) payload.email = String(email).trim();
  if (phone) payload.phone = String(phone).trim();
  if (!payload.name) payload.name = [firstName, lastName].filter(Boolean).join(' ').trim() || email || phone || 'Unknown';
  return payload;
}

async function upsertX2crmContact(contact) {
  if (!X2CRM_API_URL) throw new Error('X2CRM_API_URL not set');
  const lookupValue = contact.phone || contact.email;
  const lookupField = contact.phone ? 'phone' : 'email';
  try {
    const existing = await axios.get(`${X2CRM_API_URL}/Contacts`, { params: { [lookupField]: lookupValue }, auth: x2crmAuth });
    const existingId = existing.data?.[0]?.id;
    if (existingId) {
      await axios.put(`${X2CRM_API_URL}/Contacts/${existingId}.json`, contact, { auth: x2crmAuth });
      return existingId;
    }
  } catch (err) {
    // continue to create
  }

  const created = await axios.post(`${X2CRM_API_URL}/Contacts`, contact, { auth: x2crmAuth });
  return created.data?.id;
}

async function logX2crmAction(contactId, { type = 'WhatsApp', description = '', complete = true } = {}) {
  if (!contactId) return;
  try {
    await axios.post(
      `${X2CRM_API_URL}/Actions`,
      {
        associationId: contactId,
        associationType: 'Contacts',
        type,
        subject: description,
        actionDescription: description,
        complete,
        dueDate: new Date().toISOString(),
      },
      { auth: x2crmAuth }
    );
  } catch (err) {
    console.error('Failed to log X2CRM action:', err.response?.status, err.response?.data || err.message);
  }
}

// Webhook receiver: expects JSON { phone, name, text, eventType }
app.post('/webhooks/wa', async (req, res) => {
  try {
    const event = req.body || {};
    const phone = event.phone || event.waId || event.from;
    const name = event.name || event.senderName || event.profileName || '';
    const text = event.text || event.message || event.body || '';
    const eventType = event.eventType || 'message';

    const contactPayload = normalizeContactFromWebhook({ name, phone });
    const contactId = await upsertX2crmContact(contactPayload);

    // persist raw message to wa_messages table
    await saveMessage({ contactId, phone, direction: 'in', message: text, eventType, receivedAt: new Date() });

    const desc = text ? `[WhatsApp ${eventType}] ${text}` : `[WhatsApp ${eventType}] (no text)`;
    await logX2crmAction(contactId, { type: 'WhatsApp', description: desc, complete: true });

    // also create a short action for outbound attempts if any later

    res.status(200).json({ ok: true });
  } catch (err) {
    console.error('wa-hub webhook error:', err.message || err);
    res.status(500).json({ ok: false, error: err.message });
  }
});

app.get('/health', (req, res) => res.json({ status: 'ok' }));

// GET /form-status/:id - Public, unauthenticated, read-only check of
// whether a registered lead form (x2_custom_lead_forms) is currently
// active. Deliberately outside requireAdmin: leadform.html (and any future
// custom lead-capture page) is a static file with no PHP behind it, and
// anonymous visitors need to be able to check this before the form even
// renders. Exposes nothing beyond a boolean/name — no admin data, no auth
// bypass risk. CORS is open here specifically (not globally) since the
// static page is typically served from a different port/origin than
// wa-hub itself.
app.get('/form-status/:id', async (req, res) => {
  res.set('Access-Control-Allow-Origin', '*');
  if (!dbPool) return res.status(503).json({ error: 'db unavailable' });
  try {
    const [rows] = await dbPool.execute(
      'SELECT name, active, deactivateAt FROM x2_custom_lead_forms WHERE id = ?',
      [req.params.id]
    );
    const form = rows && rows[0];
    if (!form) return res.status(404).json({ active: false, reason: 'not_found' });

    const scheduledPast = form.deactivateAt && (Number(form.deactivateAt) * 1000) <= Date.now();
    const active = !!form.active && !scheduledPast;

    res.json({ active, name: form.name, reason: active ? null : (scheduledPast ? 'scheduled' : 'deactivated') });
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// query messages: /messages?phone=... or /messages?contactId=...
app.get('/messages', requireAdmin, async (req, res) => {
  if (!dbPool) return res.status(503).json({ error: 'db unavailable' });
  const { phone, contactId, limit = 100 } = req.query;
  const params = [];
  let where = '';
  if (contactId) {
    where = 'WHERE contact_id = ?';
    params.push(contactId);
  } else if (phone) {
    where = 'WHERE phone = ?';
    params.push(phone);
  }
  try {
    const lim = Math.min(1000, Math.max(1, Number(limit || 100)));
    const sql = `SELECT * FROM wa_messages ${where} ORDER BY id DESC LIMIT ${lim}`;
    const [rows] = await dbPool.execute(sql, params);
    res.json(rows);
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// admin rate limiter: 10 requests per minute per IP
const adminLimiter = rateLimit({ windowMs: 60 * 1000, max: 10, standardHeaders: true, legacyHeaders: false });

// Admin purge endpoint (protected + rate-limited)
app.post('/admin/purge', requireAdmin, adminLimiter, async (req, res) => {
  const days = req.query.days || WA_RETENTION_DAYS;
  try {
    const deleted = await purgeMessagesOlderThan(days);
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'purge', params: { days }, success: true });
    res.json({ ok: true, deleted });
  } catch (err) {
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'purge', params: { days }, success: false, error: err.message || String(err) });
    res.status(500).json({ ok: false, error: err.message || String(err) });
  }
});

initDb().then(async () => {
  // run a purge at startup with configured retention to ensure compliance
  try {
    const deleted = await purgeMessagesOlderThan(WA_RETENTION_DAYS);
    if (deleted) console.log(`wa-hub: purged ${deleted} messages older than ${WA_RETENTION_DAYS} days at startup`);
  } catch (err) {
    console.warn('wa-hub: startup purge failed:', err.message || err);
  }
  // schedule daily purge
  setInterval(() => {
    purgeMessagesOlderThan(WA_RETENTION_DAYS).catch((e) => console.warn('wa-hub: scheduled purge failed:', e.message || e));
  }, 24 * 60 * 60 * 1000);

  // Poll for new prospects on pracharak lead forms every 30s (see
  // pollForNewProspects() for why this is polling-based, not instant)
  setInterval(() => {
    pollForNewProspects().catch((e) => console.warn('wa-hub: scheduled prospect poll failed:', e.message || e));
  }, 30000);

  // Same cadence for native X2CRM Web Lead Form submissions with a WhatsApp
  // notify recipient assigned (see pollForNewWebFormLeads()).
  setInterval(() => {
    pollForNewWebFormLeads().catch((e) => console.warn('wa-hub: scheduled web form lead poll failed:', e.message || e));
  }, 30000);

  // Initialize Baileys WhatsApp socket
  console.log('wa-hub: initializing WhatsApp connection...');
  initBaileysSocket().catch((err) => console.warn('wa-hub: failed to initialize WhatsApp:', err.message || err));

  app.listen(PORT, () => console.log(`wa-hub listening on :${PORT}`));
});

// Admin auth middleware (Bearer token). Protects any /admin route.
async function requireAdmin(req, res, next) {
  const auth = req.get('authorization') || '';
  // Use only X2CRM Basic auth (username:password). No local bearer token allowed.
  // Basic auth (username:password)
  if (auth.toLowerCase().startsWith('basic ')) {
    try {
      const b64 = auth.slice(6).trim();
      const creds = Buffer.from(b64, 'base64').toString('utf8');
      const idx = creds.indexOf(':');
      const user = idx === -1 ? creds : creds.slice(0, idx);
      const pass = idx === -1 ? '' : creds.slice(idx + 1);
      // Accept X2CRM API credentials (admin using API key)
      if (user === X2CRM_API_USERNAME && pass === X2CRM_API_KEY) {
        req.adminUser = user;
        return next();
      }
      // verify against X2CRM `x2_users` password using PBKDF2-based PasswordUtil format
      try {
        const [rows] = await dbPool.execute('SELECT password, username FROM x2_users WHERE username = ? LIMIT 1', [user]);
        const found = rows && rows[0];
        if (!found) {
          console.debug('requireAdmin: user not found', user);
          return res.status(403).json({ error: 'forbidden' });
        }
        const ok = validateX2Password(found.password, pass || '');
        if (!ok) {
          // Try legacy MD5 match (old installs)
          const md5 = crypto.createHash('md5').update(pass || '').digest('hex');
          if (found.password === md5) {
            console.debug('requireAdmin: legacy md5 password accepted for', user);
            req.adminUser = found.username || user;
            return next();
          }
          console.debug('requireAdmin: password mismatch for', user);
          return res.status(403).json({ error: 'forbidden' });
        }
        req.adminUser = found.username || user;
        return next();
      } catch (e) {
        console.error('requireAdmin: auth lookup error', e && e.message);
        return res.status(500).json({ error: 'auth error' });
      }
    } catch (e) {
      return res.status(400).json({ error: 'invalid basic auth' });
    }
  }

  // If not basic auth, forbid.
  return res.status(401).json({ error: 'missing basic auth' });
}

// (no global mount) admin routes are protected by passing `requireAdmin` to each handler

async function logAdminAction({ adminUser = null, ip = null, action = '', params = null, success = false, error = null }) {
  if (!dbPool) {
    console.warn('wa-hub: db unavailable, skipping admin audit log');
    return;
  }
  try {
    const q = `INSERT INTO wa_admin_audit (admin_user, ip, action, params, success, error) VALUES (?, ?, ?, ?, ?, ?)`;
    const paramsJson = params ? JSON.stringify(params) : null;
    await dbPool.execute(q, [adminUser, ip, action, paramsJson, success ? 1 : 0, error]);
  } catch (err) {
    console.error('wa-hub: failed to write admin audit log:', err.message || err);
  }
}

// X2CRM PasswordUtil-compatible PBKDF2 functions (matches PHP implementation)
function validateX2Password(goodHash, password) {
  if (!goodHash || !password) return false;
  try {
    const parts = goodHash.split(':');
    if (parts.length < 4) return false;
    const algorithm = parts[0];
    const iterations = parseInt(parts[1], 10);
    const salt = parts[2];
    const hashB64 = parts[3];
    const hashBytes = Buffer.from(hashB64, 'base64');
    const derived = crypto.pbkdf2Sync(password, salt, iterations, hashBytes.length, algorithm);
    return crypto.timingSafeEqual(derived, hashBytes);
  } catch (e) {
    return false;
  }
}

function createX2Hash(password) {
  const algorithm = 'sha256';
  const iterations = 32768;
  const saltBytes = 24;
  const hashBytes = 24;
  const salt = crypto.randomBytes(saltBytes).toString('base64');
  const dk = crypto.pbkdf2Sync(password, salt, iterations, hashBytes, algorithm);
  return `${algorithm}:${iterations}:${salt}:${dk.toString('base64')}`;
}

// Admin users management API (protected): create/list/delete users
app.post('/admin/users', requireAdmin, adminLimiter, async (req, res) => {
  const { username, password } = req.body || {};
  if (!username || !password) return res.status(400).json({ error: 'username and password required' });
  try {
    // create user in x2_users and x2_profile to inherit X2CRM credentials
    const hash = createX2Hash(password);
    // insert into x2_users: firstName/lastName empty, status 1 (active)
    await dbPool.execute('INSERT INTO x2_users (firstName, lastName, username, password, emailAddress, status, lastLogin) VALUES (?, ?, ?, ?, ?, ?, ?)', ['', '', username, hash, '', 1, 0]);
    // create profile entry
    try {
      await dbPool.execute('INSERT INTO x2_profile (fullName, username, emailAddress, status) VALUES (?, ?, ?, ?)', [username, username, '', 1]);
    } catch (e) {
      // ignore profile insert errors (may already exist)
    }
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'create_user', params: { username }, success: true });
    res.json({ ok: true });
  } catch (err) {
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'create_user', params: { username }, success: false, error: err.message || String(err) });
    res.status(500).json({ error: err.message || String(err) });
  }
});

app.get('/admin/users', requireAdmin, adminLimiter, async (req, res) => {
  try {
    const [rows] = await dbPool.execute('SELECT id, firstName, lastName, username, emailAddress, status FROM x2_users ORDER BY id ASC');
    res.json(rows.map(r => ({ id: r.id, username: r.username, firstName: r.firstName, lastName: r.lastName, email: r.emailAddress, status: r.status })));
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

app.delete('/admin/users/:username', requireAdmin, adminLimiter, async (req, res) => {
  const username = req.params.username;
  try {
    const [result] = await dbPool.execute('DELETE FROM x2_users WHERE username = ?', [username]);
    // also delete profile row if present
    await dbPool.execute('DELETE FROM x2_profile WHERE username = ?', [username]);
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'delete_user', params: { username }, success: true });
    res.json({ ok: true, affected: result.affectedRows || 0 });
  } catch (err) {
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'delete_user', params: { username }, success: false, error: err.message || String(err) });
    res.status(500).json({ error: err.message || String(err) });
  }
});

// ============ WhatsApp Groups Management API ============

// GET /admin/groups - List all WhatsApp groups
app.get('/admin/groups', requireAdmin, adminLimiter, async (req, res) => {
  try {
    const [groups] = await dbPool.execute('SELECT id, groupId, groupName, subject, phoneNumber, isSynced, listId, createdAt, lastSyncedAt FROM wa_groups ORDER BY createdAt DESC');
    
    // Get member counts
    const groupsWithCounts = await Promise.all(groups.map(async (g) => {
      const [members] = await dbPool.execute('SELECT COUNT(*) as count FROM wa_group_members WHERE groupId = ?', [g.id]);
      return { ...g, memberCount: members[0]?.count || 0 };
    }));
    
    res.json(groupsWithCounts);
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// GET /admin/groups/:groupId - Get group details with members
app.get('/admin/groups/:groupId', requireAdmin, adminLimiter, async (req, res) => {
  try {
    const [groupRows] = await dbPool.execute('SELECT id, groupId, groupName, subject, isSynced, listId, createdAt, lastSyncedAt FROM wa_groups WHERE groupId = ? LIMIT 1', [req.params.groupId]);
    if (!groupRows || !groupRows[0]) return res.status(404).json({ error: 'group not found' });
    
    const group = groupRows[0];
    const [members] = await dbPool.execute('SELECT id, phone, name, isAdmin, joinedAt FROM wa_group_members WHERE groupId = ? ORDER BY joinedAt ASC', [group.id]);
    
    res.json({ ...group, members: members || [], memberCount: (members || []).length });
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// POST /admin/groups - Create new WhatsApp group
app.post('/admin/groups', requireAdmin, adminLimiter, async (req, res) => {
  const { groupName, participants = [], listId = null } = req.body || {};
  if (!groupName) return res.status(400).json({ error: 'groupName required' });

  try {
    const result = await createWhatsAppGroup(groupName, participants, listId);
    res.json({ ok: true, ...result });
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// POST /admin/groups/:groupId/link-list - Link/unlink a group to an X2CRM dynamic list
app.post('/admin/groups/:groupId/link-list', requireAdmin, adminLimiter, async (req, res) => {
  const { listId = null } = req.body || {};
  try {
    await dbPool.execute('UPDATE wa_groups SET listId = ? WHERE groupId = ?', [listId || null, req.params.groupId]);
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'link_list', params: { groupId: req.params.groupId, listId }, success: true });
    res.json({ ok: true, listId: listId || null });
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// POST /admin/groups/:groupId/sync-members - Reconcile group membership to a
// caller-supplied phone list (X2CRM computes this from the linked list's
// live criteria and sends the current result set here)
app.post('/admin/groups/:groupId/sync-members', requireAdmin, adminLimiter, async (req, res) => {
  const { phones = [] } = req.body || {};
  if (!Array.isArray(phones)) return res.status(400).json({ error: 'phones array required' });

  try {
    const result = await syncGroupMembersToPhones(req.params.groupId, phones);
    res.json({ ok: true, ...result });
  } catch (err) {
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'sync_group_members_to_list', params: { groupId: req.params.groupId }, success: false, error: err.message });
    res.status(500).json({ error: err.message || String(err) });
  }
});

// POST /admin/groups/:groupId/members - Add members to group
app.post('/admin/groups/:groupId/members', requireAdmin, adminLimiter, async (req, res) => {
  const { phones = [] } = req.body || {};
  if (!Array.isArray(phones) || phones.length === 0) return res.status(400).json({ error: 'phones array required' });
  
  try {
    const result = await addMembersToGroup(req.params.groupId, phones);
    res.json({ ok: true, ...result });
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// DELETE /admin/groups/:groupId/members/:phone - Remove member from group
app.delete('/admin/groups/:groupId/members/:phone', requireAdmin, adminLimiter, async (req, res) => {
  try {
    const result = await removeMemberFromGroup(req.params.groupId, req.params.phone);
    res.json({ ok: true, ...result });
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// POST /admin/groups/:groupId/rename - Rename a group
app.post('/admin/groups/:groupId/rename', requireAdmin, adminLimiter, async (req, res) => {
  const { groupName } = req.body || {};
  if (!groupName) return res.status(400).json({ error: 'groupName required' });

  try {
    const result = await renameGroup(req.params.groupId, groupName);
    res.json({ ok: true, ...result });
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// DELETE /admin/groups/:groupId - Leave the group (WhatsApp has no "delete
// for everyone" API) and remove it from local tracking
app.delete('/admin/groups/:groupId', requireAdmin, adminLimiter, async (req, res) => {
  try {
    const result = await leaveAndDeleteGroup(req.params.groupId);
    res.json({ ok: true, ...result });
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// POST /admin/groups/sync-all - Sync all groups from WhatsApp
app.post('/admin/groups/sync-all', requireAdmin, adminLimiter, async (req, res) => {
  try {
    const synced = await syncGroupsFromWhatsApp();
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'sync_groups', params: { count: synced.length }, success: true });
    res.json({ ok: true, synced: synced.length, groups: synced });
  } catch (err) {
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'sync_groups', success: false, error: err.message });
    res.status(500).json({ error: err.message || String(err) });
  }
});

// POST /admin/groups/:groupId/sync - Sync specific group from WhatsApp
app.post('/admin/groups/:groupId/sync', requireAdmin, adminLimiter, async (req, res) => {
  try {
    if (!sock || !isOpen) {
      return res.status(503).json({ error: 'WhatsApp not connected' });
    }
    
    const metadata = await sock.groupMetadata(req.params.groupId);
    const groupId = metadata.id;
    const groupName = metadata.subject || 'Unknown Group';
    
    // Upsert group
    await dbPool.execute(
      'INSERT INTO wa_groups (groupId, groupName, subject, isSynced, lastSyncedAt) VALUES (?, ?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE subject = ?, lastSyncedAt = NOW()',
      [groupId, groupName, groupName, groupName]
    );
    
    const [groupRow] = await dbPool.execute('SELECT id FROM wa_groups WHERE groupId = ?', [groupId]);
    const internalId = groupRow[0].id;
    
    await dbPool.execute('DELETE FROM wa_group_members WHERE groupId = ?', [internalId]);
    
    for (const participant of metadata.participants || []) {
      const phone = participant.id.replace('@s.whatsapp.net', '').replace(/\D/g, '');
      const isAdmin = ['admin', 'superadmin'].includes(participant.admin) ? 1 : 0;
      
      try {
        await dbPool.execute(
          'INSERT INTO wa_group_members (groupId, phone, name, isAdmin) VALUES (?, ?, ?, ?)',
          [internalId, phone, phone, isAdmin]
        );
      } catch (e) {
        // Ignore duplicates
      }
    }
    
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'sync_group', params: { groupId, memberCount: metadata.participants.length }, success: true });
    res.json({ ok: true, groupName, memberCount: metadata.participants.length });
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// GET /admin/wa-status - Check WhatsApp connection status, plus enough
// detail to render a standalone "WhatsApp Configuration" admin page
// (connection state, phone number, tracked-data counts, recent activity).
app.get('/admin/wa-status', requireAdmin, async (req, res) => {
  const isConnected = !!(sock && isOpen);
  // sock.user.id looks like "17603907974:59@s.whatsapp.net" — strip both
  // the domain and the ":59" per-device suffix to get the bare number.
  const phoneNumber = sock?.user?.id ? String(sock.user.id).split('@')[0].split(':')[0] : null;
  const pushName = sock?.user?.name || null;

  let totalGroups = null, totalMessages = null, recentAudit = [];
  if (dbPool) {
    try {
      const [[groupRow]] = await dbPool.query('SELECT COUNT(*) as cnt FROM wa_groups');
      totalGroups = groupRow.cnt;
      const [[msgRow]] = await dbPool.query('SELECT COUNT(*) as cnt FROM wa_messages');
      totalMessages = msgRow.cnt;
      const [auditRows] = await dbPool.query(
        'SELECT admin_user, action, success, error, created_at FROM wa_admin_audit ORDER BY id DESC LIMIT 8'
      );
      recentAudit = auditRows;
    } catch (e) {
      console.warn('wa-hub: failed to load status stats:', e.message || e);
    }
  }

  res.json({
    connected: isConnected,
    connecting: isConnecting,
    phoneNumber,
    pushName,
    hasQr: !!latestQr,
    sessionDir: WA_SESSION_DIR,
    retentionDays: WA_RETENTION_DAYS,
    totalGroups,
    totalMessages,
    recentAudit,
  });
});

// POST /admin/logout - Fully log out of WhatsApp (not just leave a group)
// and clear the saved session so the next connection attempt starts a
// fresh pairing (immediate new QR) instead of retrying now-invalid creds.
app.post('/admin/logout', requireAdmin, adminLimiter, async (req, res) => {
  try {
    if (sock) {
      try {
        await sock.logout();
      } catch (e) {
        console.warn('wa-hub: sock.logout() failed (continuing to reset local state):', e.message || e);
      }
    }
    sock = null;
    isOpen = false;
    isConnecting = false;
    latestQr = null;

    // Wipe the on-disk session so useMultiFileAuthState starts clean.
    // WA_SESSION_DIR is a Docker volume mount point (so pairing survives
    // container rebuilds) — rm-ing the directory itself throws EBUSY
    // ("resource busy or locked"), since you can't remove a mount point,
    // only its contents. Clear the contents instead.
    const fs = require('fs');
    const path = require('path');
    try {
      const entries = await fs.promises.readdir(WA_SESSION_DIR);
      await Promise.all(entries.map((entry) =>
        fs.promises.rm(path.join(WA_SESSION_DIR, entry), { recursive: true, force: true })
      ));
    } catch (e) {
      console.warn('wa-hub: failed to clear session contents (continuing anyway):', e.message || e);
    }

    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'logout', success: true });

    // Kick off a fresh connection right away so a new QR is ready as soon
    // as the admin lands back on the configuration page.
    initBaileysSocket().catch((err) => console.warn('wa-hub: failed to reinitialize after logout:', err.message || err));

    res.json({ ok: true });
  } catch (err) {
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'logout', success: false, error: err.message });
    res.status(500).json({ error: err.message || String(err) });
  }
});

// POST /admin/notify-new-form - Message-to-self with a new lead form's URL,
// a QR code of it, and a tinyurl.com short link
app.post('/admin/notify-new-form', requireAdmin, adminLimiter, async (req, res) => {
  const { name, url } = req.body || {};
  if (!name || !url) return res.status(400).json({ error: 'name and url are required' });

  try {
    const result = await notifyAdminNewForm({ name, url });
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'notify_new_form', params: { name, url }, success: true });
    res.json({ ok: true, ...result });
  } catch (err) {
    await logAdminAction({ adminUser: req.adminUser || null, ip: req.ip, action: 'notify_new_form', params: { name, url }, success: false, error: err.message });
    res.status(500).json({ error: err.message || String(err) });
  }
});

// GET /admin/qr-for-url.png?url=... - Generic QR code image for any URL
// (used for the lead-forms admin list, distinct from /admin/qr.png which is
// specifically the WhatsApp pairing QR)
app.get('/admin/qr-for-url.png', requireAdmin, async (req, res) => {
  const { url } = req.query || {};
  if (!url) return res.status(400).json({ error: 'url query param required' });
  try {
    const buffer = await QRCode.toBuffer(url, { type: 'png', width: 240, margin: 2 });
    res.set('Content-Type', 'image/png');
    res.set('Cache-Control', 'public, max-age=86400');
    res.send(buffer);
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// GET /admin/qr.png - Current pairing QR as a real scannable image (not
// just ASCII in the container logs, which needs server/SSH access)
app.get('/admin/qr.png', requireAdmin, async (req, res) => {
  if (!latestQr) {
    return res.status(404).json({ error: 'no QR available right now (already connected, or not yet generated)' });
  }
  try {
    const buffer = await QRCode.toBuffer(latestQr, { type: 'png', width: 320, margin: 2 });
    res.set('Content-Type', 'image/png');
    res.set('Cache-Control', 'no-store');
    res.send(buffer);
  } catch (err) {
    res.status(500).json({ error: err.message || String(err) });
  }
});

// GET /admin/qr - Self-refreshing pairing page: shows the QR while
// disconnected, then "Connected" once scanned. Meant to be the one place
// you go to pair/re-pair WhatsApp in any environment (local or production)
// without needing container log access.
app.get('/admin/qr', requireAdmin, async (req, res) => {
  res.set('Content-Type', 'text/html');
  res.send(`<!doctype html>
<html>
<head>
<title>wa-hub — WhatsApp pairing</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: system-ui, sans-serif; text-align: center; padding: 40px 20px; background: #f5f5f5; }
  #qr { margin: 20px auto; border: 1px solid #ddd; background: #fff; padding: 16px; display: inline-block; border-radius: 8px; }
  #qr img { display: block; width: 280px; height: 280px; }
  #status { font-size: 16px; margin-bottom: 10px; }
  .connected { color: #1a7f37; font-weight: 600; }
  .waiting { color: #555; }
</style>
</head>
<body>
  <h2>WhatsApp Pairing</h2>
  <div id="status" class="waiting">Checking status...</div>
  <div id="qr" style="display:none;"><img id="qr-img" src="" alt="QR code"></div>
  <p style="color:#888; font-size:13px;">WhatsApp &gt; Settings &gt; Linked Devices &gt; Link a Device</p>
<script>
  async function poll() {
    try {
      // No auth header needed here: the browser already has this page's own
      // Basic Auth credentials cached for this origin and resends them
      // automatically for same-origin requests like this one.
      const res = await fetch('/admin/wa-status');
      const data = await res.json();
      const statusEl = document.getElementById('status');
      const qrEl = document.getElementById('qr');
      const qrImg = document.getElementById('qr-img');
      if (data.connected) {
        statusEl.textContent = 'Connected as +' + data.phoneNumber;
        statusEl.className = 'connected';
        qrEl.style.display = 'none';
      } else if (data.hasQr) {
        statusEl.textContent = 'Scan this QR code with WhatsApp';
        statusEl.className = 'waiting';
        qrImg.src = '/admin/qr.png?_=' + Date.now();
        qrEl.style.display = 'inline-block';
      } else {
        statusEl.textContent = 'Waiting for a QR code to be generated...';
        statusEl.className = 'waiting';
        qrEl.style.display = 'none';
      }
    } catch (e) {
      document.getElementById('status').textContent = 'Error checking status: ' + e.message;
    }
  }
  poll();
  setInterval(poll, 4000);
</script>
</body>
</html>`);
});

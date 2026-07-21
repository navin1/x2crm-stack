-- Run this AFTER restoring the production x2crm database dump onto the new
-- server, and BEFORE starting the app containers against it.
--
-- Production's dump won't contain any of this: these tables/columns/RBAC
-- rows were all added on top of stock X2Engine by this stack's custom
-- features (WhatsApp Groups, MailerLite sync, the iframe lead-form ->
-- WhatsApp notify feature). Everything here is idempotent (safe to re-run).
--
-- Usage:
--   docker exec -i x2crm_db mysql -u root -p"$DB_ROOT_PASSWORD" x2crm \
--     < scripts/reconcile-custom-schema.sql

-- ---------------------------------------------------------------------
-- 1. Custom tables absent from a stock/production X2Engine database
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `integration_settings` (
  `id` tinyint NOT NULL,
  `mailerliteApiKey` text,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `mailerlite_list_sync` (
  `id` int NOT NULL AUTO_INCREMENT,
  `listId` int NOT NULL,
  `listName` varchar(255) NOT NULL,
  `groupName` varchar(255) NOT NULL,
  `groupId` varchar(64) DEFAULT NULL,
  `autoSync` tinyint(1) NOT NULL DEFAULT '0',
  `lastSyncedAt` datetime DEFAULT NULL,
  `lastSyncCount` int DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listId` (`listId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `wa_groups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `groupId` varchar(100) DEFAULT NULL,
  `groupName` varchar(250) NOT NULL,
  `subject` varchar(250) DEFAULT NULL,
  `phoneNumber` varchar(20) DEFAULT NULL,
  `isSynced` tinyint(1) DEFAULT '0',
  `description` text,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastSyncedAt` datetime DEFAULT NULL,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `listId` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groupId` (`groupId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `wa_group_members` (
  `id` int NOT NULL AUTO_INCREMENT,
  `groupId` int NOT NULL,
  `contactId` int DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `name` varchar(250) DEFAULT NULL,
  `isAdmin` tinyint(1) DEFAULT '0',
  `joinedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `groupId` (`groupId`),
  KEY `phone` (`phone`),
  KEY `contactId` (`contactId`),
  CONSTRAINT `wa_group_members_ibfk_1` FOREIGN KEY (`groupId`) REFERENCES `wa_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `wa_messages` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `contact_id` int DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `direction` enum('in','out') NOT NULL,
  `message` text,
  `event_type` varchar(64) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `wa_admin_audit` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `admin_user` varchar(128) DEFAULT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `action` varchar(128) NOT NULL,
  `params` json DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `error` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `wa_admin_users` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `username` varchar(128) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `wa_webform_notify` (
  `webFormId` int NOT NULL,
  `pracharakId` int NOT NULL,
  `lastPolledAt` bigint NOT NULL DEFAULT '0',
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`webFormId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `x2_custom_lead_forms` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `url` varchar(255) NOT NULL,
  `webFormId` int unsigned DEFAULT NULL,
  `tinyUrl` varchar(255) DEFAULT NULL,
  `createdBy` varchar(50) DEFAULT NULL,
  `createDate` bigint NOT NULL,
  `notifiedAt` bigint DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `deactivateAt` bigint DEFAULT NULL,
  `pracharakId` int unsigned DEFAULT NULL,
  `fields` text,
  `lastPolledAt` bigint DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- ---------------------------------------------------------------------
-- 2. Columns added on top of a STOCK X2Engine table (production's copy
--    of x2_web_forms exists, but won't have these two columns)
-- ---------------------------------------------------------------------

ALTER TABLE `x2_web_forms`
  ADD COLUMN IF NOT EXISTS `active` tinyint(1) NOT NULL DEFAULT '1',
  ADD COLUMN IF NOT EXISTS `deactivateAt` bigint DEFAULT NULL;

-- ---------------------------------------------------------------------
-- 3. Guest (unauthenticated) RBAC entries this stack added, so the public
--    lead-form endpoints and the MailerLite auto-sync poller keep working
--    without a login. Production's x2_auth_item / x2_auth_item_child won't
--    have these two custom action names at all.
-- ---------------------------------------------------------------------

INSERT IGNORE INTO `x2_auth_item` (`name`, `type`, `description`, `bizrule`, `data`) VALUES
  ('ContactsWeblead', 0, '', NULL, 'N;'),
  ('MailerliteResolveListMembers', 0, 'Server-to-server: resolve a Contacts list for the MailerLite auto-sync poller.', NULL, 'N;');

INSERT IGNORE INTO `x2_auth_item_child` (`parent`, `child`) VALUES
  ('AuthenticatedSiteFunctionsTask', 'ContactsWeblead'),
  ('GuestSiteFunctionsTask', 'ContactsWeblead'),
  ('AuthenticatedSiteFunctionsTask', 'MailerliteResolveListMembers'),
  ('GuestSiteFunctionsTask', 'MailerliteResolveListMembers');

-- ---------------------------------------------------------------------
-- 4. Clear any cached RBAC/permission resolution from production's data,
--    so the two guest items above take effect immediately.
-- ---------------------------------------------------------------------

TRUNCATE TABLE `x2_auth_cache`;

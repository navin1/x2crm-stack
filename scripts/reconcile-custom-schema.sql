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
-- 2. Columns added on top of a STOCK X2Engine table — IF production's
--    copy of x2_web_forms exists at all. Confirmed in the wild: a
--    production instance that never actually used X2CRM's native
--    "Web Lead Form" builder (Marketing module) can genuinely lack this
--    table entirely, not just be missing these two columns. Web Form
--    Notifications (WhatsappGroupsController::actionWebFormNotify) simply
--    won't have anything to manage in that case until the Marketing
--    module actually gets used, which is fine — skip gracefully rather
--    than failing the whole reconcile run.
-- ---------------------------------------------------------------------

SET @web_forms_table_exists = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'x2_web_forms'
);

-- ADD COLUMN IF NOT EXISTS needs MySQL 8.0.29+ — production dumps can land
-- on an older 8.0.x build (confirmed: this broke on a freshly pulled
-- mysql:8.0 image on an Oracle Ampere/arm64 host), so check
-- information_schema and only run the ALTER conditionally instead, which
-- works on any MySQL 5.7+/8.0.x and MariaDB.
SET @active_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'x2_web_forms' AND column_name = 'active'
);
SET @sql = IF(@web_forms_table_exists > 0 AND @active_exists = 0,
  'ALTER TABLE `x2_web_forms` ADD COLUMN `active` tinyint(1) NOT NULL DEFAULT ''1''',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @deactivate_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'x2_web_forms' AND column_name = 'deactivateAt'
);
SET @sql = IF(@web_forms_table_exists > 0 AND @deactivate_exists = 0,
  'ALTER TABLE `x2_web_forms` ADD COLUMN `deactivateAt` bigint DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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

-- ---------------------------------------------------------------------
-- 5. Convert legacy utf8mb3 tables to utf8mb4 — a dump sourced from an
--    older MySQL 5.7 server (common; phpMyAdmin/cPanel-hosted production
--    instances are frequently still on 5.7) carries the legacy
--    utf8mb3_general_ci collation on every stock table. MySQL 8's regex
--    engine (used by REGEXP_LIKE, which X2CRM's own search/filtering
--    relies on) flatly rejects mixing that collation with a
--    binary-collated value — a real error hit on a live migration:
--    "Character set 'utf8mb3_general_ci' cannot be used in conjunction
--    with 'binary' in call to regexp_like". Converts every legacy table
--    found (no-op if none — e.g. already on utf8mb4, or a fresh
--    non-migrated install), with FK checks off for the whole batch since
--    converting tables one at a time otherwise trips FK collation
--    mismatches against not-yet-converted related tables.
-- ---------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS=0;

DELIMITER $$
DROP PROCEDURE IF EXISTS convert_legacy_charset_tables$$
CREATE PROCEDURE convert_legacy_charset_tables()
BEGIN
  DECLARE done INT DEFAULT FALSE;
  DECLARE tbl VARCHAR(255);
  DECLARE cur CURSOR FOR
    SELECT table_name FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_collation = 'utf8mb3_general_ci';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO tbl;
    IF done THEN
      LEAVE read_loop;
    END IF;
    SET @sql = CONCAT('ALTER TABLE `', tbl, '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END LOOP;
  CLOSE cur;
END$$
DELIMITER ;

CALL convert_legacy_charset_tables();
DROP PROCEDURE convert_legacy_charset_tables;

-- ALTER DATABASE isn't supported in the prepared-statement protocol at
-- all (MySQL error 1295: "This command is not supported in the prepared
-- statement protocol yet"), so unlike the table-name loop above this
-- can't go through PREPARE/EXECUTE — but it doesn't need to: with no
-- explicit database name, ALTER DATABASE always applies to whichever
-- database the current session is connected to, which is already the
-- right one (this whole script is invoked with a specific db name on
-- the command line).
ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS=1;

-- ---------------------------------------------------------------------
-- 6. Stock X2Engine tables missing entirely from a production dump — not
--    because of anything wrong with the dump, but because that instance
--    genuinely never used the corresponding feature (mysqldump only ever
--    exports tables that exist). Hit live: a CDbException 500
--    ("table X2_campaigns/x2_templates ... cannot be found") the moment
--    a page (here, the Profile dashboard) touched a module the org had
--    never actually used. Compared a fresh install's full table list
--    (protected/data/*.sql + every module's own install.sql) against a
--    real migrated production database and found exactly these two
--    gaps; CREATE TABLE IF NOT EXISTS makes this safe to run regardless
--    of whether a given production instance already has them.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS x2_campaigns (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    masterId     INT UNSIGNED NULL,
    name         VARCHAR(250) NOT NULL,
    nameId       VARCHAR(250) DEFAULT NULL,
    assignedTo   VARCHAR(50),
    email        VARCHAR(250),
    phone        VARCHAR(40),
    leadstatus   VARCHAR(250),
    listId       VARCHAR(100),
    suppressionListId VARCHAR(100),
    active       TINYINT DEFAULT 1,
    description  TEXT,
    type         VARCHAR(100) DEFAULT NULL,
    cost         VARCHAR(100) DEFAULT NULL,
    leadSource   VARCHAR(40) DEFAULT NULL,
    template     VARCHAR(250) DEFAULT '0',
    subject      VARCHAR(250),
    content      TEXT,
    createdBy    VARCHAR(50) NOT NULL,
    complete     TINYINT DEFAULT 0,
    visibility   INT NOT NULL,
    createDate   BIGINT NOT NULL,
    launchDate   BIGINT,
    lastUpdated  BIGINT NOT NULL,
    lastActivity BIGINT,
    updatedBy    VARCHAR(50),
    sendAs       INT DEFAULT NULL,
    bouncedAccount INT DEFAULT NULL,
    enableRedirectLinks TINYINT DEFAULT 0,
    enableBounceHandling TINYINT NOT NULL DEFAULT 0,
    openRate     FLOAT DEFAULT NULL,
    clickRate    FLOAT DEFAULT NULL,
    unsubscribeRate FLOAT DEFAULT NULL,
    category     VARCHAR(250) DEFAULT 'Marketing',
    categoryListId    VARCHAR(100),
    parent VARCHAR(250),
    children VARCHAR(250),
    PRIMARY KEY (id),
    UNIQUE (nameId),
    INDEX(listId),
    INDEX(suppressionListId),
    INDEX(template)
) ENGINE InnoDB COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS x2_templates(
    id           INT NOT NULL AUTO_INCREMENT primary key,
    assignedTo   VARCHAR(250),
    `name`       VARCHAR(250) NOT NULL,
    nameId       VARCHAR(250) DEFAULT NULL,
    description  TEXT,
    createDate   INT,
    lastUpdated  INT,
    lastActivity BIGINT,
    updatedBy    VARCHAR(250),
    UNIQUE(nameId)
) COLLATE = utf8mb4_general_ci;

INSERT IGNORE INTO `x2_modules`
(`name`, title, visible, menuPosition, searchable, editable, adminOnly, custom, toggleable)
VALUES
('templates', 'TemplatesTitle', 1, 1, 1, 1, 0, 1, 1);

INSERT IGNORE INTO x2_fields
(modelName, fieldName, attributeLabel, custom, `type`, required, readOnly, linkType, searchable, isVirtual, relevance, uniqueConstraint, safe, keyType)
VALUES
('Templates', 'id',           'ID',            0, 'int',        0, 1, NULL, 0, 0, '',       1, 1, 'PRI'),
('Templates', 'name',         'Name',          0, 'varchar',    1, 0, NULL, 0, 0, 'High',   0, 1, NULL),
('Templates', 'nameId',       'NameID',        0, 'varchar',    0, 1, NULL, 0, 0, 'High',   0, 1, 'FIX'),
('Templates', 'assignedTo',   'Assigned To',   0, 'assignment', 0, 0, NULL, 0, 0, '',       0, 1, NULL),
('Templates', 'description',  'Description',   0, 'text',       0, 0, NULL, 0, 0, 'Medium', 0, 1, NULL),
('Templates', 'createDate',   'Create Date',   0, 'dateTime',   0, 1, NULL, 0, 0, '',       0, 1, NULL),
('Templates', 'lastUpdated',  'Last Updated',  0, 'dateTime',   0, 1, NULL, 0, 0, '',       0, 1, NULL),
('Templates', 'lastActivity', 'Last Activity', 0, 'dateTime',   0, 1, NULL, 0, 0, '',       0, 1, NULL),
('Templates', 'updatedBy',    'Updated By',    0, 'assignment', 0, 1, NULL, 0, 0, '',       0, 1, NULL);

-- ---------------------------------------------------------------------
-- 7. x2Activity: not a migration-specific gap like section 6 above — this
--    stock x2_modules row ships in EVERY X2Engine 7.1 install, including
--    a completely fresh one (confirmed against a from-scratch local dev
--    database with no migrated data at all), with no corresponding PHP
--    module anywhere in the codebase. x2_modules drives the main nav
--    menu, so clicking this entry there 404s/500s trying to route to a
--    controller that doesn't exist. Note this is a *different* crash
--    path than the x2_fields-based one migrate-from-prod.sh handles
--    dynamically for commercial-addon leftovers (X2Model::getModelTypes(),
--    used by e.g. the Contacts grid's "Add Relationship" dropdown, reads
--    DISTINCT modelName from x2_fields, not x2_modules at all — confirmed
--    x2Activity was never registered there, only here). Since this
--    reconcile script only ever runs for a migrated deployment (a fresh
--    local install just eats this bug silently until someone clicks that
--    menu item), fixing it here too rather than leaving it to be
--    rediscovered independently on every future migration.
-- ---------------------------------------------------------------------

DELETE FROM x2_modules WHERE name = 'x2Activity';

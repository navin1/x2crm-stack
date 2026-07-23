-- Applies X2Engine's own official 7.1->8.0 schema migration (fetched
-- directly from their update server, x2planet.com — this is the exact SQL
-- their in-app updater would run, verbatim, for auditability against what
-- X2Engine Inc. actually shipped).
--
-- Deliberately kept SEPARATE from scripts/reconcile-custom-schema.sql:
-- that script reconciles THIS stack's own custom features (WhatsApp
-- Groups, MailerLite sync) onto a fresh production restore, and runs every
-- time a new server is stood up. This file is a one-time VERSION upgrade —
-- a different lifecycle event that only ever runs once, when the 7.1->8.0
-- jump actually happens. Mixing them would make "did we already run the
-- 8.0 migration on this DB?" ambiguous.
--
-- Checked against every MySQL-8-incompatibility pattern that caused real
-- problems earlier in this project (ADD COLUMN IF NOT EXISTS needing
-- MySQL 8.0.29+, REGEXP BINARY, utf8mb3, loose GROUP BY) — none appear in
-- X2Engine's own SQL here. Also checked every new column/table name here
-- against this stack's own custom schema (custom Contacts fields, custom
-- RBAC rows) for collisions — confirmed zero overlaps.
--
-- Usage (run once, at the 7.1->8.0 upgrade boundary only):
--   docker exec -i x2crm_db mysql -u root -p"$DB_ROOT_PASSWORD" x2crm \
--     < scripts/upgrade-7.1-to-8.0-schema.sql

-- ---------------------------------------------------------------------
-- 1. New table. IF NOT EXISTS makes this line safe on its own even if
--    this script is accidentally re-run.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `x2_service_replies` ( `id` int(11) NOT NULL AUTO_INCREMENT, `serviceId` int(11) NOT NULL, `text` text NOT NULL, `assignedTo` varchar(250) DEFAULT NULL, `createDate` bigint(20) DEFAULT NULL, `lastUpdated` bigint(20) DEFAULT NULL, `updatedBy` varchar(250) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- ---------------------------------------------------------------------
-- 2. New columns across 12 stock tables (opt-in tracking, GrapesJS
--    builder fields, action scheduling/termination, KB URLs, etc). None
--    of MySQL's ADD COLUMN variants support "IF NOT EXISTS" pre-8.0.29
--    without the PREPARE/EXECUTE dance used elsewhere in this repo — for
--    13 ALTER statements that would be a lot of boilerplate for a
--    migration that only ever runs once. Instead, wrapped the whole block
--    in a single guard (checking one representative new column) so the
--    file as a whole is safe to accidentally re-run, without needing
--    per-statement idempotency for a genuinely one-time event.
-- ---------------------------------------------------------------------

DROP PROCEDURE IF EXISTS x2crm_apply_8_0_alters;
DELIMITER $$
CREATE PROCEDURE x2crm_apply_8_0_alters()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x2_admin' AND COLUMN_NAME = 'kbHomeUrl'
    ) THEN
        ALTER TABLE `x2_accounts` ADD COLUMN `optIn` tinyint(4) NULL AFTER `doNotEmail`, MODIFY COLUMN `modelName` varchar(100) NULL AFTER `company`;
        ALTER TABLE `x2_actions` ADD COLUMN `terminate` varchar(5) NULL DEFAULT 'No' AFTER `completeDate`, ADD COLUMN `terminatedBy` varchar(50) NULL AFTER `terminate`, ADD COLUMN `terminateDate` bigint(20) NULL AFTER `terminatedBy`, ADD COLUMN `terminatedStage` int(11) NULL AFTER `terminateDate`, ADD COLUMN `scheduled` tinyint(4) NULL AFTER `allDay`;
        ALTER TABLE `x2_admin` ADD COLUMN `kbHomeUrl` text NULL AFTER `outlookCredentialsId`, ADD COLUMN `kbForumsUrl` text NULL AFTER `kbHomeUrl`, ADD COLUMN `kbContactUrl` text NULL AFTER `kbForumsUrl`;
        ALTER TABLE `x2_contacts` ADD COLUMN `optIn` tinyint(4) NULL AFTER `doNotEmail`;
        ALTER TABLE `x2_docs` ADD COLUMN `info` tinyint(1) NULL DEFAULT '0' AFTER `folderId`, ADD COLUMN `redirectURL` varchar(250) NULL AFTER `info`, ADD COLUMN `gjsHtml` longtext NULL AFTER `redirectURL`, ADD COLUMN `gjsCss` longtext NULL AFTER `gjsHtml`, ADD COLUMN `gjsComponents` longtext NULL AFTER `gjsCss`, ADD COLUMN `gjsStyles` longtext NULL AFTER `gjsComponents`, ADD COLUMN `gjsAssets` longtext NULL AFTER `gjsStyles`, ADD COLUMN `edition` varchar(50) NULL DEFAULT 'responsive' AFTER `gjsAssets`;
        ALTER TABLE `x2_groups` ADD COLUMN `layout` text NULL AFTER `nameId`;
        ALTER TABLE `x2_list_items` ADD COLUMN `platform` varchar(50) NULL AFTER `urls`, ADD COLUMN `browser` varchar(50) NULL AFTER `platform`, ADD COLUMN `version` varchar(50) NULL AFTER `browser`, ADD COLUMN `modelTypes` text NULL AFTER `version`;
        ALTER TABLE `x2_lists` MODIFY COLUMN `modelName` varchar(100) NULL AFTER `logicType`;
        ALTER TABLE `x2_modules` ADD COLUMN `listable` tinyint(4) NULL DEFAULT '0' AFTER `linkOpenInFrame`;
        ALTER TABLE `x2_opportunities` ADD COLUMN `optIn` tinyint(4) NULL AFTER `doNotEmail`, MODIFY COLUMN `modelName` varchar(100) NULL AFTER `leadstatus`;
        ALTER TABLE `x2_profile` ADD COLUMN `currentLayout` varchar(255) NULL AFTER `historyShowRels`, ADD COLUMN `personalLayout` text NULL AFTER `currentLayout`, ADD COLUMN `appointmentCalendar` int(11) NULL AFTER `defaultCalendar`;
        ALTER TABLE `x2_quotes` ADD COLUMN `tax` decimal(18,2) NULL DEFAULT '0.00' AFTER `subtotal`, MODIFY COLUMN `modelName` varchar(100) NULL AFTER `leadstatus`;
        ALTER TABLE `x2_x2leads` ADD COLUMN `optIn` tinyint(4) NULL AFTER `doNotEmail`;
    END IF;
END$$
DELIMITER ;
CALL x2crm_apply_8_0_alters();
DROP PROCEDURE x2crm_apply_8_0_alters;

-- ---------------------------------------------------------------------
-- 3. x2_fields metadata for the new columns above. x2_fields has a real
--    UNIQUE key on (modelName, fieldName) (confirmed via SHOW KEYS), so
--    INSERT IGNORE gives genuine per-row idempotency here.
-- ---------------------------------------------------------------------

INSERT IGNORE INTO `x2_fields` (`attributeLabel`,`custom`,`data`,`defaultValue`,`description`,`fieldName`,`isVirtual`,`keyType`,`linkType`,`modelName`,`modified`,`readOnly`,`relevance`,`required`,`safe`,`searchable`,`type`,`uniqueConstraint`) VALUES ('Terminated By',0,NULL,NULL,NULL,'terminatedBy',0,NULL,NULL,'Actions',0,1,'',0,1,0,'assignment',0),('Info',0,NULL,NULL,NULL,'info',0,NULL,NULL,'Docs',0,0,NULL,0,1,0,'boolean',0),('terminated Stage',0,NULL,NULL,NULL,'terminatedStage',0,NULL,NULL,'Actions',0,0,'',0,1,0,'int',0),('Terminate',0,NULL,NULL,NULL,'terminate',0,NULL,NULL,'Actions',0,1,'',0,1,0,'varchar',0),('Scheduled',0,NULL,NULL,NULL,'scheduled',0,NULL,NULL,'Actions',0,0,'',0,1,0,'boolean',0),('Date Terminated',0,NULL,NULL,NULL,'terminateDate',0,NULL,NULL,'Actions',0,0,'',0,1,0,'dateTime',0),('Redirect URL',0,NULL,NULL,NULL,'redirectURL',0,NULL,NULL,'Docs',0,0,NULL,0,1,0,'varchar',0),('Tax',0,NULL,NULL,NULL,'tax',0,NULL,NULL,'Quote',0,0,'',0,1,0,'percentage',0);

-- ---------------------------------------------------------------------
-- 4. New RBAC permission names. x2_auth_item's PRIMARY KEY is `name`
--    itself (confirmed via SHOW KEYS — not a surrogate auto-increment
--    id), so INSERT IGNORE gives genuine per-row idempotency here too.
--    None of these ~90 new names collide with this stack's own custom
--    ones (ContactsWeblead, MailerliteResolveListMembers) — checked.
-- ---------------------------------------------------------------------

INSERT IGNORE INTO `x2_auth_item` (`bizrule`,`data`,`description`,`name`,`type`) VALUES (NULL,'N;','','ContactsConvert',0),(NULL,'N;','','MarketingMakeFull',0),(NULL,'N;','','OpportunitiesAddToList',0),(NULL,'N;','','OpportunitiesUpdateList',0),(NULL,'N;','','WorkflowTerminateProcess',0),(NULL,'N;','','AccountsDeleteList',0),(NULL,'N;','','ReportsLists',0),(NULL,'N;','','X2LeadsAddToList',0),(NULL,'N;','','ServicesLists',0),(NULL,'N;','','ServicesCreateList',0),(NULL,'N;','','MarketingGetListModelAttr',0),(NULL,'N;','','MarketingMyMarketing',0),(NULL,'N;','','WorkflowGetLists',0),(NULL,'N;','','MarketingLists',0),(NULL,'N;','','MarketingDeleteList',0),(NULL,'N;','','X2LeadsList',0),(NULL,'N;','','AccountsGetLists',0),(NULL,'N;','','X2LeadsMyX2Leads',0),(NULL,'N;','','ReportsUpdateList',0),(NULL,'N;','','ServicesList',0),(NULL,'N;','','MarketingUpdateBouncedEmails',0),(NULL,'N;','','AdminBounceHandlingSetup',0),(NULL,'N;','','MarketingNewMarketing',0),(NULL,'N;','','ActionsQuickUpdateGuest',0),(NULL,'N;','','MarketingFacebookRequest',0),(NULL,'N;','','MarketingLongTermCampaignCreate',0),(NULL,'N;','','MarketingCreateList',0),(NULL,'N;','','AccountsMyAccounts',0),(NULL,'N;','','MarketingLandingPages',0),(NULL,'N;','','ServicesDeleteList',0),(NULL,'N;','','BugReportsLists',0),(NULL,'N;','','OpportunitiesList',0),(NULL,'N;','','MarketingUpdateList',0),(NULL,'N;','','OpportunitiesLists',0),(NULL,'N;','','ReportsForecastReport',0),(NULL,'N;','','ProductsUpdateList',0),(NULL,'N;','','BugReportsDeleteList',0),(NULL,'N;','','AdminGetDashboardMetrics',0),(NULL,'N;','','ProductsGetLists',0),(NULL,'N;','','ReportsDeleteList',0),(NULL,'N;','','OpportunitiesMyOpportunity',0),(NULL,'N;','','AccountsCreateListFromSelection',0),(NULL,'N;','','AdminLogAnalyzer',0),(NULL,'N;','','CalendarEditActionGuest',0),(NULL,'N;','','CalendarAppointment',0),(NULL,'N;','Permissions for integrating the application the Outlook.','AdminOutlookIntegration',0),(NULL,'N;','','AdminCodeEditor',0),(NULL,'N;','','MarketingUnsubWebleadForm',0),(NULL,'N;','','X2LeadsDeleteList',0),(NULL,'N;','','MarketingList',0),(NULL,'N;','','CalendarOutlookSync',0),(NULL,'N;','','ProductsLists',0),(NULL,'N;','','ServicesUpdateList',0),(NULL,'N;','','OpportunitiesNewOpportunity',0),(NULL,'N;','','BugReportsList',0),(NULL,'N;','','ProductsDeleteList',0),(NULL,'N;','','X2LeadsCreateList',0),(NULL,'N;','','BugReportsGetLists',0),(NULL,'N;','','ProductsCreateList',0),(NULL,'N;','','X2LeadsRemoveFromList',0),(NULL,'N;','','AccountsAddToList',0),(NULL,'N;','','StudioResetCount',0),(NULL,'N;','','MarketingUnsubscribe',0),(NULL,'N;','','MarketingFacebook',0),(NULL,'N;','','ContactsPublic',0),(NULL,'N;','','AccountsRemoveFromList',0),(NULL,'N;','','BugReportsCreateList',0),(NULL,'N;','','X2LeadsNewX2Leads',0),(NULL,'N;','','ServicesGetLists',0),(NULL,'N;','','ReportsGetLists',0),(NULL,'N;','','X2LeadsGetLists',0),(NULL,'N;','','AccountsLists',0),(NULL,'N;','','ReportsList',0),(NULL,'N;','','ContactsGetInlineEmailContacts',0),(NULL,'N;','','AccountsList',0),(NULL,'N;','','AdminOutlookSync',0),(NULL,'N;','','AdminCodeBrowser',0),(NULL,'N;','','ProductsList',0),(NULL,'N;','','OpportunitiesNewOpportunities',0),(NULL,'N;','','X2LeadsLists',0),(NULL,'N;','','CalendarJsonFeedGuest',0),(NULL,'N;','','OpportunitiesCreateList',0),(NULL,'N;','','OpportunitiesDeleteList',0),(NULL,'N;','','X2LeadsCreateListFromSelection',0),(NULL,'N;','','OpportunitiesMyOpportunities',0),(NULL,'N;','','OpportunitiesRemoveFromList',0),(NULL,'N;','','AdminListProcesses',0),(NULL,'N;','','BugReportsUpdateList',0),(NULL,'N;','','OpportunitiesGetLists',0),(NULL,'N;','','ReportsCreateList',0),(NULL,'N;','','AccountsNewAccounts',0),(NULL,'N;','','AccountsUpdateList',0),(NULL,'N;','','AccountsCreateList',0),(NULL,'N;','View embed code for web tracker.','MarketingGetHeatMapData',0),(NULL,'N;','','X2LeadsUpdateList',0),(NULL,'N;','','MarketingGetLists',0),(NULL,'N;','','MarketingA_B_CampaignCreate',0),(NULL,'N;','','OpportunitiesCreateListFromSelection',0);

-- ---------------------------------------------------------------------
-- 5. X2Engine's own official 8.0 migration includes this EXACT line —
--    the same broken guest bizrule (isLoggedOut is not a real property;
--    should be isGuest) found and fixed live on this stack's production
--    database earlier this session. It's a genuine bug in X2Engine
--    Inc.'s own shipped upgrade script, confirmed straight from their
--    update server — not something specific to this migration. Kept
--    verbatim here (not stripped) for auditability against what X2Engine
--    actually ships, immediately followed by our corrective fix so it
--    always wins regardless of statement order.
-- ---------------------------------------------------------------------

UPDATE `x2_auth_item` SET `bizrule`='return Yii::app()->user->isLoggedOut;' WHERE `name`='guest';
UPDATE `x2_auth_item` SET `bizrule`='return Yii::app()->user->isGuest;' WHERE `name`='guest';

UPDATE `x2_auth_item` SET `bizrule`=NULL WHERE `name`='ActionsCaptcha';

-- ---------------------------------------------------------------------
-- 6. New RBAC parent/child links. x2_auth_item_child has a real composite
--    PRIMARY KEY (parent, child) (confirmed via SHOW KEYS), so INSERT
--    IGNORE gives genuine per-row idempotency here too.
-- ---------------------------------------------------------------------

INSERT IGNORE INTO `x2_auth_item_child` (`child`,`parent`) VALUES ('ContactsGetInlineEmailContacts','ContactsMinimumRequirements'),('X2LeadsDeleteList','X2LeadsDeletePrivate'),('AccountsList','AccountsMinimumRequirements'),('AccountsRemoveFromList','AccountsUpdatePrivate'),('AccountsUpdateList','AccountsUpdateAccess'),('X2LeadsRemoveFromList','X2LeadsUpdateAccess'),('OpportunitiesLists','OpportunitiesMinimumRequirements'),('OpportunitiesDeleteList','OpportunitiesDeletePrivate'),('X2LeadsAddToList','X2LeadsBasicAccess'),('AccountsDeleteList','AccountsDeletePrivate'),('WorkflowGetLists','AuthenticatedSiteFunctionsTask'),('AccountsDeleteList','AccountsFullAccess'),('AccountsRemoveFromList','AccountsUpdateAccess'),('X2LeadsCreateList','X2LeadsBasicAccess'),('OpportunitiesGetLists','OpportunitiesMinimumRequirements'),('ActionsQuickUpdateGuest','GuestSiteFunctionsTask'),('X2LeadsUpdateList','X2LeadsPrivateUpdateAccess'),('AccountsGetLists','AccountsMinimumRequirements'),('AccountsCreateListFromSelection','AccountsBasicAccess'),('X2LeadsDeleteList','X2LeadsFullAccess'),('X2LeadsGetLists','X2LeadsMinimumRequirements'),('AccountsCreateList','AccountsBasicAccess'),('AccountsLists','AccountsMinimumRequirements'),('OpportunitiesRemoveFromList','OpportunitiesUpdatePrivate'),('OpportunitiesCreateList','OpportunitiesBasicAccess'),('CalendarEditActionGuest','GuestSiteFunctionsTask'),('WorkflowTerminateProcess','AuthenticatedSiteFunctionsTask'),('AccountsAddToList','AccountsBasicAccess'),('OpportunitiesUpdateList','OpportunitiesUpdateAccess'),('OpportunitiesCreateListFromSelection','OpportunitiesBasicAccess'),('OpportunitiesRemoveFromList','OpportunitiesUpdateAccess'),('AccountsUpdateList','AccountsPrivateUpdateAccess'),('CalendarAppointment','GuestSiteFunctionsTask'),('X2LeadsUpdateList','X2LeadsUpdateAccess'),('OpportunitiesUpdateList','OpportunitiesPrivateUpdateAccess'),('X2LeadsList','X2LeadsMinimumRequirements'),('X2LeadsCreateListFromSelection','X2LeadsBasicAccess'),('OpportunitiesList','OpportunitiesMinimumRequirements'),('ActionsCaptcha','GuestSiteFunctionsTask'),('X2LeadsRemoveFromList','X2LeadsUpdatePrivate'),('OpportunitiesDeleteList','OpportunitiesFullAccess'),('X2LeadsLists','X2LeadsMinimumRequirements'),('OpportunitiesAddToList','OpportunitiesBasicAccess'),('MarketingGetHeatMapData','MarketingBasicAccess'),('CalendarJsonFeedGuest','GuestSiteFunctionsTask');

-- ---------------------------------------------------------------------
-- 7. Clear cached RBAC/permission resolution so the guest bizrule
--    correction above (and the new permission names) take effect
--    immediately — same reasoning as reconcile-custom-schema.sql's own
--    x2_auth_cache truncate.
-- ---------------------------------------------------------------------

TRUNCATE TABLE `x2_auth_cache`;

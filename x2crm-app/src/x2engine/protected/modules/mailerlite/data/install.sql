INSERT INTO `x2_modules`
(`name`, title, visible, menuPosition, searchable, editable, adminOnly, custom, toggleable)
VALUES
('mailerlite', 'MailerLite', 1, 91, 0, 1, 0, 1, 0);
/*&*/
-- Grants guest (no login session) access to actionResolveListMembers, the
-- server-to-server endpoint integration/server.js's auto-sync poller calls.
-- Same mechanism X2CRM's own ContactsWeblead uses for its public lead-form
-- endpoint — X2CRM's permission system (X2ControllerPermissionsBehavior)
-- checks these RBAC tables, not Yii's standard accessRules().
INSERT INTO `x2_auth_item` (`name`, `type`, `description`, `bizrule`, `data`)
VALUES ('MailerliteResolveListMembers', 0, 'Server-to-server: resolve a Contacts list for the MailerLite auto-sync poller.', NULL, 'N;');
/*&*/
INSERT INTO `x2_auth_item_child` (`parent`, `child`) VALUES
('GuestSiteFunctionsTask', 'MailerliteResolveListMembers'),
('AuthenticatedSiteFunctionsTask', 'MailerliteResolveListMembers');

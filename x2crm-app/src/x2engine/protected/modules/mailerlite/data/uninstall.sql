DELETE FROM `x2_modules` WHERE `name`='mailerlite';
DELETE FROM `x2_auth_item_child` WHERE `child`='MailerliteResolveListMembers';
DELETE FROM `x2_auth_item` WHERE `name`='MailerliteResolveListMembers';

-- wa_groups/wa_group_members are not dropped here: the wa-hub service owns
-- and continues to read/write them independently of this module's install state.
DELETE FROM `x2_modules` WHERE `name`='whatsappGroups';

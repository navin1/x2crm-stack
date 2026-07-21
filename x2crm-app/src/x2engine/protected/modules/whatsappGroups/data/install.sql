CREATE TABLE IF NOT EXISTS wa_groups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    groupId VARCHAR(100) UNIQUE,
    groupName VARCHAR(250) NOT NULL,
    subject VARCHAR(250),
    phoneNumber VARCHAR(20),
    isSynced TINYINT(1) DEFAULT 0,
    description TEXT,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastSyncedAt DATETIME NULL,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*&*/
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
/*&*/
INSERT INTO `x2_modules`
(`name`, title, visible, menuPosition, searchable, editable, adminOnly, custom, toggleable)
VALUES
('whatsappGroups', 'WhatsApp Groups', 1, 90, 0, 1, 0, 1, 0);
/*&*/
CREATE TABLE IF NOT EXISTS x2_custom_lead_forms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    url VARCHAR(255) NOT NULL,
    webFormId INT UNSIGNED NULL,
    tinyUrl VARCHAR(255) NULL,
    createdBy VARCHAR(50) NULL,
    createDate BIGINT NOT NULL,
    notifiedAt BIGINT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    deactivateAt BIGINT NULL,
    pracharakId INT UNSIGNED NULL,
    fields TEXT NULL,
    lastPolledAt BIGINT NULL
) COLLATE = utf8_general_ci;

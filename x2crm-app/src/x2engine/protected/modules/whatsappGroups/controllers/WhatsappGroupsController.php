<?php
/**
 * WhatsApp Groups Controller
 * Manages WhatsApp groups integration with wa-hub
 */

class WhatsappGroupsController extends x2base {

    public $modelClass = 'WhatsAppGroups';
    private $waHubUrl = 'http://wa_hub:3001';
    private $waHubUser = 'admin';
    private $waHubPass = '';

    /**
     * Initialize wa-hub credentials from env
     */
    public function init() {
        parent::init();
        // Get X2CRM API credentials to use for wa-hub auth
        $this->waHubUser = getenv('X2CRM_API_USERNAME') ?: 'admin';
        $this->waHubPass = getenv('X2CRM_API_KEY') ?: '';
    }

    /**
     * Filters for controller actions
     */
    public function filters() {
        return array(
            'setPortlets',
        );
    }

    /**
     * List all WhatsApp groups
     */
    public function actionIndex() {
        // Best-effort: a wa-hub hiccup here shouldn't take down the whole
        // groups list, just leave the status area showing "Unknown".
        $waStatus = array();
        try {
            $waStatus = $this->callWaHub('GET', '/admin/wa-status');
        } catch (Exception $e) {
            $waStatus = array();
        }

        try {
            $groups = $this->callWaHub('GET', '/admin/groups');

            $listIds = array_filter(array_unique(array_map(function ($g) {
                return isset($g['listId']) ? $g['listId'] : null;
            }, $groups)));
            $listNames = array();
            if (!empty($listIds)) {
                foreach (X2List::model()->findAllByPk($listIds) as $list) {
                    $listNames[$list->id] = $list->name;
                }
            }

            $dataProvider = new CArrayDataProvider($groups, array('pagination' => array('pageSize' => 20)));
            $this->render('index', array('dataProvider' => $dataProvider, 'groups' => $groups, 'listNames' => $listNames, 'waStatus' => $waStatus));
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', 'Error loading groups: ' . $e->getMessage());
            $this->render('index', array('dataProvider' => null, 'groups' => array(), 'listNames' => array(), 'waStatus' => $waStatus));
        }
    }

    /**
     * View group details with members
     */
    public function actionView($groupId) {
        try {
            $group = $this->callWaHub('GET', '/admin/groups/' . urlencode($groupId));

            if (!$group) {
                throw new CException('Group not found');
            }

            $linkedList = !empty($group['listId']) ? X2List::model()->findByPk($group['listId']) : null;

            $this->render('view', array(
                'group' => $group,
                'groupId' => $groupId,
                'linkedList' => $linkedList,
                'lists' => $this->getAccessibleContactLists(),
            ));
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', 'Error loading group: ' . $e->getMessage());
            $this->redirect(array('index'));
        }
    }

    /**
     * Create new WhatsApp group
     */
    public function actionCreate() {
        if (Yii::app()->request->isPostRequest) {
            try {
                $groupName = Yii::app()->request->getPost('groupName');
                $listId = Yii::app()->request->getPost('listId');
                $selectedContacts = Yii::app()->request->getPost('contacts', array());

                if (!$groupName) {
                    throw new CException('Group name is required');
                }

                // A linked dynamic list takes precedence over manual selection,
                // since its criteria is meant to be the (live) source of truth.
                if ($listId) {
                    $phones = $this->getListPhones($listId);
                } else {
                    $phones = array();
                    if (!empty($selectedContacts)) {
                        $contacts = Contacts::model()->findAllByPk($selectedContacts);
                        foreach ($contacts as $contact) {
                            if ($contact->phone) {
                                $phones[] = $contact->phone;
                            }
                        }
                    }
                }

                $payload = array(
                    'groupName' => $groupName,
                    'participants' => $phones,
                    'listId' => $listId ?: null,
                );

                $result = $this->callWaHub('POST', '/admin/groups', $payload);

                if (isset($result['ok']) && $result['ok']) {
                    Yii::app()->user->setFlash('success', 'WhatsApp group created successfully!');
                    $this->redirect(array('index'));
                } else {
                    throw new CException('Failed to create group: ' . (isset($result['error']) ? $result['error'] : 'Unknown error'));
                }
            } catch (Exception $e) {
                Yii::app()->user->setFlash('error', $e->getMessage());
            }
        }

        // Get all contacts for selection
        $allContacts = Contacts::model()->findAll(array('limit' => 1000));
        $this->render('create', array('contacts' => $allContacts, 'lists' => $this->getAccessibleContactLists()));
    }

    /**
     * Contact lists (X2CRM's "Lists" feature, filtered to Contacts-based
     * dynamic lists) that the current user is allowed to see, for use as a
     * live membership filter when creating/syncing a WhatsApp group.
     */
    private function getAccessibleContactLists() {
        $criteria = new CDbCriteria();
        $criteria->addCondition('modelName = "Contacts"');
        $criteria->addCondition('type = "dynamic"');
        if (!Yii::app()->params->isAdmin) {
            $condition = 'visibility="1" OR assignedTo="Anyone" OR assignedTo="' . Yii::app()->user->getName() . '"';
            $groupLinks = Yii::app()->db->createCommand()
                ->select('groupId')->from('x2_group_to_user')
                ->where('userId=' . Yii::app()->user->getId())->queryColumn();
            if (!empty($groupLinks)) {
                $condition .= ' OR assignedTo IN (' . implode(',', $groupLinks) . ')';
            }
            $criteria->addCondition($condition);
        }
        $criteria->order = 'createDate DESC';
        return X2List::model()->findAll($criteria);
    }

    /**
     * Resolves a dynamic X2CRM list's current live criteria to the phone
     * numbers of its matching Contacts right now.
     */
    private function getListPhones($listId) {
        $list = X2List::load($listId);
        if (!$list || $list->modelName !== 'Contacts') {
            throw new CException('List not found');
        }
        $contacts = Contacts::model()->findAll($list->queryCriteria());
        $phones = array();
        foreach ($contacts as $contact) {
            if ($contact->phone) {
                $phones[] = $contact->phone;
            }
        }
        return $phones;
    }

    /**
     * Re-syncs a group's WhatsApp membership to match its linked list's
     * current criteria results (adds newly-matching contacts, removes
     * contacts that no longer match).
     */
    public function actionSyncMembers($groupId) {
        try {
            $group = $this->callWaHub('GET', '/admin/groups/' . urlencode($groupId));
            if (!$group || empty($group['listId'])) {
                throw new CException('This group is not linked to a list');
            }

            $phones = $this->getListPhones($group['listId']);
            $result = $this->callWaHub('POST', '/admin/groups/' . urlencode($groupId) . '/sync-members', array('phones' => $phones));

            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', "Synced with list: added {$result['added']}, removed {$result['removed']} (now {$result['total']} matching contacts)");
            } else {
                throw new CException(isset($result['error']) ? $result['error'] : 'Sync failed');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', 'Sync error: ' . $e->getMessage());
        }

        $this->redirect(array('view', 'groupId' => $groupId));
    }

    /**
     * Link or unlink an existing group to a dynamic list.
     */
    public function actionLinkList() {
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $groupId = Yii::app()->request->getPost('groupId');
        $listId = Yii::app()->request->getPost('listId');

        try {
            $result = $this->callWaHub('POST', '/admin/groups/' . urlencode($groupId) . '/link-list', array('listId' => $listId ?: null));
            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', $listId ? 'List linked' : 'List unlinked');
            } else {
                throw new CException(isset($result['error']) ? $result['error'] : 'Failed to link list');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('view', 'groupId' => $groupId));
    }

    /**
     * Toggle whether a group receives the "new lead created" broadcast
     * (a courtesy copy of the same pracharak-assignment notification,
     * posted into the group instead of/in addition to the personal DM).
     * The message always originates from wa-hub's single paired WhatsApp
     * number — there's no separate sender identity to configure — the
     * only real prerequisite is that account already being a member of
     * this group.
     */
    public function actionToggleNotifyNewLead() {
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $groupId = Yii::app()->request->getPost('groupId');
        $enabled = Yii::app()->request->getPost('enabled');

        try {
            $result = $this->callWaHub('POST', '/admin/groups/' . urlencode($groupId) . '/notify-new-lead', array('enabled' => (bool) $enabled));
            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', $enabled ? 'New-lead notifications enabled for this group.' : 'New-lead notifications disabled for this group.');
            } else {
                throw new CException(isset($result['error']) ? $result['error'] : 'Failed to update notification setting');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('view', 'groupId' => $groupId));
    }

    /**
     * Admin-only editor for the new-lead WhatsApp group broadcast's wording
     * (see wa-hub's renderLeadNotifyTemplate()). Does not affect the
     * personal DM the assigned pracharak already gets — only the courtesy
     * copy posted into whichever group(s) have notifications toggled on.
     */
    public function actionEditNotifyTemplate() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }

        try {
            $result = $this->callWaHub('GET', '/admin/lead-notify-template');
            $template = isset($result['template']) ? $result['template'] : '';
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', 'Error loading template: ' . $e->getMessage());
            $template = '';
        }

        $this->render('notifyTemplate', array('template' => $template));
    }

    /**
     * Saves the new-lead group broadcast wording.
     */
    public function actionSaveNotifyTemplate() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $template = Yii::app()->request->getPost('template', '');

        try {
            if (trim($template) === '') {
                throw new CException('Template cannot be empty');
            }
            $result = $this->callWaHub('POST', '/admin/lead-notify-template', array('template' => $template));
            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', 'New-lead message template updated.');
            } else {
                throw new CException(isset($result['error']) ? $result['error'] : 'Failed to save template');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('editNotifyTemplate'));
    }

    /**
     * Sync groups from WhatsApp
     */
    public function actionSync() {
        try {
            $result = $this->callWaHub('POST', '/admin/groups/sync-all');
            
            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', 'Synced ' . $result['synced'] . ' groups from WhatsApp');
            } else {
                throw new CException($result['error'] ?? 'Sync failed');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', 'Sync error: ' . $e->getMessage());
        }

        $this->redirect(array('index'));
    }

    /**
     * Add members to group
     */
    public function actionAddMembers() {
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        try {
            $groupId = Yii::app()->request->getPost('groupId');
            $selectedContacts = Yii::app()->request->getPost('contacts', array());

            if (!$groupId) {
                throw new CException('Group ID is required');
            }

            // Get phone numbers
            $phones = array();
            if (!empty($selectedContacts)) {
                $contacts = Contacts::model()->findAllByPk($selectedContacts);
                foreach ($contacts as $contact) {
                    if ($contact->phone) {
                        $phones[] = $contact->phone;
                    }
                }
            }

            if (empty($phones)) {
                throw new CException('No contacts with phone numbers selected');
            }

            $payload = array('phones' => $phones);
            $result = $this->callWaHub('POST', '/admin/groups/' . urlencode($groupId) . '/members', $payload);

            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', 'Added ' . $result['added'] . ' members to group');
            } else {
                throw new CException($result['error'] ?? 'Failed to add members');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $groupId = Yii::app()->request->getPost('groupId');
        $this->redirect(array('view', 'groupId' => $groupId));
    }

    /**
     * Remove member from group
     */
    public function actionRemoveMember($groupId, $phone) {
        try {
            $result = $this->callWaHub('DELETE', '/admin/groups/' . urlencode($groupId) . '/members/' . urlencode($phone));

            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', 'Member removed from group');
            } else {
                throw new CException($result['error'] ?? 'Failed to remove member');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('view', 'groupId' => $groupId));
    }

    /**
     * Rename a group
     */
    public function actionRename() {
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $groupId = Yii::app()->request->getPost('groupId');
        $groupName = Yii::app()->request->getPost('groupName');

        try {
            if (!$groupName) {
                throw new CException('Group name is required');
            }
            $result = $this->callWaHub('POST', '/admin/groups/' . urlencode($groupId) . '/rename', array('groupName' => $groupName));

            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', 'Group renamed');
            } else {
                throw new CException(isset($result['error']) ? $result['error'] : 'Failed to rename group');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('view', 'groupId' => $groupId));
    }

    /**
     * Delete (leave) a group. WhatsApp has no "delete for everyone" API for
     * groups, so this leaves the group on WhatsApp's side (which fully
     * removes it if wa-hub's account was the only member) and always drops
     * X2CRM's own tracking of it either way.
     */
    public function actionDelete($groupId) {
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        try {
            $result = $this->callWaHub('DELETE', '/admin/groups/' . urlencode($groupId));

            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', 'Group deleted');
            } else {
                throw new CException(isset($result['error']) ? $result['error'] : 'Failed to delete group');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('index'));
    }

    /**
     * Resolves the Contacts list literally named "Pracharak" (a normal
     * X2CRM Contact List — static or dynamic, either works since both
     * resolve through queryCriteria()) into the Contacts that currently
     * match it. This list, not a dedicated table, is the pracharak
     * roster for Web Form Notifications: managing who's on it is just
     * managing X2CRM Contacts and List membership like any other list, and
     * adding an existing Contact to it is enough to make them assignable —
     * no separate storage of pracharak data at all. Returns null if no
     * such list exists yet.
     */
    private function getPracharakContacts() {
        $list = X2List::model()->find(
            'name=:name AND modelName=:modelName',
            array(':name' => 'Pracharak', ':modelName' => 'Contacts')
        );
        if (!$list) {
            return null;
        }
        $contacts = Contacts::model()->findAll($list->queryCriteria());
        $result = array();
        foreach ($contacts as $c) {
            if (!empty($c->phone)) {
                $result[] = array(
                    'id' => $c->id,
                    'name' => trim($c->firstName . ' ' . $c->lastName) ?: $c->name,
                    'phone' => $c->phone,
                );
            }
        }
        return $result;
    }

    /**
     * Admin-only registry of X2CRM's native Web Lead Forms (the ones built
     * and given an iframe embed code at marketing/webleadForm) — lets you
     * pick which pracharak gets a WhatsApp message for each form's
     * submissions, and change that choice at any time.
     */
    public function actionWebFormNotify() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        $this->ensureWebFormManagementColumns();
        $forms = Yii::app()->db->createCommand()
            ->select('*')
            ->from('x2_web_forms')
            ->where('type=:type', array(':type' => 'weblead'))
            ->order('id DESC')
            ->queryAll();
        $pracharaks = $this->getPracharakContacts();
        $notifyMap = array();
        foreach (Yii::app()->db->createCommand()->select('*')->from('wa_webform_notify')->queryAll() as $row) {
            $notifyMap[$row['webFormId']] = $row['pracharakId'];
        }
        $this->render('webFormNotify', array(
            'forms' => $forms,
            'pracharaks' => $pracharaks === null ? array() : $pracharaks,
            'hasPracharakList' => $pracharaks !== null,
            'notifyMap' => $notifyMap,
            'hostInfo' => rtrim(Yii::app()->request->getHostInfo(), '/'),
        ));
    }

    /**
     * Sets, changes, or clears (empty selection) the WhatsApp notification
     * recipient for one native Web Lead Form. Most native forms are built
     * without generateLead/leadSource set (they only ever create a
     * Contact) — wa-hub's poller needs a leadSource-tagged Lead record to
     * detect "a submission happened for this form", so assigning a
     * recipient here backfills both, the same way the pracharak-form
     * builder above (actionCreatePracharakForm) already does for its own
     * generated forms.
     */
    public function actionSaveWebFormNotify() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $this->ensureWebFormManagementColumns();
        $webFormId = (int) Yii::app()->request->getPost('webFormId');
        $pracharakId = Yii::app()->request->getPost('pracharakId', '');

        try {
            $form = Yii::app()->db->createCommand()
                ->select('id, name, leadSource, generateLead')
                ->from('x2_web_forms')
                ->where('id=:id', array(':id' => $webFormId))
                ->queryRow();
            if (!$form) {
                throw new CException('Form not found');
            }

            if ($pracharakId === '') {
                Yii::app()->db->createCommand()->delete('wa_webform_notify', 'webFormId=:id', array(':id' => $webFormId));
                Yii::app()->user->setFlash('success', 'WhatsApp notifications turned off for "' . $form['name'] . '".');
            } else {
                $validIds = array_map(function ($sp) { return (string) $sp['id']; }, $this->getPracharakContacts() ?: array());
                if (!in_array((string) $pracharakId, $validIds, true)) {
                    throw new CException('That contact is not currently in the "Pracharak" list.');
                }

                if (empty($form['leadSource'])) {
                    Yii::app()->db->createCommand()->update('x2_web_forms',
                        array('leadSource' => 'WebForm-' . $webFormId, 'generateLead' => 1),
                        'id=:id', array(':id' => $webFormId));
                } elseif (!$form['generateLead']) {
                    Yii::app()->db->createCommand()->update('x2_web_forms',
                        array('generateLead' => 1), 'id=:id', array(':id' => $webFormId));
                }

                $exists = Yii::app()->db->createCommand()
                    ->select('webFormId')->from('wa_webform_notify')
                    ->where('webFormId=:id', array(':id' => $webFormId))
                    ->queryScalar();
                if ($exists !== false) {
                    Yii::app()->db->createCommand()->update('wa_webform_notify',
                        array('pracharakId' => $pracharakId),
                        'webFormId=:id', array(':id' => $webFormId));
                } else {
                    Yii::app()->db->createCommand()->insert('wa_webform_notify', array(
                        'webFormId' => $webFormId,
                        'pracharakId' => $pracharakId,
                        // Start the watermark at "now" — only notify about
                        // submissions from this point forward, not every
                        // historical lead already sitting on this leadSource.
                        'lastPolledAt' => time(),
                    ));
                }
                Yii::app()->user->setFlash('success', 'WhatsApp notifications updated for "' . $form['name'] . '".');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('webFormNotify'));
    }

    /**
     * X2CRM's native x2_web_forms table has no built-in "pause this form"
     * concept — unlike x2_custom_lead_forms (which already has
     * active/deactivateAt for the pracharak-form feature above), so
     * activation/deactivation for native Web Lead Forms needs these two
     * columns added. Safe to call on every request that needs them: a
     * no-op once the columns exist, and idempotent if two requests race.
     */
    private function ensureWebFormManagementColumns() {
        $db = Yii::app()->db;
        $hasActive = $db->createCommand(
            "SELECT COUNT(*) FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x2_web_forms' AND COLUMN_NAME = 'active'"
        )->queryScalar();
        if (!$hasActive) {
            $db->createCommand("ALTER TABLE x2_web_forms ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1")->execute();
        }
        $hasDeactivateAt = $db->createCommand(
            "SELECT COUNT(*) FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x2_web_forms' AND COLUMN_NAME = 'deactivateAt'"
        )->queryScalar();
        if (!$hasDeactivateAt) {
            $db->createCommand("ALTER TABLE x2_web_forms ADD COLUMN deactivateAt BIGINT NULL")->execute();
        }
    }

    /**
     * Forced, immediate deactivation of a native Web Lead Form — enforced
     * server-side in WebFormAction::run() (not just a client-side notice),
     * so the iframe stops accepting submissions right away wherever it's
     * embedded.
     */
    public function actionDeactivateWebForm($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }
        $this->ensureWebFormManagementColumns();
        Yii::app()->db->createCommand()->update('x2_web_forms', array('active' => 0), 'id=:id', array(':id' => $id));
        Yii::app()->user->setFlash('success', 'Form deactivated.');
        $this->redirect(array('webFormNotify'));
    }

    /**
     * Undoes a forced deactivation and clears any scheduled deactivation
     * timestamp — reactivating means "make it live again", not "reschedule
     * for later".
     */
    public function actionReactivateWebForm($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }
        $this->ensureWebFormManagementColumns();
        Yii::app()->db->createCommand()->update('x2_web_forms',
            array('active' => 1, 'deactivateAt' => null), 'id=:id', array(':id' => $id));
        Yii::app()->user->setFlash('success', 'Form reactivated.');
        $this->redirect(array('webFormNotify'));
    }

    /**
     * Sets or clears (blank input) the scheduled auto-deactivation
     * timestamp for a native Web Lead Form.
     */
    public function actionScheduleWebFormDeactivation($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }
        $this->ensureWebFormManagementColumns();
        $deactivateAt = $this->parseDeactivateAt(Yii::app()->request->getPost('deactivateAt'));
        Yii::app()->db->createCommand()->update('x2_web_forms',
            array('deactivateAt' => $deactivateAt), 'id=:id', array(':id' => $id));
        Yii::app()->user->setFlash('success', $deactivateAt
            ? 'Scheduled deactivation set for ' . date('M j, Y g:i A', $deactivateAt) . '.'
            : 'Scheduled deactivation cleared.');
        $this->redirect(array('webFormNotify'));
    }

    /**
     * Admin-only registry of custom-styled public lead-capture forms
     * (like leadform.html) — lists them with QR codes / short links, and
     * lets you register new ones, notifying yourself over WhatsApp with the
     * URL, a QR code, and a tinyurl.com short link each time.
     */
    public function actionLeadForms() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        $forms = Yii::app()->db->createCommand()
            ->select('*')
            ->from('x2_custom_lead_forms')
            ->order('id DESC')
            ->queryAll();
        $pracharaks = $this->getPracharakContacts();
        $pracharaksById = array();
        foreach (($pracharaks ?: array()) as $sp) {
            $pracharaksById[$sp['id']] = $sp;
        }
        $this->render('leadForms', array(
            'forms' => $forms,
            'pracharaks' => $pracharaks === null ? array() : $pracharaks,
            'hasPracharakList' => $pracharaks !== null,
            'pracharaksById' => $pracharaksById,
            'fieldCatalog' => $this->getLeadFormFieldCatalog(),
        ));
    }

    /**
     * A pracharak's own personal lead-capture form: a real, dedicated
     * X2CRM WebForm (own webFormId + leadSource, so submissions can be
     * correlated back to exactly this pracharak) with a checkbox-picked
     * subset of fields, rendered as a standalone HTML file written
     * straight into the docroot (same reasoning as leadform.html: same
     * origin as the weblead endpoint avoids all CSRF/CORS/guest-permission
     * complications a dynamically-served PHP page would run into here).
     * A "pracharak" is a Contact in the "Pracharak" Contact List (see
     * getPracharakContacts()) — not tied to an X2CRM user account, and
     * not stored separately; the list membership is the only roster.
     */
    public function actionCreatePracharakForm() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $formName = trim(Yii::app()->request->getPost('formName'));
        $pracharakId = Yii::app()->request->getPost('pracharakId');
        $selectedFields = Yii::app()->request->getPost('fields', array());
        $deactivateAt = $this->parseDeactivateAt(Yii::app()->request->getPost('deactivateAt'));

        try {
            if (!$formName) {
                throw new CException('Form name is required');
            }

            $pracharaksById = array();
            foreach (($this->getPracharakContacts() ?: array()) as $sp) {
                $pracharaksById[(string) $sp['id']] = $sp;
            }
            if (!isset($pracharaksById[(string) $pracharakId])) {
                throw new CException('That contact is not currently in the "Pracharak" list.');
            }
            $pracharakName = $pracharaksById[(string) $pracharakId]['name'];

            $catalog = $this->getLeadFormFieldCatalog();
            $selectedFields = array_values(array_intersect($selectedFields, array_keys($catalog)));

            // Insert the registry row first to get an id — used both as the
            // uniqueness key for leadSource (so submissions from this exact
            // form can be told apart from every other pracharak's form)
            // and in the generated file's own status-check calls.
            $now = time();
            Yii::app()->db->createCommand()->insert('x2_custom_lead_forms', array(
                'name' => $formName,
                'url' => '',
                'createdBy' => Yii::app()->user->getName(),
                'createDate' => $now,
                'deactivateAt' => $deactivateAt,
                'pracharakId' => $pracharakId,
                'fields' => json_encode($selectedFields),
                'lastPolledAt' => $now,
            ));
            $registryId = Yii::app()->db->getLastInsertID();

            $leadSource = 'SalesForm-' . $registryId;
            Yii::app()->db->createCommand()->insert('x2_web_forms', array(
                'name' => $formName . ' (' . $pracharakName . ')',
                'type' => 'weblead',
                'description' => 'Personal lead form for ' . $pracharakName,
                'modelName' => 'Contacts',
                'visibility' => 1,
                'assignedTo' => 'Anyone',
                'createdBy' => Yii::app()->user->getName(),
                'updatedBy' => Yii::app()->user->getName(),
                'createDate' => $now,
                'lastUpdated' => $now,
                'leadSource' => $leadSource,
                'redirectUrl' => '/leadform-thanks.html',
                'generateLead' => 1,
                'generateAccount' => 0,
                'requireCaptcha' => 0,
                'fingerprintDetection' => 1,
            ));
            $webFormId = Yii::app()->db->getLastInsertID();

            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim($pracharakName)));
            $filename = 'leadform-' . trim($slug, '-') . '-' . $registryId . '.html';
            $docRoot = dirname(Yii::app()->basePath);
            $filePath = $docRoot . DIRECTORY_SEPARATOR . $filename;

            $html = $this->renderLeadFormTemplate($selectedFields, $catalog, $webFormId, $registryId, $pracharakName);
            if (file_put_contents($filePath, $html) === false) {
                throw new CException('Failed to write form file to ' . $filePath);
            }

            $publicUrl = rtrim(Yii::app()->request->getHostInfo(), '/') . '/' . $filename;
            Yii::app()->db->createCommand()->update('x2_custom_lead_forms',
                array('url' => $publicUrl, 'webFormId' => $webFormId),
                'id=:id', array(':id' => $registryId)
            );

            $this->sendLeadFormNotification($registryId, $formName . ' (' . $pracharakName . ')', $publicUrl);

            Yii::app()->user->setFlash('success', "\"$formName\" created for $pracharakName at $publicUrl — WhatsApp notification sent.");
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('leadForms'));
    }

    /**
     * Fixed catalog of optional fields a pracharak form can include,
     * beyond the always-present firstName/lastName/email — real Contacts
     * columns only, so `Contacts[fieldName]` submissions map cleanly.
     */
    private function getLeadFormFieldCatalog() {
        return array(
            'phone' => array('label' => 'Phone', 'type' => 'tel', 'placeholder' => '+1 555 123 4567'),
            'company' => array('label' => 'Company', 'type' => 'text', 'placeholder' => ''),
            'title' => array('label' => 'Job Title', 'type' => 'text', 'placeholder' => ''),
            'city' => array('label' => 'City', 'type' => 'text', 'placeholder' => ''),
            'website' => array('label' => 'Website', 'type' => 'url', 'placeholder' => 'https://'),
            'backgroundInfo' => array('label' => 'Message', 'type' => 'textarea', 'placeholder' => 'Tell us a bit about what you need...'),
        );
    }

    /**
     * Renders a standalone lead-form HTML page — same visual design and
     * CSRF/status-check JS as leadform.html, but with a dynamic field list
     * and its own webFormId / registry id baked in.
     */
    private function renderLeadFormTemplate($selectedFields, $catalog, $webFormId, $registryId, $heading) {
        $fieldsHtml = '';
        foreach ($selectedFields as $key) {
            if (!isset($catalog[$key])) continue;
            $f = $catalog[$key];
            $label = CHtml::encode($f['label']);
            $placeholder = CHtml::encode($f['placeholder']);
            if ($f['type'] === 'textarea') {
                $fieldsHtml .= "\n      <div class=\"field\">\n        <label for=\"$key\">$label</label>\n        <textarea id=\"$key\" name=\"Contacts[$key]\" placeholder=\"$placeholder\"></textarea>\n      </div>\n";
            } else {
                $type = CHtml::encode($f['type']);
                $fieldsHtml .= "\n      <div class=\"field\">\n        <label for=\"$key\">$label</label>\n        <input type=\"$type\" id=\"$key\" name=\"Contacts[$key]\" placeholder=\"$placeholder\">\n      </div>\n";
            }
        }

        $webFormId = (int) $webFormId;
        $registryId = (int) $registryId;
        $title = CHtml::encode('Get in Touch with ' . $heading);

        // Same logo X2CRM shows in its own top menu bar (Administration >
        // General Settings > upload logo) — falls back to the generic icon
        // below only if no custom logo has been uploaded, matching the same
        // "is this still the placeholder file" check main.php's own layout
        // uses (protected/views/layouts/main.php).
        $menuLogo = Media::getMenuLogo();
        $logoHtml = ($menuLogo && $menuLogo->fileName !== 'uploads/protected/logos/yourlogohere.png')
            ? '<img src="' . CHtml::encode($menuLogo->getPublicUrl()) . '" alt="" style="display:block;width:56px;height:56px;border-radius:14px;object-fit:contain;margin:0 auto 20px;">'
            : '<div class="logo-placeholder"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
                . '<path d="M3 21V8l9-5 9 5v13h-6v-7H9v7H3z" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg></div>';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
  :root {
    --accent: #6366f1;
    --accent-dark: #4f46e5;
    --ink: #1f2333;
    --muted: #6b7280;
    --error: #dc2626;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  }
  .card {
    width: 100%;
    max-width: 480px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    padding: 40px 36px;
  }
  .logo-placeholder {
    display: block;
    margin: 0 auto 20px;
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .logo-placeholder svg { width: 28px; height: 28px; }
  .card h1 { margin: 0 0 6px; font-size: 24px; color: var(--ink); text-align: center; }
  .card p.subtitle { margin: 0 0 28px; color: var(--muted); font-size: 15px; text-align: center; }
  .field { margin-bottom: 18px; }
  .field label { display: block; font-size: 13px; font-weight: 600; color: var(--ink); margin-bottom: 6px; }
  .field input, .field textarea {
    width: 100%; padding: 11px 13px; border: 1.5px solid #e5e7eb; border-radius: 8px;
    font-size: 15px; font-family: inherit; color: var(--ink); transition: border-color 0.15s;
  }
  .field input:focus, .field textarea:focus { outline: none; border-color: var(--accent); }
  .field textarea { resize: vertical; min-height: 80px; }
  .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  button[type="submit"] {
    width: 100%; padding: 13px; margin-top: 8px; border: none; border-radius: 8px;
    background: var(--accent); color: #fff; font-size: 16px; font-weight: 600;
    cursor: pointer; transition: background 0.15s, transform 0.1s;
  }
  button[type="submit"]:hover { background: var(--accent-dark); }
  button[type="submit"]:active { transform: scale(0.99); }
  button[type="submit"]:disabled { background: #a5a6f6; cursor: not-allowed; }
  .status { display: none; margin-bottom: 18px; padding: 10px 14px; border-radius: 8px; font-size: 14px; }
  .status.show { display: block; }
  .status.error { background: #fef2f2; color: var(--error); border: 1px solid #fecaca; }
  .footnote { margin: 18px 0 0; text-align: center; font-size: 12px; color: var(--muted); }
  .inactive-notice { display: none; text-align: center; padding: 20px 0; }
  .inactive-notice.show { display: block; }
  .inactive-notice svg { width: 40px; height: 40px; margin-bottom: 12px; }
  .inactive-notice p { color: var(--muted); font-size: 15px; margin: 0; }
</style>
</head>
<body>
  <div class="card">
    {$logoHtml}
    <h1>{$title}</h1>
    <p class="subtitle">Fill out the form below and we'll reach out shortly.</p>

    <div class="status error" id="statusBox"></div>

    <div class="inactive-notice" id="inactiveNotice">
      <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="12" cy="12" r="9" stroke="#9ca3af" stroke-width="1.8"/>
        <path d="M9 9l6 6M15 9l-6 6" stroke="#9ca3af" stroke-width="1.8" stroke-linecap="round"/>
      </svg>
      <p id="inactiveMessage">This form is no longer accepting submissions.</p>
    </div>

    <form id="leadForm" method="POST" action="/index.php/contacts/contacts/weblead?webFormId={$webFormId}">
      <input type="hidden" name="YII_CSRF_TOKEN" id="csrfToken" value="">

      <div class="row-2">
        <div class="field">
          <label for="firstName">First name *</label>
          <input type="text" id="firstName" name="Contacts[firstName]" required>
        </div>
        <div class="field">
          <label for="lastName">Last name *</label>
          <input type="text" id="lastName" name="Contacts[lastName]" required>
        </div>
      </div>

      <div class="field">
        <label for="email">Email *</label>
        <input type="email" id="email" name="Contacts[email]" required>
      </div>
{$fieldsHtml}
      <button type="submit" id="submitBtn">Submit</button>
    </form>

    <p class="footnote">Your information is kept private and used only to get back to you.</p>
  </div>

<script>
(function () {
  var LEAD_FORM_ID = {$registryId};
  var FORM_STATUS_URL = '/form-status/' + LEAD_FORM_ID;

  var form = document.getElementById('leadForm');
  var submitBtn = document.getElementById('submitBtn');
  var statusBox = document.getElementById('statusBox');
  var csrfInput = document.getElementById('csrfToken');
  var inactiveNotice = document.getElementById('inactiveNotice');
  var inactiveMessage = document.getElementById('inactiveMessage');

  function showError(msg) {
    statusBox.textContent = msg;
    statusBox.classList.add('show');
  }

  function showInactive(reason) {
    form.style.display = 'none';
    inactiveMessage.textContent = reason === 'scheduled'
      ? 'This form has expired and is no longer accepting submissions.'
      : 'This form is no longer accepting submissions.';
    inactiveNotice.classList.add('show');
  }

  function checkStatus() {
    return fetch(FORM_STATUS_URL)
      .then(function (r) { return r.json(); })
      .then(function (data) { return data.active !== false; })
      .catch(function () { return true; });
  }

  function initForm() {
    submitBtn.disabled = true;
    fetch('/index.php/contacts/contacts/weblead', { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var match = html.match(/name="YII_CSRF_TOKEN"[^>]*value="([^"]+)"/) ||
                    html.match(/value="([^"]+)"[^>]*name="YII_CSRF_TOKEN"/);
        if (match) {
          csrfInput.value = match[1];
          submitBtn.disabled = false;
        } else {
          showError('Could not initialize the form. Please refresh the page and try again.');
        }
      })
      .catch(function () {
        showError('Could not reach the server. Please check your connection and try again.');
      });
  }

  fetch(FORM_STATUS_URL)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.active === false) {
        showInactive(data.reason);
      } else {
        initForm();
      }
    })
    .catch(function () {
      initForm();
    });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';
    checkStatus().then(function (active) {
      if (active) {
        form.submit();
      } else {
        showInactive('deactivated');
      }
    });
  });
})();
</script>
</body>
</html>
HTML;
    }

    /**
     * Registers a new lead form (name + URL) and sends the WhatsApp
     * notification (QR + tinyurl) for it right away.
     */
    public function actionRegisterLeadForm() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $name = trim(Yii::app()->request->getPost('name'));
        $url = trim(Yii::app()->request->getPost('url'));
        $webFormId = Yii::app()->request->getPost('webFormId') ?: null;
        $deactivateAt = $this->parseDeactivateAt(Yii::app()->request->getPost('deactivateAt'));

        try {
            if (!$name || !$url) {
                throw new CException('Name and URL are required');
            }

            $now = time();
            Yii::app()->db->createCommand()->insert('x2_custom_lead_forms', array(
                'name' => $name,
                'url' => $url,
                'webFormId' => $webFormId,
                'createdBy' => Yii::app()->user->getName(),
                'createDate' => $now,
                'deactivateAt' => $deactivateAt,
            ));
            $id = Yii::app()->db->getLastInsertID();

            $this->sendLeadFormNotification($id, $name, $url);

            Yii::app()->user->setFlash('success', "\"$name\" registered and WhatsApp notification sent.");
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('leadForms'));
    }

    /**
     * Re-sends the WhatsApp notification for an already-registered form
     * (e.g. if the first attempt failed because WhatsApp wasn't connected).
     */
    public function actionNotifyLeadForm($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        try {
            $form = Yii::app()->db->createCommand()
                ->select('*')->from('x2_custom_lead_forms')->where('id=:id', array(':id' => $id))
                ->queryRow();
            if (!$form) {
                throw new CException('Form not found');
            }

            $this->sendLeadFormNotification($id, $form['name'], $form['url']);
            Yii::app()->user->setFlash('success', 'Notification re-sent.');
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('leadForms'));
    }

    /**
     * Forced, immediate deactivation — the public form starts showing a
     * "no longer accepting submissions" message right away (checked via
     * wa-hub's GET /form-status/:id, polled by the static page's own JS).
     */
    public function actionDeactivateLeadForm($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        Yii::app()->db->createCommand()->update('x2_custom_lead_forms',
            array('active' => 0), 'id=:id', array(':id' => $id));
        Yii::app()->user->setFlash('success', 'Form deactivated.');

        $this->redirect(array('leadForms'));
    }

    /**
     * Undoes a forced deactivation and clears any scheduled deactivation
     * datetime (reactivating implies "make it live again", not "reschedule
     * for later" — set a new schedule separately if that's what's wanted).
     */
    public function actionReactivateLeadForm($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        Yii::app()->db->createCommand()->update('x2_custom_lead_forms',
            array('active' => 1, 'deactivateAt' => null), 'id=:id', array(':id' => $id));
        Yii::app()->user->setFlash('success', 'Form reactivated.');

        $this->redirect(array('leadForms'));
    }

    /**
     * Sets or clears the scheduled auto-deactivation datetime on an
     * already-registered form, without needing to re-register it.
     */
    public function actionScheduleLeadFormDeactivation($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $deactivateAt = $this->parseDeactivateAt(Yii::app()->request->getPost('deactivateAt'));
        Yii::app()->db->createCommand()->update('x2_custom_lead_forms',
            array('deactivateAt' => $deactivateAt), 'id=:id', array(':id' => $id));

        Yii::app()->user->setFlash('success', $deactivateAt
            ? 'Scheduled deactivation set for ' . date('M j, Y g:i A', $deactivateAt) . '.'
            : 'Scheduled deactivation cleared.');

        $this->redirect(array('leadForms'));
    }

    /**
     * Permanently removes a registered lead-form URL. Two things beyond the
     * registry row itself are only cleaned up when this exact row is what
     * created them (never for a "Register an Existing Form URL" entry that
     * merely points at something built/hosted elsewhere):
     *  - the generated HTML file, if its name matches the exact
     *    "leadform-<slug>-<this id>.html" pattern actionCreatePracharakForm
     *    writes — never the shared leadform.html template, and never an
     *    externally-hosted URL, since neither can match that pattern;
     *  - the linked x2_web_forms row (and any wa_webform_notify assignment
     *    for it), only when its leadSource is exactly "SalesForm-<this id>",
     *    i.e. it was auto-created solely for this registry row and nothing
     *    else could be relying on it.
     */
    public function actionDeleteLeadForm($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        try {
            $form = Yii::app()->db->createCommand()
                ->select('*')->from('x2_custom_lead_forms')->where('id=:id', array(':id' => $id))
                ->queryRow();
            if (!$form) {
                throw new CException('Form not found');
            }

            $path = parse_url($form['url'], PHP_URL_PATH);
            $filename = $path ? basename($path) : '';
            if (preg_match('/^leadform-[a-zA-Z0-9\-]+-' . (int) $id . '\.html$/', $filename)) {
                $docRoot = dirname(Yii::app()->basePath);
                $filePath = $docRoot . DIRECTORY_SEPARATOR . $filename;
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
            }

            if (!empty($form['webFormId'])) {
                $webForm = Yii::app()->db->createCommand()
                    ->select('id, leadSource')->from('x2_web_forms')
                    ->where('id=:id', array(':id' => $form['webFormId']))
                    ->queryRow();
                if ($webForm && $webForm['leadSource'] === 'SalesForm-' . $id) {
                    Yii::app()->db->createCommand()->delete('wa_webform_notify', 'webFormId=:id', array(':id' => $webForm['id']));
                    Yii::app()->db->createCommand()->delete('x2_web_forms', 'id=:id', array(':id' => $webForm['id']));
                }
            }

            Yii::app()->db->createCommand()->delete('x2_custom_lead_forms', 'id=:id', array(':id' => $id));
            Yii::app()->user->setFlash('success', '"' . $form['name'] . '" deleted.');
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('leadForms'));
    }

    /**
     * Parses the "datetime-local" input format (e.g. "2026-08-01T14:30")
     * used by the schedule/register forms into a unix timestamp, or null
     * if left blank.
     */
    private function parseDeactivateAt($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        return $ts !== false ? $ts : null;
    }

    private function sendLeadFormNotification($id, $name, $url) {
        $result = $this->callWaHub('POST', '/admin/notify-new-form', array('name' => $name, 'url' => $url));
        if (!isset($result['ok']) || !$result['ok']) {
            throw new CException(isset($result['error']) ? $result['error'] : 'Failed to send notification');
        }
        Yii::app()->db->createCommand()->update('x2_custom_lead_forms',
            array('tinyUrl' => isset($result['tinyUrl']) ? $result['tinyUrl'] : null, 'notifiedAt' => time()),
            'id=:id', array(':id' => $id)
        );
    }

    /**
     * Proxies a QR code image for an arbitrary URL (lead form list
     * thumbnails), distinct from qrImage which is specifically the
     * WhatsApp pairing QR.
     */
    public function actionQrForUrl($url) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }

        $ch = curl_init($this->waHubUrl . '/admin/qr-for-url.png?url=' . urlencode($url));
        curl_setopt($ch, CURLOPT_USERPWD, $this->waHubUser . ':' . $this->waHubPass);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $image = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$image) {
            header('HTTP/1.1 404 Not Found');
            Yii::app()->end();
        }

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        echo $image;
        Yii::app()->end();
    }

    /**
     * Get WhatsApp connection status
     */
    public function actionStatus() {
        try {
            $status = $this->callWaHub('GET', '/admin/wa-status');
            echo json_encode($status);
        } catch (Exception $e) {
            echo json_encode(array('error' => $e->getMessage()));
        }
        Yii::app()->end();
    }

    /**
     * Admin-only "WhatsApp Configuration" page (Administration Tools):
     * connection status, phone number, tracked-data stats, recent activity,
     * and — while disconnected — the live pairing QR code.
     */
    public function actionConfigure() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        $status = array();
        try {
            $status = $this->callWaHub('GET', '/admin/wa-status');
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', 'Could not reach wa-hub: ' . $e->getMessage());
        }
        $this->render('configure', array('status' => $status));
    }

    /**
     * Proxies the live pairing QR code as an image, so the browser only
     * needs the X2CRM session — not a separate wa-hub Basic Auth prompt.
     */
    public function actionQrImage() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }

        $ch = curl_init($this->waHubUrl . '/admin/qr.png');
        curl_setopt($ch, CURLOPT_USERPWD, $this->waHubUser . ':' . $this->waHubPass);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $image = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$image) {
            header('HTTP/1.1 404 Not Found');
            Yii::app()->end();
        }

        header('Content-Type: image/png');
        header('Cache-Control: no-store');
        echo $image;
        Yii::app()->end();
    }

    /**
     * Fully logs out of WhatsApp and clears the saved session (not just
     * removing a group) so the next connection attempt generates a fresh
     * QR immediately, for a deliberate re-pair from the config page.
     */
    public function actionDisconnect() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        try {
            $result = $this->callWaHub('POST', '/admin/logout');
            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', 'Disconnected. Scan the new QR code below to reconnect.');
            } else {
                throw new CException(isset($result['error']) ? $result['error'] : 'Failed to disconnect');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('configure'));
    }

    /**
     * Call wa-hub API with proper authentication
     */
    private function callWaHub($method, $endpoint, $data = null) {
        $url = $this->waHubUrl . $endpoint;
        $ch = curl_init($url);

        // Set basic auth
        curl_setopt($ch, CURLOPT_USERPWD, $this->waHubUser . ':' . $this->waHubPass);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if (in_array($method, array('POST', 'PUT'))) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new CException('CURL Error: ' . $curlError);
        }

        $result = json_decode($response, true);

        if ($httpCode >= 400) {
            $waError = (json_last_error() === JSON_ERROR_NONE && isset($result['error'])) ? $result['error'] : $response;
            // "bad-request" from WhatsApp's own groupCreate/participants API almost
            // always means one of the phone numbers isn't valid: not a real
            // WhatsApp-registered number, or the same number as the connected
            // WhatsApp account itself (you can't add the group owner as a member).
            if ($waError === 'bad-request') {
                throw new CException(
                    'WhatsApp rejected this request. Check that every selected contact has a real, ' .
                    'WhatsApp-registered phone number with country code, and that none of them is the ' .
                    'same number as the WhatsApp account connected to wa-hub.'
                );
            }
            throw new CException('wa-hub error: ' . $waError);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CException('Invalid JSON response: ' . $response);
        }

        return $result;
    }
}
?>

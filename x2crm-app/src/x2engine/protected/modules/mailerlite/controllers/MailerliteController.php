<?php
/**
 * MailerLite Controller
 * Configuration and Contact List sync for the MailerLite integration
 * (integration/server.js, the same Node service that already handles the
 * MailerLite webhook and poll-based contact sync).
 */

class MailerliteController extends x2base {

    // x2base::actions() (protected/controllers/x2base.php) unconditionally
    // reads $this->modelClass — MailerliteController isn't a single-model
    // controller so this must be declared, even just as ''. Without it,
    // `$this->modelClass` is undefined (null), the `!== ''` guard there
    // passes incorrectly, and `null::model()` fatals with "Class name must
    // be a valid object or a string." Only ever surfaces for actions this
    // controller doesn't define (like the default 'index'), since Yii's own
    // createAction() resolves any real actionXxx() method before ever
    // calling $this->actions() at all.
    public $modelClass = '';

    private $integrationUrl = 'http://integration:3000';
    private $sharedSecret = '';

    public function init() {
        parent::init();
        $this->sharedSecret = getenv('INTEGRATION_SHARED_SECRET') ?: '';
    }

    /**
     * Bare module/controller URL (e.g. someone bookmarks or guesses
     * mailerlite/index) lands here instead of erroring — redirect to the
     * page that actually exists.
     */
    public function actionIndex() {
        $this->redirect(array('configure'));
    }

    /**
     * All Contacts lists (static or dynamic) available to sync — admin-only
     * page, so no per-user visibility filtering is needed here.
     */
    private function getContactLists() {
        $criteria = new CDbCriteria();
        $criteria->addCondition('modelName = "Contacts"');
        $criteria->addCondition('(type = "dynamic" OR type = "static")');
        $criteria->order = 'name ASC';
        return X2List::model()->findAll($criteria);
    }

    /**
     * mailerlite_list_sync is also created by integration/server.js at
     * startup — this defensive copy means neither service hard-depends on
     * the other having started first (same reasoning as
     * WhatsappGroupsController::ensureWebFormManagementColumns).
     */
    private function ensureMailerliteListSyncTable() {
        $exists = Yii::app()->db->createCommand(
            "SELECT COUNT(*) FROM information_schema.TABLES " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailerlite_list_sync'"
        )->queryScalar();
        if (!$exists) {
            Yii::app()->db->createCommand("
                CREATE TABLE mailerlite_list_sync (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    listId INT NOT NULL UNIQUE,
                    listName VARCHAR(255) NOT NULL,
                    groupName VARCHAR(255) NOT NULL,
                    groupId VARCHAR(64) NULL,
                    autoSync TINYINT(1) NOT NULL DEFAULT 0,
                    lastSyncedAt DATETIME NULL,
                    lastSyncCount INT NULL,
                    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ")->execute();
        }
    }

    /**
     * Every list ever synced, with its live current name (or null if the
     * list itself has since been deleted from X2CRM, which the view flags
     * distinctly from just "not yet synced").
     */
    private function getSyncedLists() {
        $rows = Yii::app()->db->createCommand()
            ->select('*')->from('mailerlite_list_sync')->order('listName ASC')->queryAll();
        foreach ($rows as &$row) {
            $currentList = X2List::model()->findByPk($row['listId']);
            $row['currentName'] = $currentList ? $currentList->name : null;
        }
        return $rows;
    }

    /**
     * MailerLite sync tools, open to any logged-in user (not just admins):
     * the synced-lists table (last sync time, auto-sync toggle, remove) and
     * the "sync this Contact List to MailerLite" tool. API key management
     * lives separately on actionApiSettings() (admin-only) — this page only
     * reads connection status read-only, to show a heads-up banner if
     * MailerLite isn't configured yet, without exposing the key itself or
     * any way to change it.
     */
    public function actionConfigure() {
        $this->ensureMailerliteListSyncTable();
        $status = $this->callIntegration('GET', '/admin/mailerlite-status');
        $this->render('configure', array(
            'lists' => $this->getContactLists(),
            'status' => $status,
            'syncedLists' => $this->getSyncedLists(),
        ));
    }

    /**
     * Admin-only: API key management and connection status/account details.
     * Split out from actionConfigure() so the sync tools there can be
     * opened up to all users without also exposing the key itself.
     */
    public function actionApiSettings() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        $status = $this->callIntegration('GET', '/admin/mailerlite-status');
        $this->render('apiSettings', array(
            'status' => $status,
        ));
    }

    /**
     * Saves/updates the MailerLite API key — stored by integration/server.js
     * in its own DB table (not this .env file), so it takes effect on the
     * very next MailerLite API call with no container restart. The key
     * itself never comes back to this page after saving; only a masked
     * last-4-characters preview does (see actionApiSettings()'s status call).
     */
    public function actionSaveApiKey() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $apiKey = trim(Yii::app()->request->getPost('apiKey'));
        try {
            if (!$apiKey) {
                throw new CException('API key is required.');
            }
            $result = $this->callIntegration('POST', '/admin/mailerlite-api-key', array('apiKey' => $apiKey));
            if (empty($result['ok'])) {
                throw new CException(isset($result['error']) ? $result['error'] : 'Failed to save API key');
            }
            Yii::app()->user->setFlash('success', 'MailerLite API key updated.');
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('apiSettings'));
    }

    /**
     * Clears the stored API key so the integration can no longer reach
     * MailerLite until a new key is saved — the equivalent of "Disconnect"
     * on the WhatsApp Configuration page, except there's no paired session
     * to log out of here, just a key to forget. Background syncing (list
     * auto-sync, new-contact grouping) fails quietly and resumes on its own
     * once a key is saved again; nothing needs to be manually re-enabled.
     */
    public function actionDisconnect() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        try {
            $result = $this->callIntegration('POST', '/admin/mailerlite-disconnect');
            if (empty($result['ok'])) {
                throw new CException(isset($result['error']) ? $result['error'] : 'Failed to disconnect');
            }
            Yii::app()->user->setFlash('success', 'MailerLite disconnected. Save a new API key to reconnect.');
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('apiSettings'));
    }

    /**
     * Resolves an X2CRM Contacts list to {email, name} pairs for every
     * member with an email address — shared by actionSyncList() and
     * actionScheduleCampaign(), both of which need "who's in this list"
     * before talking to MailerLite at all.
     */
    private function resolveListSubscribers($listId) {
        $list = X2List::model()->findByPk($listId);
        if (!$list || $list->modelName !== 'Contacts') {
            throw new CException('List not found');
        }

        $contacts = Contacts::model()->findAll($list->queryCriteria());
        $subscribers = array();
        $contactModels = array();
        foreach ($contacts as $c) {
            if (!empty($c->email)) {
                $subscribers[] = array(
                    'email' => $c->email,
                    'name' => trim($c->firstName . ' ' . $c->lastName),
                );
                $contactModels[] = $c;
            }
        }
        if (empty($subscribers)) {
            throw new CException('No contacts with an email address in this list.');
        }
        // Third element (the actual Contacts models, not just email/name) is
        // only used by actionScheduleCampaign() so it can log a per-contact
        // Action without a second query — PHP's list() ignores extra array
        // elements, so this is safe for every other caller.
        return array($list, $subscribers, $contactModels);
    }

    /**
     * Resolves the selected Contacts list's current members (Contacts with
     * an email address) and hands them to integration/server.js, which
     * upserts each as a MailerLite subscriber into a group named after the
     * list — creating that group in MailerLite first if it doesn't exist
     * yet. List membership resolution stays on the PHP/Yii side (that's
     * where X2List's query-criteria logic lives); the actual MailerLite API
     * calls stay in integration/server.js, which already owns the
     * MAILERLITE_API_KEY and the upsert-subscriber logic.
     */
    public function actionSyncList() {
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $this->ensureMailerliteListSyncTable();
        $listId = Yii::app()->request->getPost('listId');
        $autoSync = Yii::app()->request->getPost('autoSync') ? 1 : 0;

        try {
            list($list, $subscribers) = $this->resolveListSubscribers($listId);
            $groupName = 'X2CRM - ' . $list->name;

            $result = $this->callIntegration('POST', '/admin/sync-contacts-to-group', array(
                'groupName' => $groupName,
                'contacts' => $subscribers,
            ));

            if (empty($result['ok'])) {
                throw new CException(isset($result['error']) ? $result['error'] : 'Sync failed');
            }

            $existing = Yii::app()->db->createCommand()
                ->select('id')->from('mailerlite_list_sync')->where('listId=:id', array(':id' => $listId))
                ->queryScalar();
            $fields = array(
                'listName' => $list->name,
                'groupName' => $groupName,
                'groupId' => isset($result['groupId']) ? $result['groupId'] : null,
                'autoSync' => $autoSync,
                'lastSyncedAt' => new CDbExpression('NOW()'),
                'lastSyncCount' => $result['synced'],
            );
            if ($existing !== false) {
                Yii::app()->db->createCommand()->update('mailerlite_list_sync', $fields, 'id=:id', array(':id' => $existing));
            } else {
                $fields['listId'] = $listId;
                Yii::app()->db->createCommand()->insert('mailerlite_list_sync', $fields);
            }

            Yii::app()->user->setFlash('success',
                'Synced ' . $result['synced'] . ' of ' . count($subscribers) . ' contacts from "' .
                $list->name . '" to MailerLite group "' . $groupName . '".');
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('configure'));
    }

    /**
     * Flips auto-sync on/off for an already-synced list. Turning it on
     * doesn't sync immediately — the next pollAutoSyncLists() cycle
     * (integration/server.js, every LIST_AUTO_SYNC_INTERVAL_MS) picks it up.
     */
    public function actionToggleAutoSync($id) {
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $row = Yii::app()->db->createCommand()
            ->select('autoSync')->from('mailerlite_list_sync')->where('id=:id', array(':id' => $id))
            ->queryRow();
        if ($row) {
            Yii::app()->db->createCommand()->update('mailerlite_list_sync',
                array('autoSync' => $row['autoSync'] ? 0 : 1), 'id=:id', array(':id' => $id));
            Yii::app()->user->setFlash('success', $row['autoSync'] ? 'Auto-sync turned off.' : 'Auto-sync turned on.');
        }

        $this->redirect(array('configure'));
    }

    /**
     * Removes a list's MailerLite group (see integration/server.js's
     * deleteMailerliteGroup() for exactly what this does and doesn't
     * guarantee) and stops tracking/auto-syncing it here.
     */
    public function actionRemoveSync($id) {
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        try {
            $row = Yii::app()->db->createCommand()
                ->select('*')->from('mailerlite_list_sync')->where('id=:id', array(':id' => $id))
                ->queryRow();
            if (!$row) {
                throw new CException('Not found');
            }

            if (!empty($row['groupId'])) {
                $result = $this->callIntegration('POST', '/admin/delete-mailerlite-group', array('groupId' => $row['groupId']));
                if (empty($result['ok'])) {
                    throw new CException(isset($result['error']) ? $result['error'] : 'Failed to remove MailerLite group');
                }
            }

            Yii::app()->db->createCommand()->delete('mailerlite_list_sync', 'id=:id', array(':id' => $id));
            Yii::app()->user->setFlash('success', 'Removed "' . $row['listName'] . '" from MailerLite.');
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('configure'));
    }

    /**
     * Server-to-server only — resolves a list to {email, name} pairs for
     * integration/server.js's auto-sync poller, which has no login session.
     * X2CRM doesn't use Yii's standard accessRules()/CAccessControlFilter at
     * all (see X2ControllerPermissionsBehavior::beforeAction) — guest
     * access instead requires a real row in x2_auth_item named
     * "MailerliteResolveListMembers" (ucfirst(controllerId) +
     * ucfirst(actionId)) linked under GuestSiteFunctionsTask in
     * x2_auth_item_child, the same mechanism ContactsWeblead uses for the
     * public lead-form endpoint (see data/install.sql). The shared-secret
     * check below is what actually restricts this to the poller — the RBAC
     * rows only get requests as far as this action running at all.
     */
    public function actionResolveListMembers() {
        $secret = getenv('INTEGRATION_SHARED_SECRET') ?: '';
        $provided = (string) Yii::app()->request->getParam('secret', '');
        header('Content-Type: application/json');
        if (!$secret || !hash_equals($secret, $provided)) {
            http_response_code(401);
            echo json_encode(array('ok' => false, 'error' => 'unauthorized'));
            Yii::app()->end();
            return;
        }

        try {
            list($list, $subscribers) = $this->resolveListSubscribers(Yii::app()->request->getParam('listId'));
            echo json_encode(array('ok' => true, 'subscribers' => $subscribers));
        } catch (Exception $e) {
            echo json_encode(array('ok' => false, 'error' => $e->getMessage()));
        }
        Yii::app()->end();
    }

    /**
     * One-time scheduled MailerLite campaign, sent to a Contacts list.
     * Deliberately one-time only, not recurring: MailerLite's API has no
     * repeat/recurrence concept for campaigns (confirmed against their
     * docs) — faking it would mean X2CRM owning its own recurrence engine,
     * which is a big enough undertaking to scope separately rather than
     * bolt on here.
     */
    public function actionScheduleCampaign() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }

        if (Yii::app()->request->isPostRequest) {
            try {
                $sendAt = trim(Yii::app()->request->getPost('sendAt'));
                $timestamp = $sendAt !== '' ? strtotime($sendAt) : false;
                if ($timestamp === false) {
                    throw new CException('A valid send date/time is required.');
                }
                if ($timestamp <= time()) {
                    throw new CException('Send date/time must be in the future.');
                }

                list($list, $subscribers, $contactModels) = $this->resolveListSubscribers(Yii::app()->request->getPost('listId'));

                $name = trim(Yii::app()->request->getPost('campaignName'));
                $subject = trim(Yii::app()->request->getPost('subject'));
                $fromName = trim(Yii::app()->request->getPost('fromName'));
                $fromEmail = trim(Yii::app()->request->getPost('fromEmail'));
                $html = Yii::app()->request->getPost('html');
                if (!$name || !$subject || !$fromEmail || !$html) {
                    throw new CException('Campaign name, subject, from email, and content are all required.');
                }

                $result = $this->callIntegration('POST', '/admin/schedule-campaign', array(
                    'groupName' => 'X2CRM - ' . $list->name,
                    'contacts' => $subscribers,
                    'campaign' => array(
                        'name' => $name,
                        'subject' => $subject,
                        'fromName' => $fromName,
                        'fromEmail' => $fromEmail,
                        'html' => $html,
                        'scheduleDate' => date('Y-m-d', $timestamp),
                        'scheduleHours' => date('H', $timestamp),
                        'scheduleMinutes' => date('i', $timestamp),
                    ),
                ));

                if (empty($result['ok'])) {
                    throw new CException(isset($result['error']) ? $result['error'] : 'Scheduling failed');
                }

                // MailerLite's campaign.sent webhook is a single aggregate
                // event per campaign (total_recipients count only) with no
                // per-subscriber identity at all — it can't tell us which
                // individual contacts actually received the email. X2CRM
                // already knows the full recipient list right here, so log
                // the per-contact record directly instead of depending on a
                // webhook that structurally can't provide it. A failure
                // logging one contact's Action shouldn't stop the rest.
                foreach ($contactModels as $contactModel) {
                    try {
                        Actions::associateAction($contactModel, array(
                            'actionDescription' => '[Email OUT] scheduled: "' . $subject .
                                '" (MailerLite campaign "' . $name . '", sending ' .
                                date('M j, Y g:i A', $timestamp) . ')',
                            'type' => 'Email',
                        ));
                    } catch (Exception $e) {
                        Yii::log(
                            'Failed to log MailerLite campaign Action for contact ' .
                                $contactModel->id . ': ' . $e->getMessage(),
                            CLogger::LEVEL_WARNING, 'application');
                    }
                }

                Yii::app()->user->setFlash('success',
                    '"' . $name . '" scheduled for ' . date('M j, Y g:i A', $timestamp) .
                    ', sending to ' . $result['synced'] . ' contacts from "' . $list->name . '".');
                $this->redirect(array('scheduleCampaign'));
            } catch (Exception $e) {
                Yii::app()->user->setFlash('error', $e->getMessage());
            }
        }

        $this->render('scheduleCampaign', array(
            'lists' => $this->getContactLists(),
        ));
    }

    /**
     * Calls integration/server.js's admin endpoints, authenticated with the
     * same shared secret the public webhook/trigger routes require (see
     * integration/server.js and the Caddyfile) — reusing that mechanism
     * rather than inventing a second auth scheme for this internal,
     * server-to-server call.
     */
    private function callIntegration($method, $path, $data = null) {
        $url = $this->integrationUrl . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Integration-Secret: ' . $this->sharedSecret,
        ));
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return array('ok' => false, 'error' => 'CURL error: ' . $curlError);
        }
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('ok' => false, 'error' => 'Invalid response from integration service');
        }
        return $result;
    }
}

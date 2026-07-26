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
                $skippedCount = 0;
                if ($listId) {
                    $phones = $this->getListPhones($listId, $skippedCount);
                } else {
                    $phones = array();
                    if (!empty($selectedContacts)) {
                        $contacts = Contacts::model()->findAllByPk($selectedContacts);
                        foreach ($contacts as $contact) {
                            $phone = $this->toWhatsAppPhone($contact->phone, $contact->country);
                            if ($phone) {
                                $phones[] = $phone;
                            } else {
                                $skippedCount++;
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
                    $successMsg = 'WhatsApp group created successfully!';
                    if ($skippedCount > 0) {
                        $successMsg .= " $skippedCount contact(s) skipped (no usable phone number).";
                    }
                    Yii::app()->user->setFlash('success', $successMsg);
                    $this->redirect(array('index'));
                } else {
                    throw new CException('Failed to create group: ' . (isset($result['error']) ? $result['error'] : 'Unknown error'));
                }
            } catch (Exception $e) {
                Yii::app()->user->setFlash('error', $e->getMessage());
            }
        }

        $this->render('create', array('lists' => $this->getAccessibleContactLists()));
    }

    /**
     * Manual admin page: send one WhatsApp message to an individual phone
     * number on demand. Distinct from Web Form Notifications (automated,
     * form-triggered) and the group broadcast tools — this is a one-off,
     * pick-a-number-and-send tool.
     */
    public function actionSendMessage() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        $this->ensureMessageTemplatesTable();

        if (Yii::app()->request->isPostRequest) {
            $message = trim(Yii::app()->request->getPost('message', ''));
            $contactId = (int) Yii::app()->request->getPost('contactId', 0);
            $templateId = (int) Yii::app()->request->getPost('templateId', 0);

            try {
                if (!$contactId) {
                    throw new CException('Please search for and select a Contact — messages can only be sent to people already in your Contacts, not an arbitrary number.');
                }
                if ($message === '') {
                    throw new CException('Message cannot be blank.');
                }

                // Always resolve the number from the selected Contact's own
                // phone/country server-side (never trust a client-supplied
                // phone value) — same normalization used everywhere else a
                // Contact's phone is sent to WhatsApp.
                $contact = Contacts::model()->findByPk($contactId);
                if (!$contact || empty($contact->phone)) {
                    throw new CException('That contact has no phone number on file.');
                }
                $resolvedPhone = WhatsAppPhoneUtil::toWhatsAppPhone($contact->phone, $contact->country);
                if ($resolvedPhone === null) {
                    throw new CException(
                        'This contact\'s phone number is missing a country code and their country ' .
                        'isn\'t one this can map confidently — update the phone number to include a ' .
                        'country code and try again.'
                    );
                }

                // A template's {{firstName}}/{{lastName}}/{{fullName}}
                // placeholders resolve to this specific Contact's own name —
                // unlike the group/broadcast tools, there's exactly one
                // known recipient here, so no generic fallback is needed.
                $fullName = trim($contact->firstName . ' ' . $contact->lastName);
                $message = strtr($message, array(
                    '{{firstName}}' => $contact->firstName ?: '',
                    '{{lastName}}' => $contact->lastName ?: '',
                    '{{fullName}}' => $fullName !== '' ? $fullName : ($contact->firstName ?: $contact->lastName ?: ''),
                ));

                $payload = array('phone' => $resolvedPhone, 'text' => $message);

                // Optional inline attachment — read directly and
                // base64-encode rather than going through X2CRM's Media
                // model/upload pipeline, since this never needs to persist
                // as a CRM-managed file, just pass through to wa-hub once.
                // A freshly-uploaded file overrides a template's own
                // attachment; see applyOutgoingAttachment().
                $this->applyOutgoingAttachment($payload, 'image', $templateId);

                $result = $this->callWaHub('POST', '/admin/send-message', $payload);
                if (isset($result['ok']) && $result['ok']) {
                    Yii::app()->user->setFlash('success', 'Message sent.');

                    // Log to the Contact's own Activity/History feed, same
                    // mechanism X2CRM uses for logged emails
                    // (Actions::associateAction, see InlineEmail::recordEmailSent).
                    $attachmentNote = isset($payload['imageBase64'])
                        ? "\n[with image attachment]"
                        : (isset($payload['documentBase64']) ? "\n[with PDF attachment]" : '');
                    // Appends a "[from:<number>]" marker _historyView.php
                    // parses back out to show which of this install's
                    // linked WhatsApp numbers actually sent it, rather
                    // than a number that could go stale if the connected
                    // account is ever swapped later.
                    $fromPhoneNote = !empty($result['fromPhone']) ? "\n[from:" . $result['fromPhone'] . "]" : '';
                    $loggedAction = Actions::associateAction($contact, array(
                        'type' => 'whatsapp',
                        'subject' => 'WhatsApp Message Sent',
                        'actionDescription' => $message . $attachmentNote . $fromPhoneNote,
                        'dueDate' => time(),
                        'completedBy' => Yii::app()->user->getName(),
                    ));
                    if ($loggedAction) {
                        $this->saveMessageAttachment($loggedAction->id, $payload);
                    }
                } else {
                    throw new CException(isset($result['error']) ? $result['error'] : 'Failed to send message');
                }
            } catch (Exception $e) {
                Yii::app()->user->setFlash('error', $e->getMessage());
            }

            $this->redirect(array('sendMessage'));
        }

        $groups = Yii::app()->db->createCommand()
            ->select('groupId, groupName')
            ->from('wa_groups')
            ->order('groupName ASC')
            ->queryAll();

        $templates = Yii::app()->db->createCommand()
            ->select('id, name')
            ->from('wa_message_templates')
            ->order('name ASC')
            ->queryAll();

        $this->render('sendMessage', array(
            'groups' => $groups,
            'lists' => $this->getAccessibleContactLists(),
            'templates' => $templates,
        ));
    }

    /**
     * POST-only counterpart to actionSendMessage() for the "Send WhatsApp
     * Group Message" half of the same page — same manual, one-off
     * send tool, just targeting a group instead of an individual number.
     * Not logged to any Contact's Activity/History feed (see
     * actionSendMessage) since a group has no single Contact to log
     * against.
     */
    public function actionSendGroupMessage() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (Yii::app()->request->isPostRequest) {
            $groupId = trim(Yii::app()->request->getPost('groupId', ''));
            $message = trim(Yii::app()->request->getPost('message', ''));
            $templateId = (int) Yii::app()->request->getPost('templateId', 0);

            try {
                if ($groupId === '') {
                    throw new CException('Please select a WhatsApp group.');
                }
                if ($message === '') {
                    throw new CException('Message cannot be blank.');
                }

                // A group post has no single recipient to personalize for,
                // so {{firstName}}/{{fullName}} resolve to a generic,
                // natural-sounding stand-in and {{lastName}} drops out
                // entirely — this is what lets one shared template (also
                // used for Individual/Broadcast, where these placeholders
                // resolve to a real name) stay usable here too instead of
                // showing raw unresolved placeholders in a live group chat.
                $message = strtr($message, array(
                    '{{firstName}}' => 'everyone',
                    '{{fullName}}' => 'everyone',
                    '{{lastName}}' => '',
                ));

                $payload = array('text' => $message);
                $this->applyOutgoingAttachment($payload, 'groupImage', $templateId);

                $result = $this->callWaHub('POST', '/admin/groups/' . urlencode($groupId) . '/send', $payload);
                if (isset($result['ok']) && $result['ok']) {
                    Yii::app()->user->setFlash('success', 'Message sent to group.');
                } else {
                    throw new CException(isset($result['error']) ? $result['error'] : 'Failed to send message');
                }
            } catch (Exception $e) {
                Yii::app()->user->setFlash('error', $e->getMessage());
            }
        }

        $this->redirect(array('sendMessage'));
    }

    /**
     * Manual "broadcast" tool: sends the same message individually to
     * every Contact in a chosen list — WhatsApp's real Broadcast List
     * feature isn't something this stack can build (Baileys, the library
     * wa-hub is built on, has no API for creating/managing one — it only
     * knows the unrelated status@broadcast, i.e. WhatsApp Status). This is
     * the practical equivalent: a loop of ordinary individual sends, each
     * logged to that Contact's own Activity/History like any other
     * individual message. Deliberately synchronous (not backgrounded) —
     * simplest correct option for typical list sizes; a large list takes
     * proportionally longer since each send is throttled below.
     */
    public function actionBroadcastMessage() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (Yii::app()->request->isPostRequest) {
            set_time_limit(0);

            $listId = (int) Yii::app()->request->getPost('listId', 0);
            $message = trim(Yii::app()->request->getPost('message', ''));
            $templateId = (int) Yii::app()->request->getPost('templateId', 0);

            try {
                if (!$listId) {
                    throw new CException('Please select a Contact List.');
                }
                if ($message === '') {
                    throw new CException('Message cannot be blank.');
                }

                $list = X2List::model()->findByPk($listId);
                if (!$list || $list->modelName !== 'Contacts') {
                    throw new CException('List not found.');
                }

                // Same reasoning as getListPhones() for bypassing
                // X2List::load()'s per-current-user scoping and
                // queryCriteria()'s default access restriction — this is
                // an admin-triggered send, not a filtered view, so it
                // should reach the list's true full membership.
                $contacts = Contacts::model()->findAll($list->queryCriteria(false));
                if (empty($contacts)) {
                    throw new CException('That list has no contacts.');
                }

                // Resolved once (same attachment for every contact in the
                // list), then merged into each contact's own payload below.
                $attachmentPayload = array();
                $this->applyOutgoingAttachment($attachmentPayload, 'image', $templateId);

                $total = count($contacts);
                $sent = 0;
                $skipped = 0;
                $failed = 0;
                $i = 0;

                foreach ($contacts as $contact) {
                    $i++;

                    $resolvedPhone = empty($contact->phone)
                        ? null : WhatsAppPhoneUtil::toWhatsAppPhone($contact->phone, $contact->country);
                    if ($resolvedPhone === null) {
                        $skipped++;
                        continue;
                    }

                    // Personalize per-contact so the same broadcast reads
                    // as an individual message, not an obvious mail-merge
                    // blast — {{fullName}} falls back to whichever of
                    // first/last name is actually set, rather than a
                    // dangling space when only one is on file.
                    $fullName = trim($contact->firstName . ' ' . $contact->lastName);
                    $personalizedMessage = strtr($message, array(
                        '{{firstName}}' => $contact->firstName ?: '',
                        '{{lastName}}' => $contact->lastName ?: '',
                        '{{fullName}}' => $fullName !== '' ? $fullName : ($contact->firstName ?: $contact->lastName ?: ''),
                    ));

                    $payload = array_merge(array('phone' => $resolvedPhone, 'text' => $personalizedMessage), $attachmentPayload);

                    try {
                        $result = $this->callWaHub('POST', '/admin/send-message', $payload);
                        if (isset($result['ok']) && $result['ok']) {
                            $sent++;
                            $attachmentNote = isset($attachmentPayload['imageBase64'])
                                ? "\n[with image attachment]"
                                : (isset($attachmentPayload['documentBase64']) ? "\n[with PDF attachment]" : '');
                            $fromPhoneNote = !empty($result['fromPhone']) ? "\n[from:" . $result['fromPhone'] . "]" : '';
                            $loggedAction = Actions::associateAction($contact, array(
                                'type' => 'whatsapp',
                                'subject' => 'WhatsApp Broadcast Sent',
                                'actionDescription' => $personalizedMessage . $attachmentNote . $fromPhoneNote,
                                'dueDate' => time(),
                                'completedBy' => Yii::app()->user->getName(),
                            ));
                            if ($loggedAction) {
                                // Same attachment bytes for every recipient
                                // in this broadcast — stored once per
                                // recipient's own Action row rather than
                                // shared, so each history entry stays
                                // independently viewable/deletable.
                                $this->saveMessageAttachment($loggedAction->id, $attachmentPayload);
                            }
                        } else {
                            $failed++;
                        }
                    } catch (Exception $e) {
                        $failed++;
                    }

                    // Space sends out rather than firing as fast as
                    // possible — reduces the chance WhatsApp flags the
                    // paired account for bulk/spam-like behavior. Skipped
                    // after the last contact.
                    if ($i < $total) {
                        sleep(1);
                    }
                }

                $summary = "Broadcast complete: sent to $sent of $total contact(s).";
                if ($skipped > 0) {
                    $summary .= " $skipped skipped (no usable phone number).";
                }
                if ($failed > 0) {
                    $summary .= " $failed failed to send.";
                }
                Yii::app()->user->setFlash(($failed > 0 || $sent === 0) ? 'error' : 'success', $summary);
            } catch (Exception $e) {
                Yii::app()->user->setFlash('error', $e->getMessage());
            }
        }

        $this->redirect(array('sendMessage'));
    }

    /**
     * Message Templates: a small reusable library of canned WhatsApp
     * messages (with an optional image/PDF attachment) that can be picked
     * from any of the three Send Message tools instead of retyping the
     * same wording every time. Deliberately one flat list shared across
     * all three tools rather than per-tool templates — the
     * {{firstName}}/{{lastName}}/{{fullName}} placeholders a template's
     * body contains are resolved differently depending on where it's
     * sent from (see actionSendMessage for the individual-contact
     * substitution, actionSendGroupMessage for the group "everyone"
     * fallback, and actionBroadcastMessage for the existing per-contact
     * substitution), so one wording works everywhere.
     */
    public function actionTemplates() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        $this->ensureMessageTemplatesTable();

        if (Yii::app()->request->isPostRequest) {
            $name = trim(Yii::app()->request->getPost('name', ''));
            $body = trim(Yii::app()->request->getPost('body', ''));

            try {
                if ($name === '') {
                    throw new CException('Template name is required.');
                }
                if ($body === '') {
                    throw new CException('Template message cannot be blank.');
                }

                $attrs = array('name' => $name, 'body' => $body);
                $this->applyTemplateAttachmentUpload($attrs);

                Yii::app()->db->createCommand()->insert('wa_message_templates', $attrs);
                Yii::app()->user->setFlash('success', 'Template created.');
            } catch (Exception $e) {
                Yii::app()->user->setFlash('error', $e->getMessage());
            }
            $this->redirect(array('templates'));
        }

        $templates = Yii::app()->db->createCommand()
            ->select('id, name, body, attachmentKind, attachmentFileName, updatedAt')
            ->from('wa_message_templates')
            ->order('name ASC')
            ->queryAll();

        $this->render('templates', array('templates' => $templates));
    }

    /**
     * Edit (and re-upload/remove the attachment of) an existing template.
     */
    public function actionEditTemplate($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        $this->ensureMessageTemplatesTable();

        $template = Yii::app()->db->createCommand()
            ->select('id, name, body, attachmentKind, attachmentFileName')
            ->from('wa_message_templates')
            ->where('id=:id', array(':id' => $id))
            ->queryRow();
        if (!$template) {
            Yii::app()->user->setFlash('error', 'Template not found.');
            $this->redirect(array('templates'));
        }

        if (Yii::app()->request->isPostRequest) {
            $name = trim(Yii::app()->request->getPost('name', ''));
            $body = trim(Yii::app()->request->getPost('body', ''));
            $removeAttachment = (bool) Yii::app()->request->getPost('removeAttachment', false);

            try {
                if ($name === '') {
                    throw new CException('Template name is required.');
                }
                if ($body === '') {
                    throw new CException('Template message cannot be blank.');
                }

                $attrs = array('name' => $name, 'body' => $body);
                if ($removeAttachment) {
                    $attrs['attachmentKind'] = null;
                    $attrs['attachmentData'] = null;
                    $attrs['attachmentMimeType'] = null;
                    $attrs['attachmentFileName'] = null;
                }
                // A fresh upload (handled next) overrides the removal above
                // if both are somehow submitted together.
                $this->applyTemplateAttachmentUpload($attrs);

                Yii::app()->db->createCommand()->update('wa_message_templates', $attrs, 'id=:id', array(':id' => $id));
                Yii::app()->user->setFlash('success', 'Template updated.');
                $this->redirect(array('templates'));
            } catch (Exception $e) {
                Yii::app()->user->setFlash('error', $e->getMessage());
            }
        }

        $this->render('editTemplate', array('template' => $template));
    }

    /**
     * POST-only delete, matching actionDelete()'s (group deletion) plain
     * redirect convention rather than an AJAX/JSON response.
     */
    public function actionDeleteTemplate($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }
        Yii::app()->db->createCommand()->delete('wa_message_templates', 'id=:id', array(':id' => $id));
        Yii::app()->user->setFlash('success', 'Template deleted.');
        $this->redirect(array('templates'));
    }

    /**
     * AJAX endpoint the Send Message page's "Use Template" dropdowns call
     * to pre-fill a form once a template is picked. Returns only the body
     * text and attachment *metadata* (kind/filename) — never the raw
     * attachment bytes, which stay server-side and get re-attached
     * directly from the DB at send time (see applyOutgoingAttachment()).
     */
    public function actionTemplateJson($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        $template = Yii::app()->db->createCommand()
            ->select('id, name, body, attachmentKind, attachmentFileName')
            ->from('wa_message_templates')
            ->where('id=:id', array(':id' => $id))
            ->queryRow();

        header('Content-Type: application/json');
        echo json_encode($template ?: null);
        Yii::app()->end();
    }

    /**
     * Streams a template's raw attachment bytes (image or PDF) — used by
     * the edit page's live preview to show the currently-saved attachment
     * as an actual <img>/link before anything is re-uploaded. Distinct
     * from actionTemplateJson(), which deliberately never exposes the raw
     * bytes to the browser as part of its metadata response.
     */
    public function actionTemplateAttachment($id) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        $template = Yii::app()->db->createCommand()
            ->select('attachmentData, attachmentMimeType, attachmentFileName')
            ->from('wa_message_templates')
            ->where('id=:id', array(':id' => $id))
            ->queryRow();
        if (!$template || empty($template['attachmentData'])) {
            throw new CHttpException(404, 'No attachment.');
        }
        header('Content-Type: ' . $template['attachmentMimeType']);
        header('Content-Disposition: inline; filename="' . addslashes($template['attachmentFileName']) . '"');
        echo $template['attachmentData'];
        Yii::app()->end();
    }

    /**
     * Shared by actionTemplates()/actionEditTemplate(): reads the optional
     * "attachment" file upload into $attrs (ready for an insert()/update()
     * against wa_message_templates), validating it's an image or a PDF.
     * Left untouched when no file was submitted, so editing a template's
     * text doesn't require re-uploading its existing attachment.
     */
    private function applyTemplateAttachmentUpload(&$attrs) {
        $uploaded = CUploadedFile::getInstanceByName('attachment');
        if ($uploaded === null) {
            return;
        }
        $mimeType = $uploaded->type;
        if (strpos($mimeType, 'image/') === 0) {
            $attrs['attachmentKind'] = 'image';
        } elseif ($mimeType === 'application/pdf') {
            $attrs['attachmentKind'] = 'document';
        } else {
            throw new CException('Attachment must be an image or a PDF.');
        }
        $attrs['attachmentData'] = file_get_contents($uploaded->tempName);
        $attrs['attachmentMimeType'] = $mimeType;
        $attrs['attachmentFileName'] = $uploaded->name;
    }

    /**
     * Shared by the three send actions: resolves the attachment to
     * actually send and adds the right keys directly onto $payload for
     * wa-hub (imageBase64, or documentBase64/documentMimeType/
     * documentFileName). A freshly-uploaded file (under $uploadFieldName)
     * always takes priority over a chosen template's own stored
     * attachment — a template is a convenient starting point, not a lock
     * that prevents swapping the attachment per-send.
     */
    private function applyOutgoingAttachment(&$payload, $uploadFieldName, $templateId) {
        $uploaded = CUploadedFile::getInstanceByName($uploadFieldName);
        if ($uploaded !== null) {
            $mimeType = $uploaded->type;
            if (strpos($mimeType, 'image/') === 0) {
                $payload['imageBase64'] = base64_encode(file_get_contents($uploaded->tempName));
                $payload['imageMimeType'] = $mimeType;
            } elseif ($mimeType === 'application/pdf') {
                $payload['documentBase64'] = base64_encode(file_get_contents($uploaded->tempName));
                $payload['documentMimeType'] = $mimeType;
                $payload['documentFileName'] = $uploaded->name;
            } else {
                throw new CException('Attachment must be an image or a PDF.');
            }
            return;
        }

        if ($templateId) {
            $template = Yii::app()->db->createCommand()
                ->select('attachmentKind, attachmentData, attachmentMimeType, attachmentFileName')
                ->from('wa_message_templates')
                ->where('id=:id', array(':id' => (int) $templateId))
                ->queryRow();
            if ($template && $template['attachmentKind'] === 'image') {
                $payload['imageBase64'] = base64_encode($template['attachmentData']);
                $payload['imageMimeType'] = $template['attachmentMimeType'];
            } elseif ($template && $template['attachmentKind'] === 'document') {
                $payload['documentBase64'] = base64_encode($template['attachmentData']);
                $payload['documentMimeType'] = $template['attachmentMimeType'];
                $payload['documentFileName'] = $template['attachmentFileName'];
            }
        }
    }

    /**
     * Persists the attachment actually sent (image or PDF) so it can be
     * viewed later from the Contact's Activity/History feed — the send
     * itself never touched X2CRM's own storage (see applyOutgoingAttachment
     * above), so without this the feed only ever had a "[with image
     * attachment]" text note, no way to actually see it again.
     * $payload is whatever applyOutgoingAttachment() built (imageBase64 or
     * documentBase64/documentMimeType/documentFileName); a no-op if it has
     * neither key (no attachment was sent).
     */
    private function saveMessageAttachment($actionId, $payload) {
        if (isset($payload['imageBase64'])) {
            $kind = 'image';
            $data = base64_decode($payload['imageBase64']);
            $mimeType = !empty($payload['imageMimeType']) ? $payload['imageMimeType'] : 'image/jpeg';
            $fileName = null;
        } elseif (isset($payload['documentBase64'])) {
            $kind = 'document';
            $data = base64_decode($payload['documentBase64']);
            $mimeType = !empty($payload['documentMimeType']) ? $payload['documentMimeType'] : 'application/pdf';
            $fileName = !empty($payload['documentFileName']) ? $payload['documentFileName'] : null;
        } else {
            return;
        }

        $this->ensureMessageAttachmentsTable();
        Yii::app()->db->createCommand()->insert('wa_message_attachments', array(
            'actionId' => $actionId,
            'kind' => $kind,
            'data' => $data,
            'mimeType' => $mimeType,
            'fileName' => $fileName,
        ));
    }

    /**
     * AJAX/direct endpoint the History feed's WhatsApp entries use to
     * actually display an attachment — open to any logged-in user (not
     * admin-only) since the feed itself is visible to any user who can see
     * the associated Contact, matching that same access level.
     */
    public function actionMessageAttachment($actionId) {
        if (Yii::app()->user->isGuest) {
            throw new CHttpException(403, 'Login required');
        }
        $attachment = Yii::app()->db->createCommand()
            ->select('data, mimeType, fileName')
            ->from('wa_message_attachments')
            ->where('actionId=:id', array(':id' => (int) $actionId))
            ->queryRow();
        if (!$attachment) {
            throw new CHttpException(404, 'No attachment.');
        }
        header('Content-Type: ' . $attachment['mimeType']);
        if (!empty($attachment['fileName'])) {
            header('Content-Disposition: inline; filename="' . addslashes($attachment['fileName']) . '"');
        }
        echo $attachment['data'];
        Yii::app()->end();
    }

    /**
     * Self-heal table creation, same convention as
     * ensureMessageTemplatesTable().
     */
    private function ensureMessageAttachmentsTable() {
        $db = Yii::app()->db;
        $exists = $db->createCommand(
            "SELECT COUNT(*) FROM information_schema.TABLES " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_message_attachments'"
        )->queryScalar();
        if (!$exists) {
            $db->createCommand(
                "CREATE TABLE wa_message_attachments (" .
                "id INT PRIMARY KEY AUTO_INCREMENT, " .
                "actionId INT NOT NULL, " .
                "kind VARCHAR(20) NOT NULL, " .
                "data LONGBLOB NOT NULL, " .
                "mimeType VARCHAR(100) NOT NULL, " .
                "fileName VARCHAR(255) NULL, " .
                "createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, " .
                "KEY (actionId)" .
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            )->execute();
        }
    }

    /**
     * Self-heal table creation, same convention as
     * ensureWebFormManagementColumns() — the module's install.sql only
     * runs on a fresh install, so a table added after go-live needs this
     * to appear on installs that predate it (every existing local/prod
     * install in this case).
     */
    private function ensureMessageTemplatesTable() {
        $db = Yii::app()->db;
        $exists = $db->createCommand(
            "SELECT COUNT(*) FROM information_schema.TABLES " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_message_templates'"
        )->queryScalar();
        if (!$exists) {
            $db->createCommand(
                "CREATE TABLE wa_message_templates (" .
                "id INT PRIMARY KEY AUTO_INCREMENT, " .
                "name VARCHAR(150) NOT NULL, " .
                "body TEXT NOT NULL, " .
                "attachmentKind VARCHAR(20) NULL, " .
                "attachmentData LONGBLOB NULL, " .
                "attachmentMimeType VARCHAR(100) NULL, " .
                "attachmentFileName VARCHAR(255) NULL, " .
                "createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, " .
                "updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" .
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            )->execute();
        }
    }

    /**
     * Live search for the "Add Contacts" picker on the create/add-members
     * pages — used instead of pre-loading every contact into the page, which
     * silently truncated at a fixed limit and made any contact past that cut
     * (irrespective of the search box) impossible to find or select on an
     * install with more contacts than the limit. Only ever returns contacts
     * with a phone number, since that's the only kind these pages can use.
     */
    public function actionSearchContacts() {
        $q = trim(Yii::app()->request->getParam('q', ''));

        $criteria = new CDbCriteria();
        $criteria->addCondition('phone IS NOT NULL AND phone != ""');
        if ($q !== '') {
            // Built as its own criteria and merged in with AND, rather than
            // calling addSearchCondition() twice directly on $criteria —
            // doing it directly made the two conditions chain as
            // ((phone-not-null AND name-match) OR phone-match) instead of
            // (phone-not-null AND (name-match OR phone-match)), which
            // matched on phone correctly (each match already implied a
            // non-null phone) but let phone-matching short-circuit the name
            // search's own logic, breaking name search specifically.
            $searchCriteria = new CDbCriteria();
            $searchCriteria->addSearchCondition('CONCAT(firstName, " ", lastName)', $q, true, 'OR');
            $searchCriteria->addSearchCondition('phone', $q, true, 'OR');
            $criteria->mergeWith($searchCriteria, 'AND');
        }
        $criteria->order = 'firstName ASC, lastName ASC';
        $criteria->limit = 50;

        $contacts = Contacts::model()->findAll($criteria);
        $results = array();
        foreach ($contacts as $contact) {
            $results[] = array(
                'id' => $contact->id,
                'name' => $contact->name,
                'phone' => $contact->phone,
            );
        }

        header('Content-Type: application/json');
        echo json_encode($results);
        Yii::app()->end();
    }

    /**
     * Contact lists (X2CRM's "Lists" feature, filtered to Contacts-based
     * dynamic lists) that the current user is allowed to see, for use as a
     * live membership filter when creating/syncing a WhatsApp group.
     */
    private function getAccessibleContactLists() {
        $criteria = new CDbCriteria();
        $criteria->addCondition('modelName = "Contacts"');
        // Both types resolve correctly through X2List::queryCriteria() in
        // getListPhones() below — static lists are how Web Lead Forms' new
        // "Add to List" target lists show up here for group linking.
        $criteria->addInCondition('type', array('dynamic', 'static'));
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
        $criteria->order = 'name ASC';
        return X2List::model()->findAll($criteria);
    }

    /**
     * Resolves a dynamic X2CRM list's current live criteria to the phone
     * numbers of its matching Contacts right now. $skippedCount (optional,
     * by reference) is set to how many matching contacts had no usable
     * phone number — existing callers that don't pass it are unaffected.
     */
    private function getListPhones($listId, &$skippedCount = null) {
        // Deliberately X2List::model()->findByPk() here, not X2List::load()
        // — load() applies per-CURRENT-USER visibility scoping (via
        // Yii::app()->user->getId()/getName()) meant for a real logged-in
        // session. Every caller of this method is either an admin action
        // or (WhatsappGroupsListPhones, wa-hub's server-to-server auto-sync
        // poller) a trusted machine call with no logged-in user at all —
        // both want the list's full, unrestricted membership, not a
        // restriction scoped to whichever "current user" happens to be set.
        // Confirmed live: calling this without a real session hits load()'s
        // non-admin branch, where a guest's empty getId() produces a
        // malformed "WHERE userId=" subquery fragment and a hard SQL error.
        $list = X2List::model()->findByPk((int) $listId);
        if (!$list || $list->modelName !== 'Contacts') {
            throw new CException('List not found');
        }
        // false: skip queryCriteria()'s default extra per-CURRENT-USER
        // record-level access restriction (X2Model::getAccessCriteria()) —
        // same reasoning as bypassing X2List::load() above. A real admin
        // caller already sees everything via that criteria anyway, so this
        // is a no-op for them; a guest/machine caller like
        // WhatsappGroupsListPhones has no sensible per-user access rule to
        // apply at all, and without this the criteria resolves to
        // effectively nothing rather than the list's true full membership.
        $contacts = Contacts::model()->findAll($list->queryCriteria(false));
        $phones = array();
        $skippedCount = 0;
        foreach ($contacts as $contact) {
            $phone = $this->toWhatsAppPhone($contact->phone, $contact->country);
            if ($phone) {
                $phones[] = $phone;
            } else {
                $skippedCount++;
            }
        }
        return $phones;
    }

    /**
     * Normalizes a Contact's phone into the full international format
     * WhatsApp needs (country code + number, no leading zero/plus).
     * Confirmed live: ~93% of this install's contacts have their phone
     * stored as a bare 10-digit local number with no country code at all —
     * exactly WhatsApp-uninvitable as-is, which is why syncs/adds were
     * silently failing. Deliberately conservative: only touches numbers
     * that are EXACTLY 10 digits (the confirmed "missing country code"
     * shape here), leaves anything longer alone rather than risk
     * mis-prepending an already-correct number, and returns null (skip,
     * don't guess) when the contact's own country field isn't one this
     * maps confidently — a wrong guessed country code could add/message a
     * real, unrelated person in a different country who happens to share
     * that local number pattern.
     */
    private function toWhatsAppPhone($rawPhone, $country) {
        return WhatsAppPhoneUtil::toWhatsAppPhone($rawPhone, $country);
    }

    /**
     * Server-to-server only — resolves a list to its matching contacts'
     * phone numbers for wa-hub's auto-sync poller, which has no login
     * session. Same mechanism as MailerliteController::actionResolveListMembers
     * (see its docblock): X2CRM doesn't use Yii's standard accessRules() at
     * all, so guest access requires a real row in x2_auth_item named
     * "WhatsappGroupsListPhones" (ucfirst(controllerId) + ucfirst(actionId))
     * linked under GuestSiteFunctionsTask (see scripts/reconcile-custom-schema.sql)
     * — those RBAC rows only get requests as far as this action running at
     * all; the secret check below is what actually restricts it to the
     * poller. Uses X2CRM_API_KEY as the shared secret since that's already
     * the established trust anchor between wa-hub and X2CRM (the other
     * direction of every other call between these two services), rather
     * than introducing a separate secret for just this one endpoint.
     */
    public function actionListPhones($listId) {
        $secret = getenv('X2CRM_API_KEY') ?: '';
        $provided = (string) Yii::app()->request->getParam('secret', '');
        header('Content-Type: application/json');
        if (!$secret || !hash_equals($secret, $provided)) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(array('ok' => false, 'error' => 'Forbidden'));
            Yii::app()->end();
        }

        try {
            echo json_encode(array('ok' => true, 'phones' => $this->getListPhones($listId)));
        } catch (Exception $e) {
            echo json_encode(array('ok' => false, 'error' => $e->getMessage()));
        }
        Yii::app()->end();
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
            if (!isset($result['ok']) || !$result['ok']) {
                throw new CException(isset($result['error']) ? $result['error'] : 'Failed to link list');
            }

            // Linking a list only sets which list a group follows — it
            // doesn't by itself add any WhatsApp members. Without this, the
            // group stays empty until either the auto-sync poller happens
            // to run (up to several minutes later) or someone remembers to
            // separately click "Sync Now", which looked like linking a list
            // simply didn't do anything.
            if ($listId) {
                $phones = $this->getListPhones($listId);
                $syncResult = $this->callWaHub('POST', '/admin/groups/' . urlencode($groupId) . '/sync-members', array('phones' => $phones));
                if (isset($syncResult['ok']) && $syncResult['ok']) {
                    Yii::app()->user->setFlash('success', "List linked and synced: added {$syncResult['added']} member(s).");
                } else {
                    Yii::app()->user->setFlash('success', 'List linked, but the initial member sync failed — use "Sync Now" to retry.');
                }
            } else {
                Yii::app()->user->setFlash('success', 'List unlinked');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('view', 'groupId' => $groupId));
    }

    /**
     * Toggle whether a group receives the "new lead created" broadcast —
     * the exact same message (same template) as the pracharak's own DM,
     * posted into the group in addition to it. The message always
     * originates from wa-hub's single paired WhatsApp number — there's no
     * separate sender identity to configure — the only real prerequisite
     * is that account already being a member of this group.
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
     * Toggle whether a list-linked group gets automatically re-synced on a
     * schedule (see wa-hub's pollAutoSyncGroups()), instead of only via the
     * manual "Sync Now" button.
     */
    public function actionToggleAutoSync() {
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $groupId = Yii::app()->request->getPost('groupId');
        $enabled = Yii::app()->request->getPost('enabled');

        try {
            $result = $this->callWaHub('POST', '/admin/groups/' . urlencode($groupId) . '/auto-sync', array('enabled' => (bool) $enabled));
            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', $enabled ? 'Auto-sync enabled for this group.' : 'Auto-sync disabled for this group.');
            } else {
                throw new CException(isset($result['error']) ? $result['error'] : 'Failed to update auto-sync setting');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('view', 'groupId' => $groupId));
    }

    /**
     * Admin-only editor for the new-lead notification wording (see
     * wa-hub's renderLeadNotifyTemplate()) — the same template is used
     * both for the assigned pracharak's own DM and the courtesy copy
     * posted into whichever group(s) have notifications toggled on.
     */
    public function actionEditNotifyTemplate($webFormId = null) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        $this->ensureWebFormManagementColumns();
        $forms = Yii::app()->db->createCommand()
            ->select('*')
            ->from('x2_web_forms')
            ->where('type=:type', array(':type' => 'weblead'))
            ->order('name ASC')
            ->queryAll();

        $selectedForm = null;
        $leadSource = null;
        if ($webFormId) {
            foreach ($forms as $f) {
                if ((int) $f['id'] === (int) $webFormId) {
                    $selectedForm = $f;
                    break;
                }
            }
            if ($selectedForm) {
                $leadSource = $selectedForm['leadSource'] ?: null;
            }
        }

        $template = '';
        $isCustom = false;
        try {
            $qs = $leadSource ? ('?leadSource=' . urlencode($leadSource)) : '';
            $result = $this->callWaHub('GET', '/admin/lead-notify-template' . $qs);
            $template = isset($result['template']) ? $result['template'] : '';
            $isCustom = !empty($result['isCustom']);
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', 'Error loading template: ' . $e->getMessage());
        }

        // Which WhatsApp group(s) this form's leads actually reach — read
        // only, informational. Matches wa-hub's notifyGroupsOfNewLead(): a
        // group only receives it if BOTH explicitly assigned here
        // (wa_lead_notify_group_map) AND its own "New-lead notifications"
        // toggle is on. No more "broadcast to every eligible group"
        // fallback for forms with no explicit assignment.
        $groupNames = array();
        $ineligibleGroupNames = array();
        try {
            $allGroups = $this->callWaHub('GET', '/admin/groups');
        } catch (Exception $e) {
            $allGroups = array();
        }
        if ($leadSource) {
            $mappedIds = Yii::app()->db->createCommand()
                ->select('groupId')->from('wa_lead_notify_group_map')
                ->where('leadSource=:ls', array(':ls' => $leadSource))
                ->queryColumn();
            $byId = array();
            foreach ($allGroups as $g) {
                $byId[$g['groupId']] = $g;
            }
            foreach ($mappedIds as $gid) {
                $name = isset($byId[$gid]) ? $byId[$gid]['groupName'] : $gid;
                if (isset($byId[$gid]) && !empty($byId[$gid]['notifyOnNewLead'])) {
                    $groupNames[] = $name;
                } else {
                    $ineligibleGroupNames[] = $name;
                }
            }
        }

        $previewIframeUrl = null;
        $selectedFormName = null;
        if ($selectedForm) {
            $previewIframeUrl = rtrim(Yii::app()->request->getHostInfo(), '/') .
                '/index.php/contacts/contacts/weblead?webFormId=' . $selectedForm['id'];
            $selectedFormName = $selectedForm['name'];
        }

        $this->render('notifyTemplate', array(
            'template' => $template,
            'forms' => $forms,
            'selectedWebFormId' => $webFormId,
            'isCustom' => $isCustom,
            'groupNames' => $groupNames,
            'ineligibleGroupNames' => $ineligibleGroupNames,
            'previewIframeUrl' => $previewIframeUrl,
            'selectedFormName' => $selectedFormName,
        ));
    }

    /**
     * Saves the new-lead group broadcast wording — either the shared
     * default (webFormId=0) or a per-form override.
     */
    public function actionSaveNotifyTemplate() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $template = Yii::app()->request->getPost('template', '');
        $webFormId = (int) Yii::app()->request->getPost('webFormId', 0);

        try {
            if (trim($template) === '') {
                throw new CException('Template cannot be empty');
            }

            $leadSource = null;
            if ($webFormId) {
                $this->ensureWebFormManagementColumns();
                $form = Yii::app()->db->createCommand()
                    ->select('id, leadSource')->from('x2_web_forms')
                    ->where('id=:id', array(':id' => $webFormId))
                    ->queryRow();
                if (!$form) {
                    throw new CException('Form not found');
                }
                $leadSource = $form['leadSource'];
                if (empty($leadSource)) {
                    // Backfill only leadSource, NOT generateLead — a custom
                    // message alone shouldn't silently start generating Lead
                    // records; actionSaveWebFormNotify already owns turning
                    // that on when notifications are actually enabled.
                    $leadSource = 'WebForm-' . $webFormId;
                    Yii::app()->db->createCommand()->update('x2_web_forms',
                        array('leadSource' => $leadSource), 'id=:id', array(':id' => $webFormId));
                }
            }

            $result = $this->callWaHub('POST', '/admin/lead-notify-template',
                array('template' => $template, 'leadSource' => $leadSource));
            if (isset($result['ok']) && $result['ok']) {
                Yii::app()->user->setFlash('success', 'Message template updated.');
            } else {
                throw new CException(isset($result['error']) ? $result['error'] : 'Failed to save template');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('editNotifyTemplate', 'webFormId' => $webFormId ?: null));
    }

    /**
     * Reverts one form's custom message back to the shared default by
     * deleting its wa_lead_notify_form_template override row.
     */
    public function actionResetNotifyTemplate() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }

        $webFormId = (int) Yii::app()->request->getPost('webFormId', 0);

        try {
            if ($webFormId) {
                $leadSource = Yii::app()->db->createCommand()
                    ->select('leadSource')->from('x2_web_forms')
                    ->where('id=:id', array(':id' => $webFormId))
                    ->queryScalar();
                if ($leadSource) {
                    $this->callWaHub('DELETE', '/admin/lead-notify-template?leadSource=' . urlencode($leadSource));
                }
            }
            Yii::app()->user->setFlash('success', 'Reverted to the default message.');
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('editNotifyTemplate', 'webFormId' => $webFormId ?: null));
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
            $skippedCount = 0;
            if (!empty($selectedContacts)) {
                $contacts = Contacts::model()->findAllByPk($selectedContacts);
                foreach ($contacts as $contact) {
                    $phone = $this->toWhatsAppPhone($contact->phone, $contact->country);
                    if ($phone) {
                        $phones[] = $phone;
                    } else {
                        $skippedCount++;
                    }
                }
            }

            if (empty($phones)) {
                throw new CException('No contacts with phone numbers selected');
            }

            $payload = array('phones' => $phones);
            $result = $this->callWaHub('POST', '/admin/groups/' . urlencode($groupId) . '/members', $payload);

            if (isset($result['ok']) && $result['ok']) {
                $successMsg = 'Added ' . $result['added'] . ' members to group';
                if ($skippedCount > 0) {
                    $successMsg .= ". $skippedCount selected contact(s) skipped (no usable phone number)";
                }
                Yii::app()->user->setFlash('success', $successMsg);
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
     * and given an iframe embed code at marketing/webleadForm) — a compact
     * list with a read-only notification summary per form; the actual
     * pracharak/group editing, iframe URL, short link, and QR code live on
     * actionWebFormNotifyView() below (one form's full detail), matching
     * how index()/view() are split for WhatsApp Groups themselves.
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
            ->order('name ASC')
            ->queryAll();
        $notifyMap = array();
        foreach (Yii::app()->db->createCommand()->select('*')->from('wa_webform_notify')->queryAll() as $row) {
            $notifyMap[$row['webFormId']] = $row['pracharakId'];
        }
        $groupNotifyMap = array(); // webFormId => array of groupId (WhatsApp JID strings)
        $groupMapRows = Yii::app()->db->createCommand()
            ->select('f.id AS webFormId, m.groupId')
            ->from('wa_lead_notify_group_map m')
            ->join('x2_web_forms f', 'f.leadSource = m.leadSource')
            ->queryAll();
        foreach ($groupMapRows as $row) {
            $groupNotifyMap[$row['webFormId']][] = $row['groupId'];
        }

        // Resolve pracharakId/groupId to display names for the list — the
        // detail page (actionWebFormNotifyView) already does this per-form;
        // here it's done once for every form shown.
        $pracharaksById = array();
        foreach (($this->getPracharakContacts() ?: array()) as $sp) {
            $pracharaksById[(string) $sp['id']] = $sp['name'];
        }
        $groupNamesById = array();
        try {
            foreach ($this->callWaHub('GET', '/admin/groups') as $g) {
                $groupNamesById[$g['groupId']] = $g['groupName'];
            }
        } catch (Exception $e) {
            // Best-effort — group names just show as their raw id if wa-hub is unreachable.
        }

        $this->render('webFormNotify', array(
            'forms' => $forms,
            'notifyMap' => $notifyMap,
            'groupNotifyMap' => $groupNotifyMap,
            'pracharaksById' => $pracharaksById,
            'groupNamesById' => $groupNamesById,
        ));
    }

    /**
     * One Web Lead Form's full detail — iframe URL, a cached tinyurl.com
     * short link, a scannable QR code (reusing actionQrForUrl(), the same
     * proxy the Lead Forms list already uses), status/schedule management,
     * and the combined pracharak + WhatsApp-group notification editor.
     * Mirrors actionView($groupId) for WhatsApp Groups — same page shape.
     */
    public function actionWebFormNotifyView($webFormId) {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        $this->ensureWebFormManagementColumns();

        $form = Yii::app()->db->createCommand()
            ->select('*')->from('x2_web_forms')
            ->where('id=:id AND type=:type', array(':id' => $webFormId, ':type' => 'weblead'))
            ->queryRow();
        if (!$form) {
            Yii::app()->user->setFlash('error', 'Form not found.');
            $this->redirect(array('webFormNotify'));
        }

        $iframeUrl = rtrim(Yii::app()->request->getHostInfo(), '/') .
            '/index.php/contacts/contacts/weblead?webFormId=' . $form['id'];

        // If a custom URL is set (e.g. a page on the admin's own domain
        // that embeds this form's iframe), the short link and QR code
        // target THAT instead of the raw X2CRM iframe URL — the embed
        // code below always uses $iframeUrl regardless, since that's the
        // actual working form endpoint to embed.
        $targetUrl = !empty($form['customUrl']) ? $form['customUrl'] : $iframeUrl;

        // Cache the short link once per (form, target URL) pair — same
        // tinyurl.com lookup x2_custom_lead_forms already uses, just
        // without the WhatsApp message side-effect that endpoint normally
        // sends. actionSaveCustomUrl() clears tinyUrl whenever customUrl
        // changes, so this re-fires for the new target on the next view.
        if (empty($form['tinyUrl'])) {
            try {
                $result = $this->callWaHub('GET', '/admin/tinyurl?url=' . urlencode($targetUrl));
                if (!empty($result['tinyUrl'])) {
                    Yii::app()->db->createCommand()->update('x2_web_forms',
                        array('tinyUrl' => $result['tinyUrl']), 'id=:id', array(':id' => $webFormId));
                    $form['tinyUrl'] = $result['tinyUrl'];
                }
            } catch (Exception $e) {
                // Non-fatal — page still renders with just the raw target URL.
            }
        }

        $pracharaks = $this->getPracharakContacts();
        try {
            $groups = $this->callWaHub('GET', '/admin/groups');
        } catch (Exception $e) {
            $groups = array();
        }
        $currentPracharak = Yii::app()->db->createCommand()
            ->select('pracharakId')->from('wa_webform_notify')
            ->where('webFormId=:id', array(':id' => $webFormId))
            ->queryScalar();
        $currentGroups = array();
        if (!empty($form['leadSource'])) {
            $currentGroups = Yii::app()->db->createCommand()
                ->select('groupId')->from('wa_lead_notify_group_map')
                ->where('leadSource=:ls', array(':ls' => $form['leadSource']))
                ->queryColumn();
        }

        $qrStyles = array('plain', 'logo-small', 'logo-medium');
        $qrUrls = array();
        foreach ($qrStyles as $s) {
            $qrUrls[$s] = $this->createUrl('qrForUrl', array('url' => $targetUrl, 'style' => $s));
        }

        $this->render('webFormNotifyView', array(
            'form' => $form,
            'iframeUrl' => $iframeUrl,
            'targetUrl' => $targetUrl,
            'qrUrls' => $qrUrls,
            'currentQrStyle' => !empty($form['qrStyle']) ? $form['qrStyle'] : 'plain',
            'pracharaks' => $pracharaks === null ? array() : $pracharaks,
            'hasPracharakList' => $pracharaks !== null,
            'groups' => $groups,
            'currentPracharak' => $currentPracharak === false ? '' : $currentPracharak,
            'currentGroups' => $currentGroups,
        ));
    }

    /**
     * Persists which QR style (plain / small logo / medium logo) a Web
     * Lead Form's detail page should show as "the" QR code going forward.
     * Purely cosmetic/no side effects beyond this one column — the actual
     * image is always generated fresh by wa-hub's qr-for-url.png, nothing
     * to regenerate or invalidate here.
     */
    public function actionSaveQrStyle() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }
        $this->ensureWebFormManagementColumns();

        $webFormId = (int) Yii::app()->request->getPost('webFormId');
        $qrStyle = Yii::app()->request->getPost('qrStyle', 'plain');
        $validStyles = array('plain', 'logo-small', 'logo-medium');
        if (!in_array($qrStyle, $validStyles, true)) {
            $qrStyle = 'plain';
        }

        Yii::app()->db->createCommand()->update('x2_web_forms',
            array('qrStyle' => $qrStyle), 'id=:id', array(':id' => $webFormId));
        Yii::app()->user->setFlash('success', 'QR code style saved.');

        $this->redirect(array('webFormNotifyView', 'webFormId' => $webFormId));
    }

    /**
     * Sets or clears a custom URL for one Web Lead Form (e.g. a page on
     * the admin's own domain that embeds this form's iframe) — the short
     * link and QR code on webFormNotifyView target this instead of the
     * raw X2CRM iframe URL once set. Clears the cached tinyUrl so it
     * regenerates for the new target on the next view.
     */
    public function actionSaveCustomUrl() {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }
        if (!Yii::app()->request->isPostRequest) {
            throw new CException('Invalid request');
        }
        $this->ensureWebFormManagementColumns();

        $webFormId = (int) Yii::app()->request->getPost('webFormId');
        $customUrl = trim(Yii::app()->request->getPost('customUrl', ''));

        try {
            if ($customUrl !== '' && !filter_var($customUrl, FILTER_VALIDATE_URL)) {
                throw new CException('That doesn\'t look like a valid URL.');
            }
            Yii::app()->db->createCommand()->update('x2_web_forms', array(
                'customUrl' => $customUrl !== '' ? $customUrl : null,
                'tinyUrl' => null,
            ), 'id=:id', array(':id' => $webFormId));
            Yii::app()->user->setFlash('success', $customUrl !== ''
                ? 'Custom URL saved — short link and QR code will regenerate for it.'
                : 'Custom URL cleared — short link and QR code will regenerate for the iframe URL.');
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('webFormNotifyView', 'webFormId' => $webFormId));
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
        $groupIds = (array) Yii::app()->request->getPost('groupIds', array());

        try {
            $form = Yii::app()->db->createCommand()
                ->select('id, name, leadSource, generateLead')
                ->from('x2_web_forms')
                ->where('id=:id', array(':id' => $webFormId))
                ->queryRow();
            if (!$form) {
                throw new CException('Form not found');
            }

            // Defensive: only accept groupIds that actually exist right now,
            // same reasoning as the pracharak validIds check below.
            try {
                $allGroups = $this->callWaHub('GET', '/admin/groups');
            } catch (Exception $e) {
                $allGroups = array();
            }
            $validGroupIds = array_map(function ($g) { return $g['groupId']; }, $allGroups);
            $groupIds = array_values(array_intersect($groupIds, $validGroupIds));

            $wantsNotify = ($pracharakId !== '') || !empty($groupIds);

            if (!$wantsNotify) {
                Yii::app()->db->createCommand()->delete('wa_webform_notify', 'webFormId=:id', array(':id' => $webFormId));
                if (!empty($form['leadSource'])) {
                    Yii::app()->db->createCommand()->delete('wa_lead_notify_group_map',
                        'leadSource=:ls', array(':ls' => $form['leadSource']));
                }
                Yii::app()->user->setFlash('success', 'WhatsApp notifications turned off for "' . $form['name'] . '".');
            } else {
                if ($pracharakId !== '') {
                    $validIds = array_map(function ($sp) { return (string) $sp['id']; }, $this->getPracharakContacts() ?: array());
                    if (!in_array((string) $pracharakId, $validIds, true)) {
                        throw new CException('That contact is not currently in the "Pracharak" list.');
                    }
                }

                // leadSource/generateLead backfill now runs whenever EITHER
                // a pracharak or any groups are being set, not just a
                // pracharak — group targeting needs the same leadSource-
                // tagged Lead record to detect new submissions.
                if (empty($form['leadSource'])) {
                    $form['leadSource'] = 'WebForm-' . $webFormId;
                    Yii::app()->db->createCommand()->update('x2_web_forms',
                        array('leadSource' => $form['leadSource'], 'generateLead' => 1),
                        'id=:id', array(':id' => $webFormId));
                } elseif (!$form['generateLead']) {
                    Yii::app()->db->createCommand()->update('x2_web_forms',
                        array('generateLead' => 1), 'id=:id', array(':id' => $webFormId));
                }

                if ($pracharakId !== '') {
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
                } else {
                    // Pracharak explicitly off but group targeting is on —
                    // keep a row (pracharakId NULL) rather than delete it,
                    // so wa-hub's poller still has a lastPolledAt watermark
                    // to track for this form's group-only broadcasts.
                    $exists = Yii::app()->db->createCommand()
                        ->select('webFormId')->from('wa_webform_notify')
                        ->where('webFormId=:id', array(':id' => $webFormId))
                        ->queryScalar();
                    if ($exists !== false) {
                        Yii::app()->db->createCommand()->update('wa_webform_notify',
                            array('pracharakId' => null),
                            'webFormId=:id', array(':id' => $webFormId));
                    } else {
                        Yii::app()->db->createCommand()->insert('wa_webform_notify', array(
                            'webFormId' => $webFormId,
                            'pracharakId' => null,
                            'lastPolledAt' => time(),
                        ));
                    }
                }

                Yii::app()->db->createCommand()->delete('wa_lead_notify_group_map',
                    'leadSource=:ls', array(':ls' => $form['leadSource']));
                foreach ($groupIds as $gid) {
                    Yii::app()->db->createCommand()->insert('wa_lead_notify_group_map', array(
                        'leadSource' => $form['leadSource'],
                        'groupId' => $gid,
                    ));
                }

                Yii::app()->user->setFlash('success', 'WhatsApp notifications updated for "' . $form['name'] . '".');
            }
        } catch (Exception $e) {
            Yii::app()->user->setFlash('error', $e->getMessage());
        }

        $this->redirect(array('webFormNotifyView', 'webFormId' => $webFormId));
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
        $hasTinyUrl = $db->createCommand(
            "SELECT COUNT(*) FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x2_web_forms' AND COLUMN_NAME = 'tinyUrl'"
        )->queryScalar();
        if (!$hasTinyUrl) {
            $db->createCommand("ALTER TABLE x2_web_forms ADD COLUMN tinyUrl VARCHAR(255) NULL")->execute();
        }
        $hasCustomUrl = $db->createCommand(
            "SELECT COUNT(*) FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x2_web_forms' AND COLUMN_NAME = 'customUrl'"
        )->queryScalar();
        if (!$hasCustomUrl) {
            $db->createCommand("ALTER TABLE x2_web_forms ADD COLUMN customUrl VARCHAR(500) NULL")->execute();
        }
        $hasQrStyle = $db->createCommand(
            "SELECT COUNT(*) FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'x2_web_forms' AND COLUMN_NAME = 'qrStyle'"
        )->queryScalar();
        if (!$hasQrStyle) {
            $db->createCommand("ALTER TABLE x2_web_forms ADD COLUMN qrStyle VARCHAR(20) NOT NULL DEFAULT 'plain'")->execute();
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
        $this->redirect(array('webFormNotifyView', 'webFormId' => $id));
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
        $this->redirect(array('webFormNotifyView', 'webFormId' => $id));
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
        $this->redirect(array('webFormNotifyView', 'webFormId' => $id));
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
     * thumbnails, the Web Lead Form detail page's QR picker), distinct
     * from qrImage which is specifically the WhatsApp pairing QR.
     * Optional $style ('plain' | 'logo-small' | 'logo-medium') composites
     * the site's own menu logo (X2CRM's top-bar icon, Media::getMenuLogo()
     * — a small square mark, unlike the fuller login-screen wordmark) into
     * the center — resolved here (not by wa-hub, which has no access to
     * X2CRM's Media system) and passed through as a fetchable URL.
     */
    public function actionQrForUrl($url, $style = 'plain') {
        if (!Yii::app()->params->isAdmin) {
            throw new CHttpException(403, 'Admin access required');
        }

        $qs = '?url=' . urlencode($url) . '&style=' . urlencode($style);
        if ($style === 'logo-small' || $style === 'logo-medium') {
            $menuLogo = Media::getMenuLogo();
            if ($menuLogo && $menuLogo->fileName !== 'uploads/protected/logos/yourlogohere.png') {
                // Deliberately NOT getPublicUrl() — that builds a URL from
                // the current request's Host header (e.g. localhost:8080
                // locally, or the public HTTPS domain in production),
                // neither of which wa-hub's separate container can reach.
                // wa-hub talks to this app the same way this controller
                // already talks to wa-hub: over the internal docker
                // network, by service name.
                $internalLogoUrl = 'http://x2crm/index.php/media/media/getFile/id/' .
                    $menuLogo->id . '/key/' . $menuLogo->getAccessKey();
                $qs .= '&logoUrl=' . urlencode($internalLogoUrl);
            }
        }

        $ch = curl_init($this->waHubUrl . '/admin/qr-for-url.png' . $qs);
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

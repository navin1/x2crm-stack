<?php

/**
 * X2FlowAction that posts a message into a WhatsApp group, via wa-hub
 * (the WhatsApp Groups integration's Node service). Group-only by design —
 * there is no per-contact/individual-DM counterpart to this action.
 *
 * @package application.components.x2flow.actions
 */
class X2FlowWhatsAppGroupMessage extends X2FlowAction {

    public $title = 'Send WhatsApp Group Message';
    public $info = 'Posts a message into a WhatsApp group tracked under WhatsApp Groups.';

    private $waHubUrl;
    private $waHubUser;
    private $waHubPass;

    public function __construct() {
        $this->waHubUrl = getenv('WA_HUB_URL') ?: 'http://wa_hub:3001';
        $this->waHubUser = getenv('X2CRM_API_USERNAME') ?: 'admin';
        $this->waHubPass = getenv('X2CRM_API_KEY') ?: '';
    }

    /**
     * groupId => "groupName" options for the builder's dropdown. Read
     * directly from wa_groups (shared DB with wa-hub) rather than an HTTP
     * round-trip to wa-hub, since paramRules() is called on every flow
     * editor render.
     */
    private function getGroupOptions() {
        $options = array();
        try {
            $rows = Yii::app()->db->createCommand()
                ->select('groupId, groupName')
                ->from('wa_groups')
                ->order('groupName ASC')
                ->queryAll();
            foreach ($rows as $row) {
                $options[$row['groupId']] = $row['groupName'];
            }
        } catch (Exception $e) {
            // wa_groups may not exist yet (wa-hub never started) — leave
            // the dropdown empty rather than break the flow editor.
        }
        return $options;
    }

    public function paramRules() {
        return array_merge(
            parent::paramRules(), array(
            'title' => Yii::t('studio', $this->title),
            'info' => Yii::t('studio', $this->info),
            'options' => array(
                array(
                    'name' => 'groupId',
                    'label' => Yii::t('studio', 'WhatsApp Group'),
                    'type' => 'dropdown',
                    'options' => $this->getGroupOptions(),
                ),
                array(
                    'name' => 'message',
                    'label' => Yii::t('studio', 'Message'),
                    'type' => 'text',
                ),
            ),
        ));
    }

    public function execute(&$params) {
        $groupId = $this->parseOption('groupId', $params);
        $message = $this->parseOption('message', $params);

        if (empty($groupId)) {
            return array(false, Yii::t('app', 'No WhatsApp group selected.'));
        }
        if ($message === null || trim((string) $message) === '') {
            return array(false, Yii::t('app', 'Message cannot be blank.'));
        }

        try {
            $this->callWaHub('POST', '/admin/groups/' . urlencode($groupId) . '/send', array('text' => $message));
        } catch (Exception $e) {
            return array(false, $e->getMessage());
        }

        return array(true, YII_UNIT_TESTING ? $message : Yii::t('app', 'WhatsApp group message sent.'));
    }

    /**
     * Same request shape as WhatsappGroupsController::callWaHub() (curl +
     * basic auth against wa-hub) — duplicated rather than shared since that
     * method is private to its own controller; matches how
     * X2SlackIntegration inlines its own API call rather than factoring
     * out a shared HTTP helper.
     */
    private function callWaHub($method, $endpoint, $data = null) {
        $ch = curl_init($this->waHubUrl . $endpoint);
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
            throw new CException('wa-hub error: ' . $waError);
        }

        return $result;
    }

}

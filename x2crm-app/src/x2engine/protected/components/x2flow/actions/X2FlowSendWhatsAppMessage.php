<?php

/**
 * X2FlowAction that sends a WhatsApp message directly to an individual
 * phone number, via wa-hub. Counterpart to X2FlowWhatsAppGroupMessage
 * (which is group-only) — this one is DM-only, no group option.
 *
 * @package application.components.x2flow.actions
 */
class X2FlowSendWhatsAppMessage extends X2FlowAction {

    public $title = 'Send WhatsApp Message';
    public $info = 'Sends a WhatsApp message directly to a phone number (e.g. {phone}).';

    private $waHubUrl;
    private $waHubUser;
    private $waHubPass;

    public function __construct() {
        $this->waHubUrl = getenv('WA_HUB_URL') ?: 'http://wa_hub:3001';
        $this->waHubUser = getenv('X2CRM_API_USERNAME') ?: 'admin';
        $this->waHubPass = getenv('X2CRM_API_KEY') ?: '';
    }

    public function paramRules() {
        return array_merge(
            parent::paramRules(), array(
            'title' => Yii::t('studio', $this->title),
            'info' => Yii::t('studio', $this->info),
            'options' => array(
                array(
                    'name' => 'to',
                    'label' => Yii::t('studio', 'Send To (Phone Number)'),
                ),
                array(
                    'name' => 'message',
                    'label' => Yii::t('studio', 'Message'),
                    'type' => 'text',
                ),
                array(
                    'name' => 'imageUrl',
                    'label' => Yii::t('studio', 'Image URL (optional)'),
                    'optional' => 1,
                ),
            ),
        ));
    }

    public function execute(&$params) {
        $to = $this->parseOption('to', $params, false);
        $message = $this->parseOption('message', $params);
        $imageUrl = $this->parseOption('imageUrl', $params);

        if ($to === null || trim((string) $to) === '') {
            return array(false, Yii::t('app', 'No destination phone number given.'));
        }
        if ($message === null || trim((string) $message) === '') {
            return array(false, Yii::t('app', 'Message cannot be blank.'));
        }

        // Same "bare 10-digit local number" normalization used elsewhere
        // in this stack — only kicks in if {to} resolved to exactly 10
        // digits (i.e. came from a {phone}-style variable on the record
        // this flow is bound to); a literal, already-complete number
        // typed straight into this field is left untouched either way.
        $digits = preg_replace('/\D/', '', (string) $to);
        if (strlen($digits) === 10 && isset($params['model']) && $params['model']->hasAttribute('country')) {
            $normalized = WhatsAppPhoneUtil::toWhatsAppPhone($digits, $params['model']->country);
            if ($normalized === null) {
                return array(false, Yii::t('app',
                    'Phone number is missing a country code and the contact\'s country isn\'t one this can map confidently — not sent.'));
            }
            $to = $normalized;
        }

        $payload = array('phone' => $to, 'text' => $message);
        if (!empty($imageUrl)) {
            $payload['imageUrl'] = $imageUrl;
        }

        try {
            $this->callWaHub('POST', '/admin/send-message', $payload);
        } catch (Exception $e) {
            return array(false, $e->getMessage());
        }

        // Log to the bound record's own Activity/History feed, same
        // mechanism X2CRM uses for logged emails (Actions::associateAction,
        // see InlineEmail::recordEmailSent) — only when this flow is
        // actually bound to a record (e.g. a Contact), not for a
        // hardcoded/unrelated "to" number.
        if (isset($params['model']) && $params['model'] instanceof X2Model && !$params['model']->isNewRecord) {
            Actions::associateAction($params['model'], array(
                'type' => 'whatsapp',
                'subject' => 'WhatsApp Message Sent',
                'actionDescription' => $message . (!empty($imageUrl) ? "\n[with image attachment]" : ''),
                'dueDate' => time(),
            ));
        }

        return array(true, YII_UNIT_TESTING ? $message : Yii::t('app', 'WhatsApp message sent.'));
    }

    /**
     * Same request shape as WhatsappGroupsController::callWaHub() — see
     * X2FlowWhatsAppGroupMessage for why this is duplicated rather than
     * shared.
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

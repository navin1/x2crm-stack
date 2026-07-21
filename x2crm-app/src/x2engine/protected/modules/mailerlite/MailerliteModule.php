<?php
/**
 * @package application.modules.mailerlite
 */
class MailerliteModule extends X2WebModule {
    public $defaultController = 'mailerlite';

    public function init() {
        $this->setImport(array(
            'mailerlite.models.*',
            'application.components.*',
        ));
    }
}

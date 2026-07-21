<?php
/**
 * @package application.modules.whatsappGroups
 */
class WhatsappGroupsModule extends X2WebModule {
    public $defaultController = 'whatsappGroups';

    public function init() {
        $this->setImport(array(
            'whatsappGroups.models.*',
            'application.components.*',
        ));
    }
}

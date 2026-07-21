<?php

return array(
    'name' => "WhatsApp Groups",
    'install' => array(
        implode(DIRECTORY_SEPARATOR, array(__DIR__, 'data', 'install.sql')),
    ),
    'uninstall' => array(
        implode(DIRECTORY_SEPARATOR, array(__DIR__, 'data', 'uninstall.sql')),
    ),
    'editable' => true,
    'searchable' => false,
    'adminOnly' => false,
    'custom' => true,
    'toggleable' => false,
    'version' => '1.0',
);

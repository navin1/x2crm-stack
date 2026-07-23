<?php
require_once(implode(DIRECTORY_SEPARATOR,array(
    Yii::app()->basePath,
    'components',
    'phpMailer',
    'class.phpmailer.php'
)));
$mode = 0;

switch($mode) {
    case 1:
        return array(
            'code' => 404,
            'message' => 'Bad domain name!',
            'exception' => new PHPMailer\PHPMailer\Exception('bad domain name!', 404, null)
        );
        break;
    case 2:
        return array(
            'code' => 401,
            'message' => 'SMTP authentication failed!',
            'exception' => new PHPMailer\PHPMailer\Exception('SMTP authentication failed!',phpmailer::STOP_CRITICAL,null),
        );
        break;
    default:
        return array(
            'code' => 200,
            'message' => 'Email successfully sent!',
            'exception' => null
        );
}

?>

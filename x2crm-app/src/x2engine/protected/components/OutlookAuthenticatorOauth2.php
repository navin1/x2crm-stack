<?php
/***********************************************************************************
 * X2Engine Open Source Edition is a customer relationship management program developed by
 * X2 Engine, Inc. Copyright (C) 2011-2022 X2 Engine Inc.
 * 
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY X2ENGINE, X2ENGINE DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more
 * details.
 * 
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 * 
 * You can contact X2Engine, Inc. P.O. Box 610121, Redwood City,
 * California 94061, USA. or at email address contact@x2engine.com.
 * 
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 * 
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * X2 Engine" logo. If the display of the logo is not reasonably feasible for
 * technical reasons, the Appropriate Legal Notices must display the words
 * "Powered by X2 Engine".
 **********************************************************************************/





/**
 * Wrapper class for interaction with Outlook's API and authentication methods.
 * This is designed to handle all user authentication and returning of Outlook API
 * Client classes in an easy to use manner. Much of the code is from Outlook's stock
 * PHP API examples, but it has been modified to be usable with our software and
 * as such some of the comments/classes are Outlook developers' not mine.
 * this is an extension for outlook integration, wrote this to not mess with the calendar outlook 
 */
class OutlookAuthenticatorOauth2 {

    /**
     * Client ID of the Outlook API Project
     * @var string
     */
    public $clientId = '';

    /**
     * Client secret of the Outlook API Project
     * @var string
     */
    public $clientSecret = '';

    /**
     * Redirect URI for the authentication request
     * @var string
     */
    public $redirectUri = '';

    /**
     * A list of scopes required by the Outlook API to use for Outlook Integration
     * within the software. This list defines the permissions that Outlook will ask
     * for when a user is authenticating with them and X2.
     * @var array
     */
    public $scopes = array(
        'https://graph.microsoft.com/.default',
        'https://graph.microsoft.com/mail.send', // offline access
        'https%3A%2F%2Fgraph.microsoft.com', // Graph website
        '2Fcalendars.read', // Read Calendar
    );

    /**
     * An array of errors to be returned or displayed in case something goes wrong.
     * @var array
     */
    private $_errors;

    /**
     * Master control variable that prevents most methods being called unless
     * Outlook Integration is enabled in the admin settings.
     * @var boolean
     */
    private $_enabled;
    
        /**
     * Constructor that sets up the Authenticator with all the required data to
     * connect to Outlook properly.
     */
    public function __construct($scenario = null) {
        $this->_enabled = Yii::app()->settings->outlookIntegration; // Check if integration is enabled in the first place
        //get credentials id and secret
        $admin = Admin::model()->findByPk (1);
        $id = $admin->outlookCredentialsId;
        $credential = Credentials::model()->findByAttributes(array('id'=>$id));
        $auth_credential = $credential->auth;
        $client_id = $auth_credential->outlookId;
        if ($this->_enabled) {
            $this->clientId = $auth_credential->outlookId;
            $this->clientSecret = $auth_credential->outlookSecret;
            if (empty($this->redirectUri)) {
                $this->redirectUri = (@$_SERVER['HTTPS'] == 'on' ? 'https://' : 'http://') .
                        $_SERVER['HTTP_HOST'] . Yii::app()->controller->createUrl('');
            }
        }
    }

     /* Retrieve the authorization URL.
     *
     * @param String $emailAddress User's e-mail address.
     * @param String $state State for the authorization URL.
     * @return String Authorization URL to redirect the user to.
     */
    public function getAuthorizationUrl($Credentials, $isImap = false) {
        //get credentials id and secret
    $admin = Admin::model()->findByPk (1);
    $id = $admin->outlookCredentialsId;
    $credential = Credentials::model()->findByAttributes(array('id'=>$id));
    $auth_credential = $credential->auth;
    $client_id = $auth_credential->outlookId;
    //openid offline_access https://graph.microsoft.com/.default
    //uri encoding of above perameters 
    $scope = "profile openid offline_access email https://graph.microsoft.com/.default";  
    if($isImap)$scope = "profile openid offline_access email https://outlook.office.com/IMAP.AccessAsUser.All"; 
    //set a state to pass to microsoft and save the id of the credentials so we know which one we are updating
    $_SESSION['microsoftID'] = $Credentials->id;
    $length = 10;    
    $_SESSION['microsoftState'] = substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'),1,$length);
    $urlReturn = (@$_SERVER['HTTPS'] == 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . 
                            Yii::app()->controller->createUrl('/profile/RepOutlookOauth2');
    if($isImap)
        $urlReturn = (@$_SERVER['HTTPS'] == 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . 
                            Yii::app()->controller->createUrl('/profile/RepOutlookOauth2Imap');
    $url = "https://login.microsoftonline.com/common/oauth2/v2.0/authorize?client_id=". $client_id 
            . "&response_type=code&response_mode=query&scope=".urlencode($scope)."&state=" .
            urlencode($_SESSION['microsoftState'])."&redirect_uri=".urlencode($urlReturn);
    return $url;
    }

    public static function getAndsetTokens($Credentials){
        $ch = curl_init();

        $admin = Admin::model()->findByPk (1);
        $id = $admin->outlookCredentialsId;
        $credential = Credentials::model()->findByAttributes(array('id'=>$id));
        $auth_credential = $credential->auth;
        $client_id = $auth_credential->outlookId;
        $client_secret = $auth_credential->outlookSecret;
        $urlReturn = (@$_SERVER['HTTPS'] == 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . 
                            Yii::app()->controller->createUrl('/profile/RepOutlookOauth2');
        
        $scope = 'profile openid offline_access email https://graph.microsoft.com/.default';
        
        //create header and body for the POST request
        curl_setopt($ch, CURLOPT_URL,"https://login.microsoftonline.com/common/oauth2/v2.0/token");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type' => 'application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS,
            http_build_query(array('code' => $Credentials->auth->returnCode, 
                                   'grant_type' => 'authorization_code',
                                   'client_id' => $client_id,
                                   'client_secret' => $client_secret,
                                   //need to pass the same return uri form the code request
                                   'redirect_uri'=> $urlReturn,
                                   'scope' => $scope,
            )));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //execute url
        $server_output = curl_exec($ch);
        curl_close ($ch);
    
        $result = CJSON::decode($server_output);
        if(isset($result['error'])){
            printR($result,1);
            throw new Exception(500, $result['error_description']);
        }
 
	$access_token = $result['access_token'];
        $refresh_token = $result['refresh_token'];
       
        $Credentials->auth->accessToken = $access_token;
        $Credentials->auth->refreshToken = $refresh_token;
        $Credentials->save();
        return;
    }
    
    public static function getAndsetTokensImap($Credentials){
        $ch = curl_init();

        $admin = Admin::model()->findByPk (1);
        $id = $admin->outlookCredentialsId;
        $credential = Credentials::model()->findByAttributes(array('id'=>$id));
        $auth_credential = $credential->auth;
        $client_id = $auth_credential->outlookId;
        $client_secret = $auth_credential->outlookSecret;
        $urlReturn = (@$_SERVER['HTTPS'] == 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . 
                            Yii::app()->controller->createUrl('/profile/RepOutlookOauth2Imap');
        
        $scope = 'profile openid offline_access email https://outlook.office.com/IMAP.AccessAsUser.All';
        
        //create header and body for the POST request
        curl_setopt($ch, CURLOPT_URL,"https://login.microsoftonline.com/common/oauth2/v2.0/token");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type' => 'application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS,
            http_build_query(array('code' => $Credentials->auth->IMAPreturnCode, 
                                   'grant_type' => 'authorization_code',
                                   'client_id' => $client_id,
                                   'client_secret' => $client_secret,
                                   //need to pass the same return uri form the code request
                                   'redirect_uri'=> $urlReturn,
                                   'scope' => $scope,
            )));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //execute url
        $server_output = curl_exec($ch);
        curl_close ($ch);
    
        $result = CJSON::decode($server_output);
        if(isset($result['error'])){
            printR($result,1);
            throw new Exception(500, $result['error_description']);
        }
 
	$access_token = $result['access_token'];
        $refresh_token = $result['refresh_token'];
       
        $Credentials->auth->IMAPaccessToken = $access_token;
        $Credentials->auth->IMAPrefreshToken = $refresh_token;
        $Credentials->save();
        return;
    }    
    
    
    public static function getAccessToken($credentials = null) {  
        $currentuser = Yii::app()->user->getName();
        $profile = Profile::model()->findByAttributes(array('username'=>$currentuser));
        $refresh = $profile->outlookRefreshToken; 
       
        //get credentials id and secret
        $admin = Admin::model()->findByPk (1);
        $id = $admin->outlookCredentialsId;
        $credential = Credentials::model()->findByAttributes(array('id'=>$id));
        if(isset($credentials)){
            $refresh = $credentials->auth->refreshToken;
        }
        $auth_credential = $credential->auth;
        $client_id = $auth_credential->outlookId;
        $client_secret = $auth_credential->outlookSecret;
        
        //every input needs to exist
    	if($client_id == null || $client_secret == null || $refresh == null){
            return false;
        }
        
        $ch = curl_init();
        //create header and body for the POST request
        curl_setopt($ch, CURLOPT_URL,"https://login.microsoftonline.com/common/oauth2/v2.0/token");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type' => 'application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS,
            http_build_query(array('refresh_token' => $refresh, 
                                   'grant_type' => 'refresh_token',
                                   'client_id' => $client_id,
                                   'client_secret' => $client_secret
            )));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //execute url
        $server_output = curl_exec($ch);
        curl_close ($ch);
        
        $result = CJSON::decode($server_output);

        if(isset($result['error'])){
            throw new Exception(500, $result['error']);
        }
	$access_token = $result['access_token'];
        
        return $access_token;
    }
    
    public static function getAccessTokenIMAP($credentials = null) {  
        $currentuser = Yii::app()->user->getName();
        $profile = Profile::model()->findByAttributes(array('username'=>$currentuser));
        $refresh = $profile->outlookRefreshToken; 
       
        //get credentials id and secret
        $admin = Admin::model()->findByPk (1);
        $id = $admin->outlookCredentialsId;
        $credential = Credentials::model()->findByAttributes(array('id'=>$id));
        if(isset($credentials)){
            $refresh = $credentials->auth->IMAPrefreshToken;
        }
        $auth_credential = $credential->auth;
        $client_id = $auth_credential->outlookId;
        $client_secret = $auth_credential->outlookSecret;
        
        //every input needs to exist
    	if($client_id == null || $client_secret == null || $refresh == null){
            return false;
        }
        
        $ch = curl_init();
        //create header and body for the POST request
        curl_setopt($ch, CURLOPT_URL,"https://login.microsoftonline.com/common/oauth2/v2.0/token");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type' => 'application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS,
            http_build_query(array('refresh_token' => $refresh, 
                                   'grant_type' => 'refresh_token',
                                   'client_id' => $client_id,
                                   'client_secret' => $client_secret
            )));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //execute url
        $server_output = curl_exec($ch);
        curl_close ($ch);
        
        $result = CJSON::decode($server_output);

        if(isset($result['error'])){
            throw new Exception(500, $result['error']);
        }
	$access_token = $result['access_token'];
        
        return $access_token;
    }
    
}

?>

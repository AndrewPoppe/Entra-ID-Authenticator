<?php

namespace YaleREDCap\YaleREDCapAuthenticator;

class Yale_EntraID_Authenticator
{
    private $client_id;
    private $ad_tenant;
    private $client_secret;
    private $redirect_uri;
    private $redirect_uri_spa;
    private $module;
    private $session_id;
    private $logout_uri;
    public function __construct(YaleREDCapAuthenticator $module, string $session_id = null)
    {
        $this->module           = $module;
        $this->client_id        = $this->module->framework->getSystemSetting('entraid-yale-client-id');  //Application (client) ID
        $this->ad_tenant        = $this->module->framework->getSystemSetting('entraid-yale-ad-tenant-id');  //Entra ID Tenant ID, with Multitenant apps you can use "common" as Tenant ID, but using specific endpoint is recommended when possible
        $this->client_secret    = $this->module->framework->getSystemSetting('entraid-yale-client-secret');  //Client Secret, remember that this expires someday unless you haven't set it not to do so
        $this->redirect_uri     = $this->module->framework->getSystemSetting('entraid-yale-redirect-url');  //This needs to match 100% what is set in Entra ID
        $this->redirect_uri_spa = $this->module->framework->getSystemSetting('entraid-yale-redirect-url-spa');  //This needs to match 100% what is set in Entra ID
        $this->session_id       = $session_id ?? session_id();
        $this->logout_uri       = $this->module->framework->getSystemSetting('entraid-yale-logout-url');
    }

    public function authenticate(bool $refresh = false)
    {
        $url = "https://login.microsoftonline.com/" . $this->ad_tenant . "/oauth2/v2.0/authorize?";
        $url .= "state=" . $this->session_id;
        $url .= "&scope=User.Read";
        $url .= "&response_type=code";
        $url .= "&approval_prompt=auto";
        $url .= "&client_id=" . $this->client_id;
        $url .= "&redirect_uri=" . urlencode($this->redirect_uri);
        $url .= $refresh ? "&prompt=login" : "";
        header("Location: " . $url);
        return;
    }

    public function getAuthData($state, $code)
    {
        $stateMatches = strcmp(session_id(), $state) == 0;
        if ( !$stateMatches ) {
            return;
        }

        //Verifying the received tokens with Azure and finalizing the authentication part
        $content = "grant_type=authorization_code";
        $content .= "&client_id=" . $this->client_id;
        $content .= "&redirect_uri=" . urlencode($this->redirect_uri);
        $content .= "&code=" . $code;
        $content .= "&client_secret=" . urlencode($this->client_secret);
        $options = array(
            "http" => array(  //Use "http" even if you send the request with https
                "method"  => "POST",
                "header"  => "Content-Type: application/x-www-form-urlencoded\r\n" .
                    "Content-Length: " . strlen($content) . "\r\n",
                "content" => $content
            )
        );
        $context = stream_context_create($options);
        $json    = file_get_contents("https://login.microsoftonline.com/" . $this->ad_tenant . "/oauth2/v2.0/token", false, $context);
        if ( $json === false ) {
            // errorhandler(array( "Description" => "Error received during Bearer token fetch.", "PHP_Error" => error_get_last(), "\$_GET[]" => $_GET, "HTTP_msg" => $options ), $error_email);
            return;
        }
        $authdata = json_decode($json, true);
        if ( isset($authdata["error"]) ) {
            // errorhandler(array( "Description" => "Bearer token fetch contained an error.", "\$authdata[]" => $authdata, "\$_GET[]" => $_GET, "HTTP_msg" => $options ), $error_email);
            return;
        }

        return $authdata;
    }

    public function getUserData($access_token)
    {

        //Fetching the basic user information that is likely needed by your application
        $options = array(
            "http" => array(  //Use "http" even if you send the request with https
                "method" => "GET",
                "header" => "Accept: application/json\r\n" .
                    "Authorization: Bearer " . $access_token . "\r\n"
            )
        );
        $context = stream_context_create($options);
        $json    = file_get_contents("https://graph.microsoft.com/v1.0/me?\$select=id,mail,givenName,surname,onPremisesSamAccountName,companyName,department,jobTitle,userType,accountEnabled", false, $context);
        $json2    = file_get_contents("https://graph.microsoft.com/v1.0/me/memberOf/microsoft.graph.group?\$select=displayName,id", false, $context);
        if ( $json === false ) {
            // errorhandler(array( "Description" => "Error received during user data fetch.", "PHP_Error" => error_get_last(), "\$_GET[]" => $_GET, "HTTP_msg" => $options ), $error_email);
            return;
        }

        $userdata = json_decode($json, true);  //This should now contain your logged on user information
        if ( isset($userdata["error"]) ) {
            // errorhandler(array( "Description" => "User data fetch contained an error.", "\$userdata[]" => $userdata, "\$authdata[]" => $authdata, "\$_GET[]" => $_GET, "HTTP_msg" => $options ), $error_email);
            return;
        }

        $groupdata = json_decode($json2, true);

        $userdata_parsed = [
            'user_email'     => $userdata['mail'],
            'user_firstname' => $userdata['givenName'],
            'user_lastname'  => $userdata['surname'],
            'netid'          => $userdata['onPremisesSamAccountName'],
            'company'        => $userdata['companyName'],
            'department'     => $userdata['department'],
            'job_title'      => $userdata['jobTitle'],
            'type'           => $userdata['userType'],
            'accountEnabled' => $userdata['accountEnabled'],
            'id'             => $userdata['id'],
            'groups'         => $groupdata['value']
        ];

        return $userdata_parsed;
    }

    public function checkGroupMembership($userData) {
        $userGroups = $userData['groups'];
        $groups = $this->module->framework->getSystemSetting('entraid-yale-allowed-groups');
        if (empty($groups)) {
            return true;
        }
        foreach ($userGroups as $group) {
            if (in_array($group['id'], $groups)) {
                return true;
            }
        }
        return false;
    }

    public function logout($entraid) {
        header("Location: " . $this->module->addQueryParameter($this->getLogoutUri(), 'logout_hint', $entraid));
        return;
    }

    public function getRedirectUri()
    {
        return $this->redirect_uri;
    }

    public function getRedirectUriSpa()
    {
        return $this->redirect_uri_spa;
    }

    public function getClientId()
    {
        return $this->client_id;
    }

    public function getAdTenant()
    {
        return $this->ad_tenant;
    }

    public function getLogoutUri()
    {
        return $this->logout_uri;
    }

}
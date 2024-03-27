<?php

namespace YaleREDCap\YaleREDCapAuthenticator;

/**
 * @property \ExternalModules\Framework $framework
 * @see Framework
 */

require_once 'YNHH_SAML_Authenticator.php';

class YaleREDCapAuthenticator extends \ExternalModules\AbstractExternalModule
{

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        // Normal Users
        if ( $action === 'eraseCasSession' ) {
            return $this->eraseCasSession();
        }

        // Admins only
        if ( !$this->framework->getUser()->isSuperUser() ) {
            throw new \Exception('Unauthorized');
        }
        if ( $action === 'isCasUser' ) {
            return $this->isCasUser($payload['username']);
        }
        if ( $action === 'getUserType' ) {
            return $this->getUserType($payload['username']);
        }
        if ( $action === 'convertTableUserToCasUser' ) {
            return $this->convertTableUserToCasUser($payload['username']);
        }
        if ( $action == 'convertCasUsertoTableUser' ) {
            return $this->convertCasUsertoTableUser($payload['username']);
        }
    }

    public function redcap_every_page_before_render($project_id = null)
    {   
        global $enable_user_allowlist, $homepage_contact, $homepage_contact_email, $lang;
        $page = defined('PAGE') ? PAGE : null;
        if ( empty ($page) ) {
            return;
        }

        // Handle E-Signature form action
        if ( $page === 'Locking/single_form_action.php' ) {
            if ( !isset ($_POST['esign_action']) || $_POST['esign_action'] !== 'save' || !isset ($_POST['username']) || !isset ($_POST['cas_code']) ) {
                return;
            }
            if ( $_POST['cas_code'] !== $this->getCode($_POST['username']) ) {
                $this->log('CAS Login E-Signature: Error authenticating user');
                $this->exitAfterHook();
                return;
            }
            $this->setCode($_POST['username'], '');

            global $auth_meth_global;
            $auth_meth_global = 'none';
            return;
        }

        // Already logged in to REDCap
        if ( (defined('USERID') && defined('USERID') !== '') || $this->framework->isAuthenticated() ) {
            return;
        }

        // Only authenticate if we're asked to (but include the login page HTML if we're not logged in)
        parse_str($_SERVER['QUERY_STRING'], $query);
        if ( !isset ($_GET['CAS_auth']) ) {
            return;
        }

        try {
            $userid = $this->authenticate();
            if ( $userid === false ) {
                $this->exitAfterHook();
                return;
            }

            // Successful authentication
            $this->framework->log('CAS Authenticator: Auth Succeeded', [
                "CASAuthenticator_NetId" => $userid,
                "page"                   => $page
            ]);

            // Trigger login
            \Authentication::autoLogin($userid);

            // Update last login timestamp
            \Authentication::setUserLastLoginTimestamp($userid);

            // Log the login
            \Logging::logPageView("LOGIN_SUCCESS", $userid);

            // Handle account-related things.
            // If the user does not exist, try to fetch user details and create them.
            if ( !$this->userExists($userid) ) {
                $userDetails = $this->fetchUserDetails($userid);
                if ( $userDetails ) {
                    $this->setUserDetails($userid, $userDetails);
                }
                $this->setCasUser($userid);
            }
            // If user is a table-based user, convert to CAS user
            elseif ( \Authentication::isTableUser($userid) ) {
                $this->convertTableUserToCasUser($userid);
            }
            // otherwise just make sure they are logged as a CAS user
            else {
                $this->setCasUser($userid);
            }

            // 2. If user allowlist is not enabled, all CAS users are allowed.
            // Otherwise, if not in allowlist, then give them error page.
            if ( $enable_user_allowlist && !$this->inUserAllowlist($userid) ) {
                session_unset();
                session_destroy();
                $objHtmlPage = new \HtmlPage();
                $objHtmlPage->addExternalJS(APP_PATH_JS . "base.js");
                $objHtmlPage->addStylesheet("home.css", 'screen,print');
                $objHtmlPage->PrintHeader();
                print "<div class='red' style='margin:40px 0 20px;padding:20px;'>
                            {$lang['config_functions_78']} \"<b>$userid</b>\"{$lang['period']}
                            {$lang['config_functions_79']} <a href='mailto:$homepage_contact_email'>$homepage_contact</a>{$lang['period']}
                        </div>
                        <button onclick=\"window.location.href='" . APP_PATH_WEBROOT_FULL . "index.php?logout=1';\">Go back</button>";
                print '<div id="my_page_footer">' . \REDCap::getCopyright() . '</div>';
                $this->framework->exitAfterHook();
                return;
            }

            // url to redirect to after login
            $redirect = $this->curPageURL();
            // strip the "CAS_auth" parameter from the URL
            $redirectStripped = $this->stripQueryParameter($redirect, 'CAS_auth');
            // Redirect to the page we were on
            $this->redirectAfterHook($redirectStripped);
            return;
        } catch ( \CAS_GracefullTerminationException $e ) {
            if ( $e->getCode() !== 0 ) {
                $this->framework->log('CAS Authenticator: Error getting code', [ 'error' => $e->getMessage() ]);
                session_unset();
                session_destroy();
                $this->exitAfterHook();
                return;
            }
        } catch ( \Throwable $e ) {
            $this->framework->log('CAS Authenticator: Error', [ 'error' => $e->getMessage() ]);
            session_unset();
            session_destroy();
            $this->exitAfterHook();
            return;
        }
    }

    public function redcap_every_page_top($project_id)
    {

        $page = defined('PAGE') ? PAGE : null;
        if ( empty ($page) ) {
            return;
        }

        // If we're on the login page, inject the CAS login button
        if (\ExternalModules\ExternalModules::getUsername() === null && !\ExternalModules\ExternalModules::isNoAuth() ) {
            $this->injectLoginPage($this->curPageURL());
        }

        // If we are on the Browse Users page, add CAS-User information if applicable 
        if ( $page === 'ControlCenter/view_users.php' ) {
            $this->addCasInfoToBrowseUsersTable();
        }

        // If we're on the EM Manager page, add a little CSS to make the
        // setting descriptives wider in the project settings
        if ( $page === 'manager/project.php' ) {
            echo "<style>label:has(.cas-descriptive){width:100%;}</style>";
            return;
        }
    }

    private function getLoginButtonSettings()
    {
        return [
            'casLoginButtonBackgroundColor'        => $this->framework->getSystemSetting('cas-login-button-background-color') ?? 'transparent',//'#00356b',
            'casLoginButtonBackgroundColorHover'   => $this->framework->getSystemSetting('cas-login-button-background-color-hover') ?? 'transparent',//'#286dc0',
            'casLoginButtonText'                   => $this->framework->getSystemSetting('cas-login-button-text') ?? 'Yale University',
            'casLoginButtonLogo'                   => $this->framework->getSystemSetting('cas-login-button-logo') ?? $this->framework->getUrl('assets/images/YU.png', true, true),//'<i class="fas fa-sign-in-alt"></i>',
            'localLoginButtonBackgroundColor'      => $this->framework->getSystemSetting('local-login-button-background-color') ?? 'transparent',//'#00a9e0',
            'localLoginButtonBackgroundColorHover' => $this->framework->getSystemSetting('local-login-button-background-color-hover') ?? 'transparent',//'#32bae6',
            'localLoginButtonText'                 => $this->framework->getSystemSetting('local-login-button-text') ?? 'Yale New Haven Health',
            'localLoginButtonLogo'                 => $this->framework->getSystemSetting('local-login-button-logo') ?? $this->framework->getUrl('assets/images/YNHH.png', true, true),//'<i class="fas fa-sign-in-alt"></i>',
        ];
    }

    private function injectLoginPage(string $redirect)
    {
        $loginButtonSettings = $this->getLoginButtonSettings();
        
        ?>
        <style>
            .btn-cas {
                background-color:
                    <?= $loginButtonSettings['casLoginButtonBackgroundColor'] ?>
                ;
                background-image: url('<?= $loginButtonSettings['casLoginButtonLogo'] ?>');
                width: auto;
            }

            .btn-cas:hover,
            .btn-cas:focus,
            .btn-cas:active,
            .btn-cas.btn-active,
            .btn-cas:active:focus,
            .btn-cas:active:hover {
                color: #fff !important;
                background-color:
                    <?= $loginButtonSettings['casLoginButtonBackgroundColorHover'] ?>
                    !important;
                border: 1px solid transparent;
            }

            .btn-login-original {
                background-color:
                    <?= $loginButtonSettings['localLoginButtonBackgroundColor'] ?>
                ;
                background-image: url('<?= $loginButtonSettings['localLoginButtonLogo'] ?>');
                width: auto;
            }

            .btn-login-original:hover,
            .btn-login-original:focus,
            .btn-login-original:active,
            .btn-login-original.btn-active,
            .btn-login-original:active:focus,
            .btn-login-original:active:hover {
                color: #fff !important;
                background-color:
                    <?= $loginButtonSettings['localLoginButtonBackgroundColorHover'] ?>
                    !important;
                border: 1px solid transparent !important;
            }

            .btn-login:hover,
            .btn-login:hover:active,
            .btn-login.btn-active:hover,
            .btn-login:focus {
                outline: 1px solid #4ca2ff !important;
            }

            .btn-login {
                background-size: contain;
                background-repeat: no-repeat;
                background-position: center;
                max-width: 350px;
                min-width: 250px;
                height: 50px;
                color: #fff;
                border: 1px solid transparent;
            }
        </style>
        <script>
            $(document).ready(function () {
                if ($('#rc-login-form').length === 0) {
                    return;
                }
                $('#rc-login-form').hide();

                //const loginButton = `<button class="btn btn-sm btn-cas fs15 my-2" onclick="showProgress(1);window.location.href='<?= $this->addQueryParameter($redirect, 'CAS_auth', '1') ?>';"><i class="fas fa-sign-in-alt"></i> <span><?= $loginButtonSettings['casLoginButtonText'] ?></span></button>`;
                const loginButton = `<button class="btn btn-sm btn-cas btn-login fs15 my-2" onclick="showProgress(1);window.location.href='<?= $this->addQueryParameter($redirect, 'CAS_auth', '1') ?>';"></button>`;
                const orText = '<span class="text-secondary mx-3 my-2 nowrap">-- <?= \RCView::tt('global_46') ?> --</span>';
                const loginChoiceSpan = $('span[data-rc-lang="global_257"]');
                if (loginChoiceSpan.length > 0) {
                    const firstButton = loginChoiceSpan.closest('div').find('button').eq(0);
                    firstButton.before(loginButton);
                    firstButton.before(orText);
                } else {
                    const loginDiv = `<div class="my-4 fs14">
                                <div class="mb-4"><?= \RCView::tt('global_253') ?></div>
                                <div>
                                    <span class="text-secondary my-2 me-3"><?= \RCView::tt('global_257') ?></span>
                                    ${loginButton}
                                    ${orText}
                                    <button class="btn btn-sm btn-rcgreen fs15 my-2" onclick="$('#rc-login-form').toggle();"><i class="fas fa-sign-in-alt"></i> <?= \RCView::tt('global_258') ?></button>
                                </div>
                            </div>`;
                    $('#rc-login-form').before(loginDiv);
                }
                // $('.btn-rcgreen span').text('<?= $loginButtonSettings['localLoginButtonText'] ?>');
                $('.btn-rcgreen').html(null).addClass('btn-login btn-login-original');
                $('.btn-login-original')[0].onclick = function () { $('#rc-login-form').toggle(); $(this).blur(); };
            });
        </script>
        <?php
    }

    private function curPageURL()
    {
        $pageURL = 'http';
        if ( isset ($_SERVER["HTTPS"]) )
            if ( $_SERVER["HTTPS"] == "on" ) {
                $pageURL .= "s";
            }
        $pageURL .= "://";
        if ( $_SERVER["SERVER_PORT"] != "80" ) {
            $pageURL .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
        } else {
            $pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
        }
        return $pageURL;
    }

    private function stripQueryParameter($url, $param)
    {
        $parsed  = parse_url($url);
        $baseUrl = strtok($url, '?');
        if ( isset ($parsed['query']) ) {
            parse_str($parsed['query'], $params);
            unset($params[$param]);
            $parsed = http_build_query($params);
        }
        return $baseUrl . (empty ($parsed) ? '' : '?') . $parsed;
    }

    private function addQueryParameter(string $url, string $param, string $value = '')
    {
        $parsed  = parse_url($url);
        $baseUrl = strtok($url, '?');
        if ( isset ($parsed['query']) ) {
            parse_str($parsed['query'], $params);
            $params[$param] = $value;
            $parsed         = http_build_query($params);
        } else {
            $parsed = http_build_query([ $param => $value ]);
        }
        return $baseUrl . (empty ($parsed) ? '' : '?') . $parsed;
    }

    private function convertTableUserToCasUser(string $userid)
    {
        if ( empty ($userid) ) {
            return;
        }
        try {
            $SQL   = 'DELETE FROM redcap_auth WHERE username = ?';
            $query = $this->framework->query($SQL, [ $userid ]);
            $this->setCasUser($userid);
            return;
        } catch ( \Exception $e ) {
            $this->framework->log('CAS Authenticator: Error converting table user to CAS user', [ 'error' => $e->getMessage() ]);
            return;
        }
    }

    private function convertCasUsertoTableUser(string $userid)
    {
        if ( empty ($userid) ) {
            return;
        }
        try {
            $SQL   = "INSERT INTO redcap_auth (username) VALUES (?)";
            $query = $this->framework->query($SQL, [ $userid ]);
            \Authentication::resetPasswordSendEmail($userid);
            $this->setCasUser($userid, false);
            return;
        } catch ( \Exception $e ) {
            $this->framework->log('CAS Authenticator: Error converting CAS user to table user', [ 'error' => $e->getMessage() ]);
            return;
        }
    }

    /**
     * @param string $userid
     * @return bool
     */
    private function inUserAllowlist(string $userid)
    {
        $SQL = "SELECT 1 FROM redcap_user_allowlist WHERE username = ?";
        $q   = $this->framework->query($SQL, [ $userid ]);
        return $q->fetch_assoc() !== null;
    }

    private function handleLogout()
    {
        if ( isset ($_GET['logout']) && $_GET['logout'] ) {
            \phpCAS::logoutWithUrl(APP_PATH_WEBROOT_FULL);
        }
    }

    public function initializeCas()
    {
        require_once __DIR__ . '/vendor/apereo/phpcas/CAS.php';
        if ( \phpCAS::isInitialized() ) {
            return true;
        }
        try {

            $cas_host                = $this->getSystemSetting("cas-host");
            $cas_context             = $this->getSystemSetting("cas-context");
            $cas_port                = (int) $this->getSystemSetting("cas-port");
            $cas_server_ca_cert_id   = $this->getSystemSetting("cas-server-ca-cert-pem");
            $cas_server_ca_cert_path = empty ($cas_server_ca_cert_id) ? $this->getSafePath('cacert.pem') : $this->getFile($cas_server_ca_cert_id);
            $server_force_https      = $this->getSystemSetting("server-force-https");
            $service_base_url        = (SSL ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];//APP_PATH_WEBROOT_FULL;

            // Enable https fix
            if ( $server_force_https == 1 ) {
                $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
                $_SERVER['HTTP_X_FORWARDED_PORT']  = 443;
                $_SERVER['HTTPS']                  = 'on';
                $_SERVER['SERVER_PORT']            = 443;
            }

            // Initialize phpCAS
            \phpCAS::client(CAS_VERSION_2_0, $cas_host, $cas_port, $cas_context, $service_base_url, false);

            // Set the CA certificate that is the issuer of the cert
            // on the CAS server
            \phpCAS::setCasServerCACert($cas_server_ca_cert_path);

            // Don't exit, let me handle instead
            \CAS_GracefullTerminationException::throwInsteadOfExiting();
            return true;
        } catch ( \Throwable $e ) {
            $this->log('CAS Authenticator: Error initializing CAS', [ 'error' => $e->getMessage() ]);
            return false;
        }
    }

    /**
     * Initiate CAS authentication
     * 
     * 
     * @return string|boolean username of authenticated user (false if not authenticated)
     */
    public function authenticate()
    {
        try {

            $initialized = $this->initializeCas();
            if ( $initialized === false ) {
                $this->framework->log('CAS Authenticator: Error initializing CAS');
                throw new \Exception('Error initializing CAS');
            }

            // force CAS authentication
            \phpCAS::forceAuthentication();

            // Return authenticated username
            return \phpCAS::getUser();
        } catch ( \CAS_GracefullTerminationException $e ) {
            if ( $e->getCode() !== 0 ) {
                $this->framework->log('CAS Authenticator: Error getting code', [ 'error' => $e->getMessage() ]);
            }
            return false;
        } catch ( \Throwable $e ) {
            $this->framework->log('CAS Authenticator: Error authenticating', [ 'error' => json_encode($e, JSON_PRETTY_PRINT) ]);
            return false;
        }
    }

    public function renewAuthentication()
    {
        try {
            $initialized = $this->initializeCas();
            if ( !$initialized ) {
                $this->framework->log('CAS Login E-Signature: Error initializing CAS');
                throw new \Exception('Error initializing CAS');
            }

            $cas_url = \phpCAS::getServerLoginURL() . '%26cas_authed%3Dtrue&renew=true';
            \phpCAS::setServerLoginURL($cas_url);
            \phpCAS::forceAuthentication();
        } catch ( \CAS_GracefullTerminationException $e ) {
            if ( $e->getCode() !== 0 ) {
                $this->framework->log('CAS Login E-Signature: Error getting code', [ 'error' => $e->getMessage() ]);
            }
            return false;
        } catch ( \Throwable $e ) {
            $this->framework->log('CAS Login E-Signature: Error authenticating', [ 'error' => json_encode($e, JSON_PRETTY_PRINT) ]);
            return false;
        }
    }


    /**
     * Get url to file with provided edoc ID.
     * 
     * @param string $edocId ID of the file to find
     * 
     * @return string path to file in edoc folder
     */
    private function getFile(string $edocId)
    {
        $filePath = "";
        if ( $edocId === null ) {
            return $filePath;
        }
        $result   = $this->query('SELECT stored_name FROM redcap_edocs_metadata WHERE doc_id = ?', $edocId);
        $filename = $result->fetch_assoc()["stored_name"];
        if ( defined('EDOC_PATH') ) {
            $filePath = $this->framework->getSafePath(EDOC_PATH . $filename, EDOC_PATH);
        }
        return $filePath;
    }


    private function casLog($message, $params = [], $record = null, $event = null)
    {
        $doProjectLogging = $this->getProjectSetting('logging');
        if ( $doProjectLogging ) {
            $changes = "";
            foreach ( $params as $label => $value ) {
                $changes .= $label . ": " . $value . "\n";
            }
            \REDCap::logEvent(
                $message,
                $changes,
                null,
                $record,
                $event
            );
        }
        $this->framework->log($message, $params);
    }

    private function jwt_request(string $url, string $token)
    {
        $result = null;
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $authorization = "Authorization: Basic " . $token;
            $authheader    = array( 'Content-Type: application/json', $authorization );
            curl_setopt($ch, CURLOPT_HTTPHEADER, $authheader);
            $result = curl_exec($ch);
            curl_close($ch);
            $response = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $result);
            $xml      = new \SimpleXMLElement($response);
            $result   = json_decode(json_encode((array) $xml), TRUE);
        } catch ( \Throwable $e ) {
            $this->framework->log('CAS Authenticator: Error', [ 'error' => $e->getMessage() ]);
        } finally {
            return $result;
        }
    }

    private function fetchUserDetails(string $userid)
    {
        $url   = $this->getSystemSetting('cas-user-details-url');
        $token = $this->getSystemSetting('cas-user-details-token');
        if ( empty ($url) || empty ($token) ) {
            return null;
        }
        $url      = str_replace('{userid}', $userid, $url);
        $response = $this->jwt_request($url, $token);
        return $this->parseUserDetailsResponse($response);
    }

    private function parseUserDetailsResponse($response)
    {
        if ( empty ($response) ) {
            return null;
        }
        $userDetails = [];
        try {
            $userDetails['user_firstname'] = $response['Person']['Names']['ReportingNm']['First'];
            $userDetails['user_lastname']  = $response['Person']['Names']['ReportingNm']['Last'];
            $userDetails['user_email']     = $response['Person']['Contacts']['Email'];
        } catch ( \Throwable $e ) {
            $this->framework->log('CAS Authenticator: Error parsing user details response', [ 'error' => $e->getMessage() ]);
        } finally {
            return $userDetails;
        }
    }

    private function setUserDetails($userid, $details)
    {
        if ( $this->userExists($userid) ) {
            $this->updateUserDetails($userid, $details);
        } else {
            $this->insertUserDetails($userid, $details);
        }
        $SQL = 'INSERT INTO redcap_user_information (username, user_firstname, user_lastname, email) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = ?, email = ?';
    }

    private function userExists($userid)
    {
        $SQL = 'SELECT 1 FROM redcap_user_information WHERE username = ?';
        $q   = $this->framework->query($SQL, [ $userid ]);
        return $q->fetch_assoc() !== null;
    }

    private function updateUserDetails($userid, $details)
    {
        try {
            $SQL    = 'UPDATE redcap_user_information SET user_firstname = ?, user_lastname = ?, user_email = ? WHERE username = ?';
            $PARAMS = [ $details['user_firstname'], $details['user_lastname'], $details['user_email'], $userid ];
            $query  = $this->createQuery();
            $query->add($SQL, $PARAMS);
            $query->execute();
            return $query->affected_rows;
        } catch ( \Exception $e ) {
            $this->framework->log('CAS Authenticator: Error updating user details', [ 'error' => $e->getMessage() ]);
        }
    }

    private function insertUserDetails($userid, $details)
    {
        try {
            $SQL    = 'INSERT INTO redcap_user_information (username, user_firstname, user_lastname, user_email) VALUES (?, ?, ?, ?)';
            $PARAMS = [ $userid, $details['user_firstname'], $details['user_lastname'], $details['user_email'] ];
            $query  = $this->createQuery();
            $query->add($SQL, $PARAMS);
            $query->execute();
            return $query->affected_rows;
        } catch ( \Exception $e ) {
            $this->framework->log('CAS Authenticator: Error inserting user details', [ 'error' => $e->getMessage() ]);
        }
    }

    public function createCode()
    {
        return uniqid('cas_', true);
    }

    public function setCode($username, $code)
    {
        $this->framework->setSystemSetting('cas-code-' . $username, $code);
    }
    public function getCode($username)
    {
        return $this->framework->getSystemSetting('cas-code-' . $username);
    }

    public function isCasUser($username)
    {
        return !\Authentication::isTableUser($username) && $this->framework->getSystemSetting('cas-user-' . $username) === true;
    }

    public function getUserType($username)
    {
        if ( $this->isCasUser($username) ) {
            return 'CAS';
        }
        if ( $this->inUserAllowlist($username) ) {
            return 'allowlist';
        }
        if ( \Authentication::isTableUser($username) ) {
            return 'table';
        }
        return 'unknown';
    }

    public function setCasUser($userid, bool $value = true)
    {
        $this->framework->setSystemSetting('cas-user-' . $userid, $value);
    }

    public function eraseCasSession()
    {
        $this->initializeCas();
        unset($_SESSION[\phpCAS::getCasClient()::PHPCAS_SESSION_PREFIX]);
        return;
    }

    private function addCasInfoToBrowseUsersTable()
    {

        // echo '<pre><br><br><br><br>';
        // var_dump($_SESSION);
        // echo '</pre>';
        // if (isset($_GET['authed'])) {
        //     return;
        // }

        // $protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";  
        // $curPageURL = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        // $newUrl = $this->addQueryParameter($curPageURL, 'authed', 'true');
        // $authenticator = new YNHH_SAML_Authenticator('http://localhost:33810',  $this->framework->getUrl('YNHH_SAML_ACS.php', true));

        // // Perform login
        
        // // $this->log('test', ['isAuthenticated' => $authenticator->]);
        // $authenticator->login($newUrl, [], false, true);
        // $this->lost('test2');
        // // Check authentication status
        // if ($authenticator->isAuthenticated()) {
        //     // User is authenticated, proceed with further actions
        //     $attributes = $authenticator->getAttributes();
        //     $this->log('authed', [ 'attributes' => json_encode($attributes, JSON_PRETTY_PRINT) ]);
        //     var_dump($attributes);
        //     // Process user attributes as needed
        // } else {
        //     // Authentication failed
        //     $this->log('no authed');
        //     echo "Authentication failed. Reason: " . $authenticator->getLastError();
        // }

        // // Perform logout if needed
        // $authenticator->logout();


        $this->framework->initializeJavascriptModuleObject();
        
        parse_str($_SERVER['QUERY_STRING'], $query);
        if ( isset ($query['username']) ) {
            $userid = $query['username'];
            $userType = $this->getUserType($userid);
        }

        ?>
        <script>
            var cas_authenticator = <?= $this->getJavascriptModuleObjectName() ?>;
            function convertTableUserToCasUser() {
                const username = $('#user_search').val();
                Swal.fire({
                    title: "Are you sure you want to convert this table-based user to a CAS user?",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonText: "Convert to CAS User"
                }).then((result) => {
                    if (result.isConfirmed) {
                        cas_authenticator.ajax('convertTableUserToCasUser', { username: username }).then(() => {
                            location.reload();
                        });
                    }
                });
            }
            function convertCasUsertoTableUser() {
                const username = $('#user_search').val();
                Swal.fire({
                    title: "Are you sure you want to convert this CAS user to a table-based user?",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonText: "Convert to Table User"
                }).then((result) => {
                    if (result.isConfirmed) {
                        cas_authenticator.ajax('convertCasUsertoTableUser', { username: username }).then(() => {
                            location.reload();
                        });
                    }
                });
            }

            function addTableRow(userType) {
                console.log(userType);
                let casUserText = '';
                switch (userType) {
                    case 'CAS':
                        casUserText = `<strong>${userType}</strong> <input type="button" style="font-size:11px" onclick="convertCasUsertoTableUser()" value="Convert to Table User">`;
                        break;
                    case 'allowlist':
                        casUserText = `<strong>${userType}</strong>`;
                        break;
                    case 'table':
                        casUserText = `<strong>${userType}</strong> <input type="button" style="font-size:11px" onclick="convertTableUserToCasUser()" value="Convert to CAS User">`;
                        break;
                    default:
                        casUserText = `<strong>${userType}</strong>`;
                        break;
                }
                console.log($('#indv_user_info'));
                $('#indv_user_info').append('<tr id="userTypeRow"><td class="data2">User type</td><td class="data2">' + casUserText + '</td></tr>');
            }

            view_user = function (username) {
                if (username.length < 1) return;
                $('#view_user_progress').css({'visibility':'visible'});
                $('#user_search_btn').prop('disabled',true);
                $('#user_search').prop('disabled',true);
                $.get(app_path_webroot+'ControlCenter/user_controls_ajax.php', { user_view: 'view_user', view: 'user_controls', username: username },
                    function(data) {
                        cas_authenticator.ajax('getUserType', { username: username }).then((userType) => {
                            $('#view_user_div').html(data);
                            addTableRow(userType);
                            enableUserSearch();
                            highlightTable('indv_user_info',1000);
                        });
                    }
                );
            }

            <?php if ( isset ($userid) ) { ?>
                window.requestAnimationFrame(()=>{addTableRow('<?= $userType ?>')});
            <?php } ?>

            $(document).ready(function () {
                <?php if ( isset ($userid) ) { ?>
                    if (!$('#userTypeRow').length) {
                        view_user('<?= $userid ?>');
                    }
                <?php } ?>
            });
        </script>
        <?php
    }


    /**
     * Just until my minimum RC version is >= 13.10.1
     * @param mixed $url
     * @param mixed $forceJS
     * @return void
     */
    public function redirectAfterHook($url, $forceJS = false)
    {
        // If contents already output, use javascript to redirect instead
        if ( headers_sent() || $forceJS ) {
            $url = \ExternalModules\ExternalModules::escape($url);
            echo "<script type=\"text/javascript\">window.location.href=\"$url\";</script>";
        }
        // Redirect using PHP
        else {
            header("Location: $url");
        }

        \ExternalModules\ExternalModules::exitAfterHook();
    }
}
<?php

class Authentication extends DBTable {

    protected $basePath = '';
    private static $user = false;
    
    const TABLE_TOKENS = 'contact_api_tokens';
    const TABLE_FAILED_LOGINS = 'contact_failed_logins';
    const TIMEOUT = AUTHENTICATION_TIMEOUT;
    const MAX_ACTIVE_PER_USER = AUTHENTICATION_MAX_ACTIVE_SESSIONS;

    public function __construct($app) {
        parent::__construct();
        $this->app = $app;
    }

    public function index() {
        //login endpoint
        $self = $this;
        $this->app->post("/login", array ($this, 'login'));

        if ($this->app->config('debug'))
            {
            $this->app->get("/login", array ($this, 'login'));
            }

        $this->app->get("/logout", array ($this, 'logout'));
        $this->app->post("/logout", array ($this, 'logout'));

        $this->app->post("/passwd", array($this, 'passwd'));
    }

    /**
     * Login method
     * 
     * Will verify the username and password
     */
    public function login() {
        //Get the body and decode it
        if ($this->app->request->isGet())
            {
            $user = $this->app->request->get('email');
            $password = $this->app->request->get('password');
            }
        else if ('application/json' == $this->app->request->headers->get('Content-Type'))
            {
            $body = json_decode($this->app->request->getBody(), true);
            $user = $body['email'];
            $password = $body['password'];
            }
        else
            {
            $user = $this->app->request->params('email');
            $password = $this->app->request->post('password');
            }

        if (empty ($user))
            return $this->app->render(400, array ('error' => true, 'msg'=> "Missing user name"));
        if (empty ($password))
            return $this->app->render(400, array ('error' => true, 'msg'=> "Missing password"));

        //Validate the username and password, if valid returns token, else will return false
        $token = $this->validateUser($user, $password, $userName, $fullName, $error);

        if ($token) {
            //Get the user object
            $this->data = array("token" => $token,
                'user' => $userName,
                'fullname' => $fullName
            );
            //Set the response
            $this->app->render(200, $this->data);
        } else {
            $this->app->render(409, array ('error' => true, 'msg'=> "Username or password incorrect".($error ? " ($error)" : "")));
        }
    }

    /**
     * Change password method
     */
    public function passwd() {
        $currentUser = Authentication::getCurrentUserId();
        if (false == $currentUser)
            {
            $this->app->render (401, array('error' => true, 'status' => 401, 'msg' => "No access"));
            return;
            }       
      
        //Get the body and decode it
        $body = json_decode($this->app->request->getBody(), true);
        if (empty ($body) || empty ($body['old']) || empty ($body['new']))
            {
            $this->app->render (400, array('error' => true, 'status' => 400, 'msg' => "Invalid parameters"));
            return;
            }       

        if (preg_match ('#'.PASSWORD_VALIDATION_REGEX.'#', $body['new']) <= 0)
            {
            $this->app->render (400, array('error' => true, 'status' => 400, 'msg' => PASSWORD_VALIDATION_DESCRIPTION));
            return;
            }

        //Validate the username and password, if valid returns token, else will return false
        if (!$this->changePassword($body['old'], $body['new'], $error))
            $this->app->render(409, array ('error' => true, 'msg'=> "Unable to change the password".($error ? " ($error)" : "")));
        else
            {
            //Get the user object
            $this->data = array();
            //Set the response
            $this->app->render(200, $this->data);
            }
        }

    public function logout()
        {
        $token = self::getAuthorizationToken ($this->app);

        if (empty ($token))
            return $this->app->render(400, array ('error' => true, 'msg'=> "Invalid access token"));

        //Validate the username and password, if valid returns token, else will return false
        if (!$this->validateToken ($token))
            $this->app->render(200, array ('error' => true, 'msg'=> "Not logged in"));
        else
            {
            $tableName = self::TABLE_TOKENS;
            $token = $this->db->escapeString ($token);
            $sql = "DELETE FROM `$tableName` WHERE `token`='$token'";
            if (false === $this->db->executeSQLNoCheck ($sql, true))
                {
                $error = $this->db->getLastError ();
                return $this->app->render(500, array
                    (
                    'error' => true,
                    'msg'=> "Failed to log out".($this->app->config('debug') ? " - $error" : "")
                    ));
                }

            $this->app->render(200, array ('error' => true, 'msg'=> "Success"));
            }
        }

    public static function getAuthorizationToken ($app)
        {
        if ($app->request->isPost() && 'application/json' == $app->request->headers->get('Content-Type'))
            {
            $body = json_decode($app->request->getBody(), true);
            $token = empty ($body['token']) ? false : $body['token'];
            }
        else
            {
            $token = $app->request->params('token');
            }

        $tokenH = $app->request->headers->get('Authorization');
        if (empty ($token))
            $token = $tokenH;
        else if ($tokenH != $token)
            {
            // mixing GET/POST variables and Authorization header
            return false;
            }
        
        return $token;
        }

    public function validateToken($token)
        {
        $tableName = self::TABLE_TOKENS;
        $tokenTimeout = self::TIMEOUT;
        $token = $this->db->escapeString ($token);
        $sql = <<<EOT
SELECT `token_id`, t.`contact_id`, c.`email`
  FROM `$tableName` t
  INNER JOIN `contact` c ON t.`contact_id`=c.`contact_id`
  WHERE `token`='$token' AND TIMESTAMPDIFF(MINUTE,`last_access`,UTC_TIMESTAMP())<=$tokenTimeout
EOT;
        $rows = $this->db->executeSelect ($tableName, $sql, true);
        if (empty ($rows))
            return false;

        // touch the row, reseting timestamp used for timeout calculation 
        $this->db->executeUpdate ($tableName, "SET `last_access`=UTC_TIMESTAMP() WHERE `token`='$token'", true);

        self::setUserContext (self::createUserObject($rows[0]['contact_id'], $rows[0]['email']));
        return true;
        }

    public function validateUser($email, $password, &$userName, &$fullName, &$error)
        {
        $tableName = 'contact';

        $email = $this->db->escapeString ($email);
        $sql = "SELECT `contact_id`, `password`, `first_name`, `last_name` , `email` FROM `$tableName` WHERE email = '$email' AND `portal_access`=1";

        $rows = $this->db->executeSelect ($tableName, $sql, true);

        if (empty ($rows))
            {
            $error = "Invalid user and password combination";
            return false;
            }

        $contactId = $rows[0]['contact_id'];
        // make sure that account is not blocked (if it is - no check is made)
        $tableFailures = self::TABLE_FAILED_LOGINS;
        $sql = "SELECT COUNT(*) `cnt`, MAX(`time`) `last` FROM `$tableFailures` WHERE `contact_id` = $contactId";
        $rowsFailed = $this->db->executeSelect ($tableFailures, $sql, true);
        // no check for false - we do not care if table already exists
        if (!empty ($rowsFailed))
            {
            $failedAttempts = $rowsFailed[0]['cnt'];
            if ($failedAttempts == AUTHENTICATION_MAX_ATTEMPTS) // first failure after hitting the limit, log this action
                {
                $sql = "(`entry_datetime`,`username`,`affected_table`,`affected_row`,`action`,`logData`) VALUES ('".date('Y-m-d H:i:s',strtotime('now'))."','$email','contact',$contactId,'login','Client account locked due to too many failed attempts (more than $failedAttempts failed attempts)')";
                if (false === $this->db->executeInsert ('audit_log', $sql, true, true))
                    {
                    $error = "Error in the dabatabse";
                    if ($this->app->config('debug'))
                        $error = " - ".$this->db->getLastError();
                    return false;
                    }
                }
            if ($failedAttempts >= AUTHENTICATION_MAX_ATTEMPTS)
                {
                $blockTime = AUTHENTICATION_BLOCK_MINUTES;
                for ($i = AUTHENTICATION_MAX_ATTEMPTS; $i < $failedAttempts; $i++)
                    $blockTime *= max (1, AUTHENTICATION_BLOCK_MULTIPLIER);
                $diff = time() - strtotime ($rowsFailed[0]['last']);
                $diff /= 60; // convert seconds to minutes

                if ($diff < $blockTime)
                    {
                    $error = "Account is blocked";
                    if ($this->app->config('debug'))
                        $error .= " after $failedAttempts failed attempts. ".round($blockTime - $diff,1)." minutes remaining";
                    return false;
                    }
                }
            }

        $hash = $rows[0]['password'];
        if (!password_verify ($password, $hash))
            {
            $error = "Invalid user and password combination";
            $this->logFailedAttempt($email, $contactId, $error);
            if ($this->app->config('debug'))
                $error = "Invalid password for email $email";
            return false;
            }
        else if (!empty ($rowsFailed))
            {
            // account is not blocked and now login has succeeded, so purge all the failed attempts
            $sql = "DELETE FROM `$tableFailures` WHERE `contact_id` = $contactId";
            $affected = $this->db->executeSQLNoCheck ($sql);
            if ($this->app->config('debug') && false === $affected)
                {
                $error = $this->db->getLastError();
                return false;
                }
            }

        $userId = $contactId;
        $userName = $rows[0]['email'];
        $fullName = trim ($rows[0]['first_name'].' '.$rows[0]['last_name']);

        $token = $this->generateAccessToken ($userId, $userName, $error, $fullName);
        if (!$token)
            {
            $error = "User cannot login at this time ($error)";
            return false;
            }

        self::setUserContext (self::createUserObject($userId, $userName));
        $error = NULL;
        return $token;
        }

    public function changePassword($oldPassword, $newPassword, &$error)
        {
        $tableName = 'contact';
        $userId = self::$user->id;

        $sql = "SELECT `contact_id`, `password` FROM `$tableName` WHERE `contact_id`=$userId AND `portal_access`=1";

        $rows = $this->db->executeSelect ($tableName, $sql, true);

        if (empty ($rows))
            {
            $error = "Invalid user";
            return false;
            }

        $hash = $rows[0]['password'];
        if (!password_verify ($oldPassword, $hash))
            {
            $error = "Invalid password";
            return false;
            }

        $newHash = password_hash ($newPassword, PASSWORD_DEFAULT);
        if (false === $this->db->executeUpdate ($tableName, "SET `password`='$newHash' WHERE `contact_id`=$userId AND `password`='$hash'", true))
            {
            $error = "Critical failure";
            return false;
            }

        return true;
        }

    private function sendMultipleSessionNotice ($email, $fullName, $numberOfSessions, &$error = NULL)
        {
        $error = NULL;
        $mail = new PHPMailer(true);
        $mail->IsSendmail();
        $mail->isHTML (true);
        $mail->WordWrap = 50;
        $from = AUTHENTICATION_NOTIFY_MAIL_FROM;
        if (empty ($from))
            {
            error_log ("AUTHENTICATION_NOTIFY_MAIL_FROM is not cofigured. No mail will be sent notifying about multiple sessions");
            return;
            }

        try
            {
            $mail->SetFrom ($from, AUTHENTICATION_NOTIFY_MAIL_FROM_NAME);
            $mail->AddAddress ($email, $fullName);

            $mail->Subject = AUTHENTICATION_NOTIFY_MAIL_SUBJECT;

            $body = <<<EOT
<html>
<head>
 <meta content="text/html;charset=iso-8859-1" http-equiv="Content-Type">
 <title>{$mail->Subject}</title>
</head>
<body>
    <h1>Multiple sessions active</h1>
    <p>A new Eleven API session was just created using your login, but there is still another active session.
    
    If another session was not created by you, please notify Eleven administrators.</p>
</body>
</html>
EOT;

            $mail->Body = $body;
            $mail->Send();
            return true;
            }
        catch (phpmailerException $e)
            {
            $error = "Could not send an e-mail notification. ".$e->errorMessage();
            }
        catch (Exception $e)
            {
            $error = "Could not send an e-mail notification. ".$e->getMessage();
            }
        return false;
        }

    /******************************************************
    * Generates a new access token after loggin into the API
    *******************************************************/
    private function generateAccessToken ($userId, $userName, &$error, $fullName = NULL)
        {
        $tableName = self::TABLE_TOKENS;
        $tokenTimeout = self::TIMEOUT;
        $sql = "SELECT SUM(CASE WHEN TIMESTAMPDIFF(MINUTE,`last_access`,UTC_TIMESTAMP())<$tokenTimeout THEN 1 ELSE 0 END) `active`, COUNT(*) `all` FROM `$tableName` WHERE `contact_id`=$userId";
        $rows = $this->db->executeSelect ($tableName, $sql, true);
        if (false === $rows && 1146 == $this->db->getLastErrorId())
            {
            if ($this->ensureTokensTable ())
                $rows = $this->db->executeSelect ($tableName, $sql, true);
            }

        if (false === $rows)
            {
            $error = "Error in the dabatabse";
            if ($this->app->config('debug'))
                $error .= " - ".$this->db->getLastError();
            return false;
            }

        if (count ($rows) > 0)
            {
            $totalSessions = $rows[0]['all'];
            $activeSessions = $rows[0]['active'];

            // if $totalSessions > X, remove old sessions to free up some space
            if ($totalSessions - $activeSessions > self::MAX_ACTIVE_PER_USER)
                {
                $sql = "DELETE FROM `$tableName` WHERE `contact_id`=$userId AND TIMESTAMPDIFF(MINUTE,`last_access`,UTC_TIMESTAMP())>$tokenTimeout";
                // fo not check the error
                $this->db->executeSQLNoCheck ($sql, true);
                }

            if ($activeSessions >= self::MAX_ACTIVE_PER_USER)
                {
                $error = "Maximum number of concurent active connections reached for this user. Please log out of other sessions beefore creating a new one.";
                return false;
                }

            if ($activeSessions == 1 && AUTHENTICATION_NOTIFY_MULTIPLE_SESSIONS)
                {
                // if second active session, send an email to the user (no more emails if 3rd session, etc)
                $this->sendMultipleSessionNotice ($userName, $fullName, $activeSessions, $err);
                if ($err)
                    error_log ($err);
                }
            }

        $token = $this->db->escapeString (\IcyApril\CryptoLib::randomString(32));
        $sql = <<<EOT
(`token`, `contact_id`, `last_access`)
VALUES
('$token', $userId, UTC_TIMESTAMP())
EOT;
        if (false === $this->db->executeInsert ($tableName, $sql, true, true))
            {
            $error = "Error in the dabatabse";
            if ($this->app->config('debug'))
                $error .= " - ".$this->db->getLastError();
            return false;
            }

        if ($this->logClientLogin($userName, $userId, $error) === FALSE)
            return false;

        return $token;
        }

    /******************************************************
    * Log a client logging into the api/system
    *******************************************************/
    private function logClientLogin ($userName, $userId, &$error)
        {
        $ip = $this->getClientIP();
        $sql = "(`entry_datetime`,`username`,`affected_table`,`affected_row`,`action`,`logData`) VALUES ('".date('Y-m-d H:i:s',strtotime('now'))."','$userName','contact',$userId,'login','Client logged into system ($ip)')";
        if (false === $this->db->executeInsert ('audit_log', $sql, true, true))
            {
            $error = "Error in the dabatabse";
            if ($this->app->config('debug'))
                $error = " - ".$this->db->getLastError();
            return false;
            }
        }

    private function ensureFailedLoginTable (&$error)
        {
        $tableFailures = self::TABLE_FAILED_LOGINS;
        $sql = <<<EOT
CREATE TABLE IF NOT EXISTS `$tableFailures` (
  `row_id` INT PRIMARY KEY AUTO_INCREMENT,
  `contact_id` int(11) NOT NULL,
  `time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `ip` varchar(255),
  KEY (`contact_id`, `time`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8
EOT;
        $tableExists = $this->db->executeSelect ("MYSQL", "SHOW TABLES LIKE '$tableFailures'", true);
        if (empty ($tableExists))
            {
            $ret = $this->db->executeSQLNoCheck ($sql);
            if (false === $ret)
                {
                $error = "Failed to create the '$tableFailures' table";
                if ($this->app->config('debug'))
                    $error = " - ".$this->db->getLastError();
                return false;
                }
            }

        return true;
        }

    /******************************************************
    * Log a a failed authentication attempt
    *******************************************************/
    private function logFailedAttempt ($userName, $userId, &$error)
        {
        $ip = $this->getClientIP();
        $sql = "(`entry_datetime`,`username`,`affected_table`,`affected_row`,`action`,`logData`) VALUES ('".date('Y-m-d H:i:s',strtotime('now'))."','$userName','contact',$userId,'login','Failed login attempt ($ip)')";
        if (false === $this->db->executeInsert ('audit_log', $sql, true, true))
            {
            $error = "Error in the dabatabse";
            if ($this->app->config('debug'))
                $error = " - ".$this->db->getLastError();
            return false;
            }

        $tableFailures = self::TABLE_FAILED_LOGINS;
        $sqlFail = "(`contact_id`,`ip`) VALUES ($userId,'$ip')";
        $affected = $this->db->executeInsert ($tableFailures, $sqlFail, true, true);
        if (false === $affected)
            {
            if (1146 == $this->db->getLastErrorId())
                {
                if ($this->ensureFailedLoginTable ($error))
                    $affected = $this->db->executeInsert ($tableFailures, $sqlFail, true, true);
                }
            }

        if (false === $affected)
            {
            $error = "Error in the dabatabse";
            if ($this->app->config('debug'))
                $error = " - ".$this->db->getLastError();
            return false;
            }
        }

    /******************************************************
    * Create the db table tracking access tokens (sessions)
    *******************************************************/
    private function ensureTokensTable ()
        {
        $sql = <<<EOT
CREATE TABLE `contact_api_tokens` (
`token_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
`token` VARCHAR( 128 ) NOT NULL ,
`contact_id` INT NOT NULL ,
`last_access` TIMESTAMP NOT NULL ,
UNIQUE (
`token`
),
INDEX (`contact_id`, `last_access`)
) ENGINE = MyISAM;
EOT;
        return $this->db->executeSQLNoCheck ($sql);
        }

    /******************************************************
    * Sets user context after logging into the database or authenticating with token
    *******************************************************/
    private static function setUserContext ($user)
        {
        self::$user = $user;
        }

    public static function getCurrentUserId ()
        {
        if (self::$user)
            return self::$user->id;
        return false;
        }

    public static function getCurrentUserName ()
        {
        if (self::$user)
            return self::$user->userName;
        return false;
        }


    private static function createUserObject ($userId, $userName)
        {
        $user = new StdClass ();
        $user->id = $userId;
        $user->userName = $userName;
        // TODO: extract permissions
        return $user;
        }
        
    /******************************************************
    * function to get the client ip address
    *******************************************************/
    public function getClientIP ()
        {
        $ipaddress = '';
        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if(isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        else
            $ipaddress = 'UNKNOWN IP';
        return $ipaddress;
        }
    }
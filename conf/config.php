<?php

$files = array_merge (glob(__DIR__."/../conf/*.php"), glob(__DIR__."/../css/*.css"), glob(__DIR__."/../js/*.js"));
$files = array_combine($files, array_map("filemtime", $files));
arsort($files);
$latest_file = array_shift ($files);

define ("VERSION", "0.0.1.{$latest_file}");

$userConfig = __DIR__.'/user.config.php';

if (!file_exists ($userConfig))
    {
    error_reporting (E_ALL);
    trigger_error ("$userConfig does not exists, using default values. Database connect will probably fail");
    $userConfig .= __DIR__.'/user.config.default';
    }

require_once ($userConfig);

function define_default ($const, $val)
    {
    if (!defined ($const))
        define ($const, $val);
    }

define_default ("VERBOSE", false);
define_default ("DEBUG", false);

define_default ("AUTHENTICATION_MAX_ATTEMPTS", 3);
define_default ("AUTHENTICATION_TIMEOUT", 10); // timeout (in minutes) after last action
define_default ("AUTHENTICATION_MAX_ACTIVE_SESSIONS", 3); // maximum active sessions for the single user
define_default ("AUTHENTICATION_BLOCK_MINUTES", 60);
define_default ("AUTHENTICATION_BLOCK_MULTIPLIER", 2); // if >1, after each successive failed attempt double block time
define_default ("AUTHENTICATION_NOTIFY_MULTIPLE_SESSIONS", false); // notify user if he already has another active session open

define_default ("TEMPLATES_DIR", __DIR__."/../templates/default/");


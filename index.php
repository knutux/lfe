<?php
require __DIR__.'/vendor/autoload.php';
require_once (__DIR__.'/conf/config.php');

$isDebug = defined ('DEBUG') && DEBUG;

$config = [
    'settings' => [
        'displayErrorDetails' => $isDebug,

        /*
        'logger' => [
            'name' => 'slim-app',
            'level' => Monolog\Logger::DEBUG,
            'path' => __DIR__ . '/../logs/app.log',
        ],
        */
    ],
];
$app = new \Slim\App($config);

$container = $app->getContainer();
if (is_dir (__DIR__."/../logs"))
    {
    $container['logger'] = function($c) {
        $logger = new \Monolog\Logger('lfe_logger');
        $file_handler = new \Monolog\Handler\StreamHandler(__DIR__."/../logs/app.log");
        // debug, info, notice, warning, error, critical, alert, emergency
        $logger->pushHandler($file_handler, $isDebug ? \Monolog\Logger::DEBUG : \Monolog\Logger::WARNING);
        return $logger;
    };
    }

require_once (__DIR__.'/api/apihandler.php');
$api = new \ApiHandler($app);
$api->index();

require_once (__DIR__.'/ui/uihandler.php');
$api = new \UIHandler($app);
$api->index();

//$app->notFound(function() {    var_dump ($_SERVER); });

$app->run();


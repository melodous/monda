<?php

use Tracy\Debugger,App\Model\Opts;

require __DIR__ . '/../vendor/autoload.php';

$configurator = new Nette\Configurator;
$configurator->setTempDirectory(getenv("MONDA_TMP"));
$configurator->enableDebugger(getenv("MONDA_LOGDIR"));

if (getenv("MONDA_WWW")) {
    $o=fopen(getenv("MONDA_LOGDIR")."/stdout.log","a");
    $e=fopen(getenv("MONDA_LOGDIR")."/stderr.log","a");
    define("STDERR",$e);
    define("STDOUT",$o);
}

define("APP_DIR",__DIR__);

$configurator->createRobotLoader()
	->addDirectory(__DIR__)
	->addDirectory(__DIR__ . '/../vendor/')
	->register()->getIndexedClasses();

$configurator->addConfig(__DIR__ . '/config/config.neon');

Debugger::$maxDepth = 15;
Debugger::$maxLen = 2000;
Debugger::$strictMode = false;

Opts::startup();
Opts::preReadOpts();

$container = $configurator->createContainer();

return $container;

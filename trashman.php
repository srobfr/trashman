<?php

use trashman\TrashmanApplication;
define('ROOT_DIR', __DIR__);

$autoloader = require __DIR__ . '/vendor/autoload.php';
$autoloader->set('trashman\\', array(ROOT_DIR));

$application = new TrashmanApplication();
$application->run();

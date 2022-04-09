<?php

use splitbrain\blogrng\Controller;

require_once(__DIR__ . '/../vendor/autoload.php');

// this is our stupid, simple router
$dirname = dirname($_SERVER['SCRIPT_NAME']);
$urlpath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$view = substr($urlpath, strlen($dirname));
$view = trim($view, '/');

// the controller knows what to do
$controller = new Controller();
$controller($view);


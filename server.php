<?php

include "vendor/autoload.php";

use Inbenta\SkypeConnector\SkypeConnector;

//Instance new SkypeConnector
$appPath=__DIR__.'/';
$app = new SkypeConnector($appPath);

//Handle the incoming request
$app->handleRequest();

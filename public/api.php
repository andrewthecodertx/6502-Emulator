<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Emulator\API\RestAPI;

// Create and handle API request
$api = new RestAPI();
$api->handleRequest();
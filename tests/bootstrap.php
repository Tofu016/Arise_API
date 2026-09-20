<?php
// Tests load framework-free modules directly — no CodeIgniter boot, no
// database, no HTTP. BASEPATH is only defined so the "no direct script
// access" guard at the top of each CI library file lets it load.
define('BASEPATH', __DIR__ . '/../system/');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/libraries/Photo_store.php';

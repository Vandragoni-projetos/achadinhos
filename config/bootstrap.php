<?php

/**
 * Bootstrap mínimo para scripts em cron/ (sem sessão admin).
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

if (!defined('CRON_TOKEN')) {
    define('CRON_TOKEN', (string) getConfig('cron_token', ''));
}

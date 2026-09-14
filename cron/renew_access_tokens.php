<?php
date_default_timezone_set('Asia/Manila');

$lockFile = fopen(sys_get_temp_dir() . '/uratex-token-renewal.lock', 'c');
if (!$lockFile || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Token renewal is already running.\n");
    exit(1);
}

require __DIR__ . '/../pages/renew_token.php';

flock($lockFile, LOCK_UN);
fclose($lockFile);
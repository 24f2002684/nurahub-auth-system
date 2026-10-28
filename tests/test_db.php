<?php
require_once __DIR__ . '/../php/database.php';

echo "Testing Database Connections...\n";
$res = Database::initializeDatabases();
print_r($res);

if ($res['mysql'] && $res['mongodb'] && $res['redis']) {
    echo "SUCCESS: All 3 databases (MySQL, MongoDB, Redis) connected and initialized!\n";
    exit(0);
} else {
    echo "FAILURE: One or more databases failed to initialize.\n";
    exit(1);
}

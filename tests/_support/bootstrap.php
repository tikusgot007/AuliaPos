<?php

/**
 * PHPUnit bootstrap: the CodeIgniter test bootstrap plus a fail-closed
 * guard for the `inbox` database group.
 *
 * Several Inbox tests empty the inbox tables in setUp(). If the `inbox`
 * group does not point to the dedicated test database, PHPUnit must stop
 * here, before any test can delete real Inbox data.
 *
 * Config check only: no database connection is opened here.
 */

require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

$expectedInboxDatabase = 'aulia_inboxdb_test';
$actualInboxDatabase   = config('Database')->inbox['database'] ?? '';

if ($actualInboxDatabase !== $expectedInboxDatabase) {
    fwrite(
        STDERR,
        "Refusing to run tests: inbox group points to '{$actualInboxDatabase}', expected {$expectedInboxDatabase}." . PHP_EOL
    );

    exit(1);
}

unset($expectedInboxDatabase, $actualInboxDatabase);

<?php

/**
 * PHPUnit bootstrap for feature tests that touch the `inbox` MySQL
 * database (tests/feature/*Test.php). Separate from the default
 * `vendor/bin/phpunit` run (phpunit.xml), which stays bootstrap-only /
 * no-database per AGENTS.md Phase 4 and sdlc.md Section 4 ("Tests must
 * never connect to the production or development database from .env").
 *
 * Config\Database::__construct() already redirects the `inbox` group to
 * `aulia_inboxdb_test` whenever ENVIRONMENT === 'testing' (see
 * app/Config/Database.php). The guard below is a fail-closed safety net
 * in case that redirect is ever removed or bypassed: if the live
 * connection does not report the dedicated test database, abort before
 * any test can run SQL against it.
 */

require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

$expectedInboxDatabase = 'aulia_inboxdb_test';
$configuredInboxDatabase = config('Database')->inbox['database'] ?? '';

if ($configuredInboxDatabase !== $expectedInboxDatabase) {
    fwrite(
        STDERR,
        "Refusing to run feature tests: inbox group config points to '{$configuredInboxDatabase}', expected '{$expectedInboxDatabase}'." . PHP_EOL
    );

    exit(1);
}

$liveInboxDatabase = (string) db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db;

if ($liveInboxDatabase !== $expectedInboxDatabase) {
    fwrite(
        STDERR,
        "Refusing to run feature tests: live inbox connection reports '{$liveInboxDatabase}', expected '{$expectedInboxDatabase}'." . PHP_EOL
    );

    exit(1);
}

unset($expectedInboxDatabase, $configuredInboxDatabase, $liveInboxDatabase);

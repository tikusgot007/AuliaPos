<?php

/**
 * PHPUnit bootstrap for database-backed integration tests
 * (tests/integration/*Test.php).
 *
 * Unlike tests/_support/bootstrap-feature.php, this suite does NOT touch the
 * `inbox` MySQL group. Under ENVIRONMENT === 'testing',
 * Config\Database::__construct() forces the default group to `tests`, which is
 * an in-memory SQLite connection (:memory:, `db_` prefix). Tests create their
 * own minimal schema with the Forge, so no .env database and no external
 * server are used (sdlc.md Section 4: tests never connect to the production or
 * development database).
 */

require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';

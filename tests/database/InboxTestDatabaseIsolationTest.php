<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guard test: the `inbox` group used by PHPUnit must be the dedicated
 * test database `aulia_inboxdb_test`, never the real `aulia_inboxdb`.
 *
 * Several Inbox tests empty the inbox tables in setUp(). If this guard
 * fails, those tests would wipe the real Inbox data.
 *
 * Read-only on purpose: no DatabaseTestTrait, no writes, so running it
 * is always safe.
 *
 * @internal
 */
final class InboxTestDatabaseIsolationTest extends CIUnitTestCase
{
    private const TEST_DATABASE = 'aulia_inboxdb_test';
    private const REAL_DATABASE = 'aulia_inboxdb';

    public function testConfigPointsInboxGroupToTestDatabase(): void
    {
        $this->assertSame(
            self::TEST_DATABASE,
            config('Database')->inbox['database'],
            'Config inbox group is not the test database: tests are about to touch the real Inbox database.'
        );
    }

    public function testInboxGroupHasNoDsnOrFailoverOverride(): void
    {
        $inbox = config('Database')->inbox;

        $this->assertSame(
            '',
            $inbox['DSN'],
            "inbox DSN is '{$inbox['DSN']}': a DSN overrides the test database when the connection opens."
        );

        $this->assertSame(
            [],
            $inbox['failover'],
            'inbox failover is set: a failed primary connection would fall back to a live database.'
        );
    }

    public function testLiveInboxConnectionUsesTestDatabase(): void
    {
        $live = $this->liveInboxDatabase();

        $this->assertSame(
            self::TEST_DATABASE,
            $live,
            "Live inbox connection uses '{$live}': tests are about to touch the real Inbox database."
        );
    }

    public function testLiveInboxConnectionIsNotRealDatabase(): void
    {
        $this->assertNotSame(
            self::REAL_DATABASE,
            $this->liveInboxDatabase(),
            'Live inbox connection is the real Inbox database (aulia_inboxdb). Stop: tests would delete real chats.'
        );
    }

    private function liveInboxDatabase(): string
    {
        return (string) db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db;
    }
}

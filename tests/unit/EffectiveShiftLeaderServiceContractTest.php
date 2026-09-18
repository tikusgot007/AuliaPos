<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EffectiveShiftLeaderServiceContractTest extends TestCase
{
    public function testServiceAndAuthorityFilesExist(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertFileExists($root . '/app/Services/EffectiveShiftLeaderService.php');
        $this->assertFileExists($root . '/app/Services/EvaluasiJendelaKerjaShift.php');
        $this->assertFileExists($root . '/app/Services/Authority.php');
    }

    public function testPriorityMigrationDefinesNullableUniqueColumn(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root . '/app/Database/Migrations/2026-09-13-000001_AddPriorityToUsers.php');

        $this->assertIsString($migration);
        $this->assertStringContainsString("ADD COLUMN `priority`", $migration);
        $this->assertStringContainsString('SMALLINT UNSIGNED NULL DEFAULT NULL', $migration);
        $this->assertStringContainsString('uq_users_priority', $migration);
    }
}

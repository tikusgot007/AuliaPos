<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * users.priority: NULL diperbolehkan berkali-kali (belum di-ranking),
 * non-NULL harus unik. DB UNIQUE constraint adalah otoritas akhir
 * (lihat migration produksi
 * app/Database/Migrations/2026-09-13-000001_AddPriorityToUsers.php);
 * di sini dibuktikan lewat exception saat insert/update duplikat.
 *
 * @internal
 */
final class PriorityUniquenessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private function seedUser(string $username, ?int $priority): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username' => $username,
            'priority' => $priority,
        ]);

        return (int) $db->insertID();
    }

    public function testMultipleNullDiperbolehkan(): void
    {
        $id1 = $this->seedUser('kasir_a', null);
        $id2 = $this->seedUser('kasir_b', null);
        $id3 = $this->seedUser('kasir_c', null);

        $this->assertNotSame(0, $id1);
        $this->assertNotSame(0, $id2);
        $this->assertNotSame(0, $id3);
    }

    public function testPriorityBerbedaDiperbolehkan(): void
    {
        $this->seedUser('kasir_a', 10);
        $this->seedUser('kasir_b', 7);

        $this->seeInDatabase('users', ['username' => 'kasir_a', 'priority' => 10]);
        $this->seeInDatabase('users', ['username' => 'kasir_b', 'priority' => 7]);
    }

    public function testPrioritySamaDitolakDb(): void
    {
        $this->seedUser('kasir_a', 10);

        $this->expectException(\Throwable::class);
        $this->seedUser('kasir_b', 10);
    }

    public function testPrioritySamaSaatUpdateDitolakDb(): void
    {
        $idA = $this->seedUser('kasir_a', 10);
        $this->seedUser('kasir_b', 7);

        $db = db_connect();

        $this->expectException(\Throwable::class);
        $db->table('users')->where('id', $idA)->update(['priority' => 7]);
    }
}

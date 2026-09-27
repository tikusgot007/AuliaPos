<?php

use App\Database\Migrations\AddQuotedSourceMessageIdToMessages;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Balas Pesan (Tahap 3, v1.6 REQ-008b) -- kontrak skema untuk kolom
 * `messages.quoted_source_message_id` (spec Section 4.2).
 *
 * Berjalan terhadap grup `inbox` yang diarahkan ke `aulia_inboxdb_test`
 * (salinan skema database Inbox yang sudah dimigrasi; riwayat migrasi ada di
 * database `default` sehingga `php spark migrate` tidak bisa membangun
 * database ini sendiri). Test round-trip membuktikan artefak migrasi mampu
 * membangun ulang kolomnya.
 *
 * @internal
 */
final class QuotedSourceMessageIdMigrationTest extends CIUnitTestCase
{
    private const MIGRATION_FILE = '2026-09-27-000002_AddQuotedSourceMessageIdToMessages.php';
    private const COLUMN         = 'quoted_source_message_id';
    private const AFTER_COLUMN   = 'quoted_media_available';

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    private function columnInfo(string $column): ?array
    {
        return $this->inbox->table('information_schema.COLUMNS')
            ->select('DATA_TYPE, IS_NULLABLE, COLUMN_TYPE, COLUMN_DEFAULT, ORDINAL_POSITION')
            ->where('TABLE_SCHEMA', $this->inbox->database)
            ->where('TABLE_NAME', 'messages')
            ->where('COLUMN_NAME', $column)
            ->get()
            ->getRowArray();
    }

    private function seedConversation(): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert([
            'chat_id'    => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type'   => 'pn',
            'status'     => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->inbox->insertID();
    }

    public function testKolomAdaDenganTipeSesuaiSpesifikasi(): void
    {
        $info = $this->columnInfo(self::COLUMN);

        $this->assertNotNull($info, 'messages.quoted_source_message_id must exist (REQ-008b, spec 4.2).');
        // DATA_TYPE (bukan COLUMN_TYPE): `int(10) unsigned` adalah detail
        // display yang berbeda antar versi MySQL/MariaDB; tipe dasarnya `int`.
        $this->assertSame('int', $info['DATA_TYPE'], 'Type must be INT (spec 4.2), portable across MySQL/MariaDB.');
        $this->assertSame('YES', $info['IS_NULLABLE'], 'Column must stay NULLable (GUD-001).');
    }

    public function testKolomDitempatkanSetelahQuotedMediaAvailable(): void
    {
        $kolom   = $this->columnInfo(self::COLUMN);
        $sebelum = $this->columnInfo(self::AFTER_COLUMN);

        $this->assertSame(
            (int) $sebelum['ORDINAL_POSITION'] + 1,
            (int) $kolom['ORDINAL_POSITION'],
            'Column must sit right after quoted_media_available (spec 4.2).'
        );
    }

    public function testKolomDefaultnyaNull(): void
    {
        $default = $this->columnInfo(self::COLUMN)['COLUMN_DEFAULT'];

        // MySQL melaporkan kolom `DEFAULT NULL` sebagai string 'NULL'.
        $this->assertTrue(
            $default === null || $default === 'NULL',
            'Column must have no real default (GUD-001); legacy rows read NULL.'
        );
    }

    public function testPesanBukanBalasanTetapNull(): void
    {
        $conversationId = $this->seedConversation();

        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'wa-' . bin2hex(random_bytes(6)),
            'direction'         => 'incoming',
            'message_type'      => 'text',
            'text'              => 'pesan biasa tanpa kutipan',
            'message_timestamp' => date('Y-m-d H:i:s'),
            'send_status'       => 'received',
        ]);

        $row = $this->inbox->table('messages')->where('conversation_id', $conversationId)->get()->getRowArray();

        $this->assertNull($row[self::COLUMN], 'GUD-001: must stay NULL for non-quote rows.');
    }

    public function testMigrasiAdditiveTidakMenambahIndeksMaupunForeignKey(): void
    {
        $index = $this->inbox->query(
            'SELECT COUNT(*) AS total FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->inbox->database, 'messages', self::COLUMN]
        )->getRowArray();

        $this->assertSame(0, (int) $index['total'], 'No index may be added on the snapshot column (GUD-001).');

        $fk = $this->inbox->query(
            'SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            . ' AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->inbox->database, 'messages', self::COLUMN]
        )->getRowArray();

        $this->assertSame(0, (int) $fk['total'], 'Snapshot is NOT a live FK (spec Section 9 "Never do").');
    }

    public function testUpDownRoundTripMemulihkanKolom(): void
    {
        $this->assertNotNull($this->columnInfo(self::COLUMN), 'Precondition: column exists in the test schema.');

        require_once APPPATH . 'Database/Migrations/' . self::MIGRATION_FILE;
        $migration = new AddQuotedSourceMessageIdToMessages();

        try {
            $migration->down();
            $this->assertNull($this->columnInfo(self::COLUMN), 'down() must drop the column.');

            $migration->up();
            $info = $this->columnInfo(self::COLUMN);
            // DATA_TYPE (bukan COLUMN_TYPE): `int(10) unsigned` adalah detail
            // display yang berbeda antar versi MySQL/MariaDB; tipe dasarnya `int`.
            $this->assertSame('int', $info['DATA_TYPE'], 'up() must recreate it as INT, portable across MySQL/MariaDB.');
            $this->assertSame('YES', $info['IS_NULLABLE'], 'up() must keep it NULLable.');
            // MySQL melaporkan kolom `DEFAULT NULL` sebagai string 'NULL'.
            $this->assertTrue(
                $info['COLUMN_DEFAULT'] === null || $info['COLUMN_DEFAULT'] === 'NULL',
                'up() must recreate it with no real default (GUD-001).'
            );
        } finally {
            // Jangan pernah meninggalkan skema uji shared dalam kondisi tidak
            // lengkap, bahkan kalau ada assertion di atas yang gagal.
            if ($this->columnInfo(self::COLUMN) === null) {
                $migration->up();
            }
        }
    }
}

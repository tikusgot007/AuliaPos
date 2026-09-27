<?php

use App\Database\Migrations\AddQuoteColumnsToMessages;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Balas Pesan (Tahap 3, TASK-001) -- kontrak skema untuk keempat kolom
 * kutipan pada `messages` (spec Section 4.2, GUD-001, REQ-007/REQ-008).
 *
 * Menjalankan terhadap grup `inbox` yang diarahkan ke `aulia_inboxdb_test`
 * di bawah environment testing (salinan skema dari database Inbox yang sudah
 * dimigrasi). Test round-trip di bawah membuktikan artefak migrasi itu sendiri
 * mampu membangun ulang skema, karena `php spark migrate` tidak bisa membuat
 * database ini sendiri (riwayat migrasi ada di database `default` -- F-02).
 *
 * @internal
 */
final class QuoteColumnsMigrationTest extends CIUnitTestCase
{
    private const MIGRATION_FILE = '2026-09-27-000001_AddQuoteColumnsToMessages.php';

    /** Kolom kutipan yang harus ada, dengan tipe COLUMN_TYPE yang diharapkan. */
    private const KOLOM = [
        'quoted_wa_message_id'   => 'varchar(255)',
        'quoted_sender_label'    => 'varchar(255)',
        'quoted_snippet'         => 'text',
        'quoted_media_available' => 'tinyint(1)',
    ];

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    /** Satu baris information_schema untuk kolom itu, atau null bila tidak ada. */
    private function columnInfo(string $column): ?array
    {
        return $this->inbox->table('information_schema.COLUMNS')
            ->select('DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_TYPE, COLUMN_DEFAULT')
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

    public function testKeempatKolomKutipanAdaDenganTipeSesuaiSpesifikasi(): void
    {
        foreach (self::KOLOM as $column => $expectedType) {
            $info = $this->columnInfo($column);

            $this->assertNotNull($info, "messages.{$column} must exist (TASK-001, spec 4.2).");
            $this->assertSame($expectedType, $info['COLUMN_TYPE'], "messages.{$column} type must match spec 4.2.");
            $this->assertSame('YES', $info['IS_NULLABLE'], "messages.{$column} must stay NULLable (GUD-001).");
        }
    }

    public function testKolomKutipanDefaultnyaNull(): void
    {
        // GUD-001: kolom baru tidak boleh membawa default nilai -- pesan yang
        // bukan balasan harus tetap NULL, bukan terisi diam-diam.
        //
        // Catatan MySQL: kolom yang dideklarasikan `DEFAULT NULL` dilaporkan
        // information_schema sebagai string 'NULL', bukan NULL, jadi keduanya
        // sama-sama diterima di sini. Yang penting: tidak ada default NYATA.
        foreach (self::KOLOM as $column => $expectedType) {
            $default = $this->columnInfo($column)['COLUMN_DEFAULT'];

            $this->assertTrue(
                $default === null || $default === 'NULL',
                "messages.{$column} must have no real default value (GUD-001)."
            );
        }
    }

    public function testPesanBukanBalasanTetapMemilikiSemuaKolomKutipanNull(): void
    {
        $conversationId = $this->seedConversation();

        $this->inbox->table('messages')->insert([
            'conversation_id'    => $conversationId,
            'wa_message_id'      => 'wa-' . bin2hex(random_bytes(6)),
            'direction'          => 'incoming',
            'message_type'       => 'text',
            'text'               => 'pesan biasa tanpa kutipan',
            'message_timestamp'  => date('Y-m-d H:i:s'),
            'send_status'        => 'received',
        ]);

        $row = $this->inbox->table('messages')->where('conversation_id', $conversationId)->get()->getRowArray();

        $this->assertNull($row['quoted_wa_message_id'], 'GUD-001: quoted_wa_message_id must stay NULL.');
        $this->assertNull($row['quoted_sender_label'], 'GUD-001: quoted_sender_label must stay NULL.');
        $this->assertNull($row['quoted_snippet'], 'GUD-001: quoted_snippet must stay NULL.');
        $this->assertNull($row['quoted_media_available'], 'GUD-001: quoted_media_available must stay NULL.');
    }

    public function testMigrasiAdditiveTidakMenambahIndeksBaru(): void
    {
        // GUD-001/minimalisme: kutipan dibaca lewat id yang sudah ada, jadi
        // tidak ada indeks UNIQUE maupun biasa yang perlu dibangun.
        foreach (array_keys(self::KOLOM) as $column) {
            $count = $this->inbox->query(
                'SELECT COUNT(*) AS total FROM information_schema.STATISTICS'
                . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$this->inbox->database, 'messages', $column]
            )->getRowArray();

            $this->assertSame(0, (int) $count['total'], "No index may be added on {$column} (GUD-001).");
        }
    }

    public function testMigrasiAdditiveTidakMenambahForeignKeyBaru(): void
    {
        // Kutipan adalah SNAPSHOT, bukan referensi hidup ke messages.id
        // (spec Section 9 "Never do", ALT-001) -- jadi tidak boleh ada FK.
        $fk = $this->inbox->query(
            'SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME LIKE ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->inbox->database, 'messages', 'quoted%']
        )->getRowArray();

        $this->assertSame(0, (int) $fk['total'], 'No foreign key may reference the quote columns (spec 9).');
    }

    public function testKolomLamaTidakBerubah(): void
    {
        // CON-009/GUD-001: migrasi additive tidak boleh mengubah arti kolom
        // lama. Guard yang sama dipakai migrasi gateway_operation_id.
        $this->assertSame('varchar', $this->columnInfo('wa_message_id')['DATA_TYPE']);
        $this->assertSame(255, (int) $this->columnInfo('wa_message_id')['CHARACTER_MAXIMUM_LENGTH']);
        $this->assertSame('NO', $this->columnInfo('wa_message_id')['IS_NULLABLE']);
        $this->assertSame("enum('received','sent','failed')", $this->columnInfo('send_status')['COLUMN_TYPE']);
    }

    public function testUpDownRoundTripMemulihkanKeempatKolom(): void
    {
        foreach (array_keys(self::KOLOM) as $column) {
            $this->assertNotNull($this->columnInfo($column), "Precondition: {$column} exists in the test schema.");
        }

        // Migrations dikecualikan dari classmap composer
        // (exclude-from-classmap **/Database/Migrations/**), jadi kelasnya
        // di-require eksplisit sebelum di-instantiate.
        require_once APPPATH . 'Database/Migrations/' . self::MIGRATION_FILE;
        $migration = new AddQuoteColumnsToMessages();

        try {
            $migration->down();
            foreach (array_keys(self::KOLOM) as $column) {
                $this->assertNull($this->columnInfo($column), "down() must drop {$column}.");
            }

            $migration->up();
            foreach (self::KOLOM as $column => $expectedType) {
                $this->assertSame($expectedType, $this->columnInfo($column)['COLUMN_TYPE'], "up() must recreate {$column}.");
                $this->assertSame('YES', $this->columnInfo($column)['IS_NULLABLE'], "up() must keep {$column} NULLable.");
            }
        } finally {
            // Jangan pernah meninggalkan skema uji shared dalam kondisi
            // tidak lengkap, bahkan kalau ada assertion di atas yang gagal.
            $missing = array_filter(array_keys(self::KOLOM), fn ($c) => $this->columnInfo($c) === null);
            if ($missing !== []) {
                $migration->up();
            }
        }
    }
}

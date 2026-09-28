<?php

use App\Database\Migrations\AddIsForwardedToMessages;
use App\Models\MessageModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Teruskan (Tahap 4, TASK-001) -- kontrak skema untuk kolom penanda
 * `messages.is_forwarded` (spec Section 4.2, REQ-008, REQ-009, GUD-001).
 *
 * Menjalankan terhadap grup `inbox` yang diarahkan ke `aulia_inboxdb_test`
 * di bawah environment testing (salinan skema dari database Inbox yang sudah
 * dimigrasi). Test round-trip di bawah membuktikan artefak migrasi itu sendiri
 * mampu membangun ulang skema -- pola yang sama dengan QuoteColumnsMigrationTest.
 *
 * @internal
 */
final class IsForwardedMigrationTest extends CIUnitTestCase
{
    private const MIGRATION_FILE = '2026-09-28-000001_AddIsForwardedToMessages.php';

    private const COLUMN = 'is_forwarded';

    /**
     * Kolom yang TIDAK boleh ada (REQ-009/ALT-004): penanda tunggal tanpa
     * penghitung forward dan tanpa referensi ke pesan asal.
     */
    private const KOLOM_TERLARANG = [
        'forward_count',
        'forwarded_count',
        'forwarded_from',
        'forward_from_message_id',
        'forward_depth',
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
            ->select('DATA_TYPE, IS_NULLABLE, COLUMN_TYPE, COLUMN_DEFAULT')
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

    // ------------------------------------------------------------------
    // Bentuk kolom (spec Section 4.2)
    // ------------------------------------------------------------------

    public function testKolomIsForwardedAdaDenganTipeSesuaiSpesifikasi(): void
    {
        $info = $this->columnInfo(self::COLUMN);

        $this->assertNotNull($info, 'messages.is_forwarded must exist (TASK-001, spec 4.2).');
        $this->assertSame('tinyint(1)', $info['COLUMN_TYPE'], 'Tipe harus tinyint(1) (spec 4.2).');
        $this->assertSame('NO', $info['IS_NULLABLE'], 'Kolom penanda WAJIB NOT NULL (spec 4.2).');
        $this->assertSame('0', (string) $info['COLUMN_DEFAULT'], 'Default WAJIB 0 (GUD-001: baris lama otomatis 0).');
    }

    public function testBarisLamaDanPesanBiasaBernilaiNol(): void
    {
        // GUD-001: pesan yang bukan hasil Teruskan (pesan masuk, catatan
        // internal, balasan biasa) TIDAK boleh terisi 1 diam-diam.
        $conversationId = $this->seedConversation();

        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'wa-' . bin2hex(random_bytes(6)),
            'direction'         => 'incoming',
            'message_type'      => 'text',
            'text'              => 'pesan biasa, tidak diteruskan',
            'message_timestamp' => date('Y-m-d H:i:s'),
            'send_status'       => 'received',
        ]);

        $row = $this->inbox->table('messages')->where('conversation_id', $conversationId)->get()->getRowArray();

        $this->assertSame(0, (int) $row[self::COLUMN], 'Pesan biasa harus punya is_forwarded = 0.');
    }

    public function testKolomBisaDitulisLaluDibacaLewatModel(): void
    {
        // Kontrak yang dipakai Inbox::kirimKeConversation(): kolom harus bisa
        // ditulis lewat MessageModel (ada di $allowedFields) lalu dibaca
        // kembali utuh.
        $conversationId = $this->seedConversation();

        $messageModel = new MessageModel();
        $messageModel->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'wa-' . bin2hex(random_bytes(6)),
            'direction'         => 'outgoing',
            'message_type'      => 'text',
            'text'              => 'pesan hasil Teruskan',
            'message_timestamp' => date('Y-m-d H:i:s'),
            'send_status'       => 'sent',
            self::COLUMN        => 1,
        ]);

        $row = $messageModel->find($messageModel->getInsertID());

        $this->assertSame(1, (int) $row[self::COLUMN], 'Kolom harus bisa ditulis lalu dibaca lewat MessageModel.');
    }

    public function testSemuaKolomKutipanTetapNullPadaPesanTeruskan(): void
    {
        // REQ-009: pesan hasil Teruskan tidak pernah membawa kutipan. Kolom
        // kutipan hanya diisi lewat $quoteSnapshot pada jalur Balas Pesan, dan
        // jalur Teruskan tidak pernah menyalakannya.
        $conversationId = $this->seedConversation();

        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'wa-' . bin2hex(random_bytes(6)),
            'direction'         => 'outgoing',
            'message_type'      => 'text',
            'text'              => 'isi pesan diteruskan',
            'message_timestamp' => date('Y-m-d H:i:s'),
            'send_status'       => 'sent',
            self::COLUMN        => 1,
        ]);

        $row = $this->inbox->table('messages')->where('conversation_id', $conversationId)->get()->getRowArray();

        $this->assertNull($row['quoted_wa_message_id'], 'REQ-009: kutipan tidak ikut terbawa.');
        $this->assertNull($row['quoted_sender_label'], 'REQ-009: kutipan tidak ikut terbawa.');
        $this->assertNull($row['quoted_snippet'], 'REQ-009: kutipan tidak ikut terbawa.');
        $this->assertNull($row['quoted_source_message_id'], 'REQ-009: kutipan tidak ikut terbawa.');
    }

    // ------------------------------------------------------------------
    // REQ-009/ALT-004: tidak ada kolom penghitung / referensi asal
    // ------------------------------------------------------------------

    public function testTidakAdaKolomPenghitungAtauReferensiAsal(): void
    {
        // REQ-009: penanda TUNGGAL tanpa penghitung. Kolom penghitung atau
        // referensi ke pesan asal berarti "menumpuk" -- yang justru dilarang.
        foreach (self::KOLOM_TERLARANG as $column) {
            $this->assertNull(
                $this->columnInfo($column),
                "messages.{$column} must not exist (REQ-009/ALT-004: penanda tunggal, tanpa penghitung)."
            );
        }
    }

    public function testMigrasiTidakMenambahIndeksBaru(): void
    {
        // GUD-001/minimalisme: penanda dibaca bersama baris pesan yang sudah
        // diambil, tidak pernah dicari sendiri -> tidak butuh indeks.
        $count = $this->inbox->query(
            'SELECT COUNT(*) AS total FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->inbox->database, 'messages', self::COLUMN]
        )->getRowArray();

        $this->assertSame(0, (int) $count['total'], 'No index may be added on is_forwarded (GUD-001).');
    }

    public function testMigrasiTidakMenambahForeignKeyBaru(): void
    {
        $fk = $this->inbox->query(
            'SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->inbox->database, 'messages', self::COLUMN]
        )->getRowArray();

        $this->assertSame(0, (int) $fk['total'], 'is_forwarded is a marker, not a live reference (spec 9).');
    }

    public function testKolomLamaTidakBerubah(): void
    {
        // GUD-001: migrasi additive tidak boleh mengubah arti kolom lama --
        // guard yang sama dipakai QuoteColumnsMigrationTest.
        $this->assertSame('varchar', $this->columnInfo('wa_message_id')['DATA_TYPE']);
        $this->assertSame("enum('received','sent','failed')", $this->columnInfo('send_status')['COLUMN_TYPE']);
        $this->assertSame('tinyint(1)', $this->columnInfo('is_internal')['COLUMN_TYPE'], 'is_internal tidak boleh berubah.');
    }

    // ------------------------------------------------------------------
    // Round-trip up()/down()
    // ------------------------------------------------------------------

    public function testUpDownRoundTripMemulihkanKolom(): void
    {
        $this->assertNotNull($this->columnInfo(self::COLUMN), 'Precondition: is_forwarded exists in the test schema.');

        // Migrations dikecualikan dari classmap composer
        // (exclude-from-classmap **/Database/Migrations/**), jadi kelasnya
        // di-require eksplisit sebelum di-instantiate.
        require_once APPPATH . 'Database/Migrations/' . self::MIGRATION_FILE;
        $migration = new AddIsForwardedToMessages();

        try {
            $migration->down();
            $this->assertNull($this->columnInfo(self::COLUMN), 'down() must drop is_forwarded.');

            $migration->up();
            $info = $this->columnInfo(self::COLUMN);
            $this->assertNotNull($info, 'up() must recreate is_forwarded.');
            $this->assertSame('tinyint(1)', $info['COLUMN_TYPE'], 'up() must recreate the same type.');
            $this->assertSame('NO', $info['IS_NULLABLE'], 'up() must keep is_forwarded NOT NULL.');
            $this->assertSame('0', (string) $info['COLUMN_DEFAULT'], 'up() must keep default 0.');
        } finally {
            // Jangan pernah meninggalkan skema uji shared dalam kondisi tidak
            // lengkap, bahkan kalau ada assertion di atas yang gagal.
            if ($this->columnInfo(self::COLUMN) === null) {
                $migration->up();
            }
        }
    }

    public function testUpBernilaiIdempoten(): void
    {
        // Dijalankan dua kali (mis. setelah diterapkan manual di database uji)
        // tidak boleh meledak atau menambah kolom dobel.
        require_once APPPATH . 'Database/Migrations/' . self::MIGRATION_FILE;
        $migration = new AddIsForwardedToMessages();

        $migration->up();
        $migration->up();

        $count = $this->inbox->query(
            'SELECT COUNT(*) AS total FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->inbox->database, 'messages', self::COLUMN]
        )->getRowArray();

        $this->assertSame(1, (int) $count['total'], 'up() harus idempoten (satu kolom, tidak dobel).');
    }
}

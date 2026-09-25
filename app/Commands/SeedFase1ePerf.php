<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * One-off fixture loader for the Fase 1e speed measurement (M3 Fase 1e
 * TASK-026, ASSUMPTION-004). It writes 2.000 conversations x 100 messages =
 * 200.000 `messages` rows, so it is guarded twice: only the `inbox` group is
 * accepted, and the active database of that group must literally be
 * `aulia_inboxdb_perf`. It can never be pointed at `aulia_inboxdb` (live) or
 * `aulia_inboxdb_test` (composer test).
 *
 * This command is deliberately NOT wired into `composer test`, PHPUnit or
 * CI. It only runs when a human types:
 * `php spark aulia:seed-fase1e-perf --dbgroup=inbox`
 */
class SeedFase1ePerf extends BaseCommand
{
    protected $group       = 'AULIA';
    protected $name        = 'aulia:seed-fase1e-perf';
    protected $description = 'Mengisi database perf terpisah dengan data uji kecepatan Fase 1e (2.000 conversation x 100 pesan).';
    protected $usage       = 'aulia:seed-fase1e-perf --dbgroup=inbox';
    protected $arguments   = [];
    protected $options     = [
        '--dbgroup' => 'Grup koneksi database yang diisi datanya. Hanya `inbox` yang didukung.',
    ];

    /**
     * The only database this command is allowed to write to.
     */
    public const DATABASE_DIIZINKAN = 'aulia_inboxdb_perf';

    /**
     * AC-016 keywords: a rare word present in exactly one message, a common
     * word present in roughly 10% of the messages, and the single letter `a`
     * (present in every text). These constants are the single source of the
     * measurement keywords, so TASK-027 measures exactly what was seeded.
     */
    public const KATA_LANGKA     = 'zarahrafi';
    public const KATA_UMUM       = 'katalog';
    public const KATA_SATU_HURUF = 'a';

    /**
     * Fixture size (ASSUMPTION-004, CONFIRMED in spec rev 1.4).
     */
    public const JUMLAH_CONVERSATION    = 2000;
    public const PESAN_PER_CONVERSATION = 100;

    /**
     * Fixture markers. Every row written by this command carries one, so the
     * measurement data is always distinguishable from real data.
     */
    private const PREFIX_CHAT_ID = 'fase1eperf-';
    private const PREFIX_WA_ID   = 'fase1eperf-';

    /**
     * Insert batch size: keeps every statement small enough for MariaDB
     * while still writing 200.000 rows in a few hundred statements.
     */
    private const UKURAN_BATCH = 500;

    /**
     * Timestamp of the first message; conversation $i starts one hour later
     * and each of its messages is 7 minutes newer than the previous one.
     */
    private const WAKTU_AWAL = '2026-06-01 00:00:00';

    public function run(array $params)
    {
        $dbGroup = $this->ambilDbGroup($params);

        if ($dbGroup !== 'inbox') {
            CLI::error('DITOLAK: command ini hanya mendukung grup koneksi `inbox` (diberikan: `' . $dbGroup . '`).');
            CLI::write('Tidak ada data yang ditulis.', 'yellow');

            return EXIT_ERROR;
        }

        $db           = Database::connect($dbGroup);
        $namaDatabase = (string) $db->getDatabase();

        if ($namaDatabase !== self::DATABASE_DIIZINKAN) {
            CLI::error(
                'DITOLAK: database grup `inbox` saat ini adalah `' . $namaDatabase . '`. '
                . 'Command ini hanya boleh mengisi `' . self::DATABASE_DIIZINKAN . '` (database perf terpisah), '
                . 'TIDAK PERNAH `aulia_inboxdb` (live) atau `aulia_inboxdb_test`.'
            );
            CLI::write(
                'Tidak ada data yang ditulis. Arahkan `database.inbox.database` ke `' . self::DATABASE_DIIZINKAN
                . '` di `.env` lebih dulu (lihat docs/ARCHITECTURE.md bagian 11 dan prosedur bagian 6 spec Fase 1e).',
                'yellow'
            );

            return EXIT_ERROR;
        }

        if (!$db->tableExists('conversations') || !$db->tableExists('messages')) {
            CLI::error('DITOLAK: tabel `conversations`/`messages` tidak ada di `' . $namaDatabase . '`. Provisioning skema dulu (docs/ARCHITECTURE.md bagian 11).');

            return EXIT_ERROR;
        }

        CLI::write('Database terverifikasi: ' . $namaDatabase, 'green');
        CLI::write(
            'Mengisi ' . number_format(self::JUMLAH_CONVERSATION, 0, ',', '.') . ' conversation x '
            . self::PESAN_PER_CONVERSATION . ' pesan = '
            . number_format(self::JUMLAH_CONVERSATION * self::PESAN_PER_CONVERSATION, 0, ',', '.') . ' baris messages ...',
            'yellow'
        );

        return $this->isiData($db);
    }

    /**
     * Reads the `--dbgroup` option.
     *
     * CI4 4.7 only understands the `--option value` spelling, while the
     * approved spec (section 6) documents `--dbgroup=inbox`. Both spellings
     * are accepted here; an unparsable value is returned as is so the guard
     * below rejects it instead of silently falling back to `inbox`.
     */
    private function ambilDbGroup(array $params): string
    {
        if (isset($params['dbgroup']) && $params['dbgroup'] !== '') {
            return (string) $params['dbgroup'];
        }

        foreach (CLI::getOptions() as $nama => $nilai) {
            $nama = (string) $nama;

            if ($nama === 'dbgroup' && is_string($nilai)) {
                return $nilai;
            }

            if (str_starts_with($nama, 'dbgroup=')) {
                return substr($nama, 8);
            }
        }

        return 'inbox';
    }

    /**
     * Writes the whole fixture and prints the evidence TASK-027 needs.
     */
    private function isiData(BaseConnection $db): int
    {
        $waktuMulai = microtime(true);

        $petaConversation = $this->isiConversation($db);
        $jumlahPesan      = $this->isiPesan($db, $petaConversation);

        CLI::write('Selesai dalam ' . round(microtime(true) - $waktuMulai, 1) . ' detik.', 'green');
        CLI::write('conversations terisi : ' . number_format(count($petaConversation), 0, ',', '.'));
        CLI::write('messages terisi      : ' . number_format($jumlahPesan, 0, ',', '.'));

        $this->cetakJumlahKataKunci($db);

        return EXIT_SUCCESS;
    }

    /**
     * Inserts the conversations in batches.
     *
     * @return array<string, int> map of chat_id => conversation id
     */
    private function isiConversation(BaseConnection $db): array
    {
        $sekarang = date('Y-m-d H:i:s');
        $baris    = [];

        for ($i = 1; $i <= self::JUMLAH_CONVERSATION; $i++) {
            $baris[] = [
                'chat_id'                => $this->chatId($i),
                'jid_type'               => 'pn',
                'contact_name'           => 'Pelanggan Uji ' . $i,
                'whatsapp_name'          => 'Pelanggan Uji ' . $i,
                'phone'                  => '6282200' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'manual_phone'           => null,
                'status'                 => 'open',
                'assigned_to'            => null,
                'last_message_at'        => $this->waktuPesan($i, self::PESAN_PER_CONVERSATION),
                'last_message_direction' => 'outgoing',
                'created_at'             => $sekarang,
                'updated_at'             => $sekarang,
            ];

            if (count($baris) >= self::UKURAN_BATCH) {
                $db->table('conversations')->insertBatch($baris);
                $baris = [];
            }
        }

        if ($baris !== []) {
            $db->table('conversations')->insertBatch($baris);
        }

        $hasil = $db->table('conversations')
            ->select('id, chat_id')
            ->like('chat_id', self::PREFIX_CHAT_ID, 'after')
            ->get()
            ->getResultArray();

        $peta = [];

        foreach ($hasil as $row) {
            $peta[(string) $row['chat_id']] = (int) $row['id'];
        }

        return $peta;
    }

    /**
     * Inserts the messages in batches.
     *
     * @param array<string, int> $petaConversation map of chat_id => conversation id
     */
    private function isiPesan(BaseConnection $db, array $petaConversation): int
    {
        $jumlah = 0;
        $baris  = [];

        for ($i = 1; $i <= self::JUMLAH_CONVERSATION; $i++) {
            $idConversation = $petaConversation[$this->chatId($i)] ?? null;

            if ($idConversation === null) {
                continue;
            }

            for ($p = 1; $p <= self::PESAN_PER_CONVERSATION; $p++) {
                $baris[] = $this->barisPesan($idConversation, $i, $p);

                if (count($baris) >= self::UKURAN_BATCH) {
                    $db->table('messages')->insertBatch($baris);
                    $jumlah += count($baris);
                    $baris = [];
                }
            }
        }

        if ($baris !== []) {
            $db->table('messages')->insertBatch($baris);
            $jumlah += count($baris);
        }

        return $jumlah;
    }

    /**
     * Builds one `messages` row.
     *
     * @return array<string, mixed>
     */
    private function barisPesan(int $idConversation, int $i, int $p): array
    {
        $catatanInternal = $p === 25;
        $kataLangka      = $i === 1500 && $p === 42;
        $kataUmum        = ! $catatanInternal && $p % 10 === 0;
        $arah            = $catatanInternal || $p % 2 === 0 ? 'outgoing' : 'incoming';

        if ($kataLangka) {
            $teks = 'Permintaan khusus dari pelanggan ' . self::KATA_LANGKA . ' untuk cetak ulang.';
        } elseif ($kataUmum) {
            $teks = 'Mohon cek ' . self::KATA_UMUM . ' terbaru kami untuk pesanan nomor ' . $p . '.';
        } elseif ($catatanInternal) {
            $teks = 'Catatan internal: pelanggan sudah menanyakan pesanan nomor ' . $p . '.';
        } elseif ($p % 17 === 0) {
            $teks = null; // media without caption (REQ-014 NULL text path)
        } else {
            $teks = 'Pesanan nomor ' . $p . ' sudah kami proses, mohon dicek kembali ya.';
        }

        return [
            'conversation_id'   => $idConversation,
            'wa_message_id'     => self::PREFIX_WA_ID . $i . '-' . $p,
            'direction'         => $arah,
            'is_internal'       => $catatanInternal ? 1 : 0,
            'message_type'      => 'text',
            'sender_jid'        => $arah === 'incoming' ? $this->chatId($i) : 'staff@aulia.local',
            'text'              => $teks,
            'sent_by_user_id'   => $arah === 'outgoing' ? 1 : null,
            'send_status'       => $arah === 'incoming' ? 'received' : 'sent',
            'message_timestamp' => $this->waktuPesan($i, $p),
            'created_at'        => $this->waktuPesan($i, $p),
        ];
    }

    /**
     * Timestamp of message $p inside conversation $i.
     */
    private function waktuPesan(int $i, int $p): string
    {
        $awal = strtotime(self::WAKTU_AWAL) + (($i - 1) * 3600);

        return date('Y-m-d H:i:s', $awal + ($p * 420));
    }

    /**
     * chat_id of fixture conversation $i.
     */
    private function chatId(int $i): string
    {
        return self::PREFIX_CHAT_ID . $i . '@s.whatsapp.net';
    }

    /**
     * Prints the seeded volume of each AC-016 keyword, so the measurement
     * step can prove which datasets the three queries really touched.
     */
    private function cetakJumlahKataKunci(BaseConnection $db): void
    {
        CLI::write('Volume kata kunci AC-016 di ' . $db->getDatabase() . ':', 'yellow');

        foreach ([self::KATA_LANGKA, self::KATA_UMUM, self::KATA_SATU_HURUF] as $kata) {
            $jumlah = $db->table('messages')
                ->like('text', $kata)
                ->where('deleted_at', null)
                ->countAllResults();

            CLI::write(sprintf('  %-12s -> %s pesan', $kata, number_format($jumlah, 0, ',', '.')));
        }
    }
}

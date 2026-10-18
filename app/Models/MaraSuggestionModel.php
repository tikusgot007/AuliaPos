<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model untuk tabel `mara_suggestions` di database aulia_inboxdb.
 *
 * Menyimpan saran yang dikirim M.A.R.A lewat `POST /api/inbox/mara/suggestions`
 * dan dibaca kasir lewat `GET /inbox/api/conversations/(:num)/suggestions`.
 * Lihat migration CreateMaraSuggestions untuk arti kolom & siklus hidup status.
 *
 * PENTING: $DBGroup eksplisit 'inbox' -- TIDAK PERNAH mengandalkan default
 * group, supaya mustahil salah nyambung ke aulia_kasirdb (pola sama dengan
 * ConversationModel/MaraResolveLogModel).
 */
class MaraSuggestionModel extends Model
{
    protected $DBGroup    = 'inbox';
    protected $table      = 'mara_suggestions';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    // created_at/updated_at diisi MANUAL (Asia/Jakarta eksplisit, konsisten
    // pola mara_resolve_log & tabel Inbox lain yang tidak memakai $useTimestamps).
    protected $useTimestamps = false;

    protected $allowedFields = [
        'conversation_id',
        'type',
        'priority',
        'text',
        'items',
        'reason',
        'action',
        'cart_items',
        'status',
        'expires_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Saran yang layak ditampilkan kasir untuk satu percakapan:
     * status='active' DAN belum lewat expires_at (AC-8/AC-9). Urut
     * kepentingan: darurat dulu, lalu tinggi, lalu normal; di dalam
     * prioritas yang sama, yang terbaru dulu.
     *
     * Urutan prioritas dihitung di PHP (bukan SQL FIELD()) supaya tidak
     * bergantung pada fungsi spesifik MySQL dan mudah diuji.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeForConversation(int $conversationId, ?string $nowWib = null): array
    {
        $now = $nowWib ?? self::nowWib();

        $rows = $this->where('conversation_id', $conversationId)
            ->where('status', 'active')
            ->groupStart()
                ->where('expires_at', null)
                ->orWhere('expires_at >', $now)
            ->groupEnd()
            ->findAll();

        // Rank prioritas: darurat (0) > tinggi (1) > normal (2); lalu terbaru dulu.
        $rank = ['darurat' => 0, 'tinggi' => 1, 'normal' => 2];

        usort($rows, static function (array $a, array $b) use ($rank): int {
            $ra = $rank[$a['priority']] ?? 3;
            $rb = $rank[$b['priority']] ?? 3;

            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });

        return $rows;
    }

    /**
     * Kunci SERIALISASI per percakapan sebelum supersede+insert (AC-5).
     *
     * Kenapa perlu: transaksi saja tidak cukup -- kalau belum ada baris
     * 'active' bertipe sama, UPDATE supersede mencocokkan 0 baris dan TIDAK
     * mengambil row lock, sehingga dua request bersamaan bisa sama-sama
     * insert 'active' untuk tipe yang sama. `SELECT ... FOR UPDATE` pada baris
     * `conversations` (pasti sudah ada, conversation_id sudah diverifikasi
     * pemanggil) memaksa request kedua menunggu sampai request pertama commit,
     * jadi supersede-nya pasti melihat baris yang baru saja di-insert.
     *
     * HARUS dipanggil di dalam transaksi yang sama dengan supersede+insert.
     * No-op aman kalau baris conversation tidak ada (lock tidak terambil).
     */
    public function lockConversation(int $conversationId): void
    {
        // FOR UPDATE hanya bermakna di dalam transaksi; kalau tidak ada
        // transaksi aktif, lock langsung dilepas (autocommit) dan tidak
        // merusak apa pun.
        $this->db->query(
            'SELECT id FROM conversations WHERE id = ? FOR UPDATE',
            [$conversationId]
        );
    }

    /**
     * Tandai saran 'active' bertipe SAMA pada percakapan ini sebagai
     * 'superseded' (AC-5: maks 1 aktif per tipe). Dipanggil SEBELUM insert
     * saran baru, di dalam satu transaksi (lihat MaraController::suggestions()).
     *
     * @return int jumlah baris yang di-supersede
     */
    public function supersedeSameType(int $conversationId, string $type, ?string $nowWib = null): int
    {
        $now = $nowWib ?? self::nowWib();

        $this->where('conversation_id', $conversationId)
            ->where('type', $type)
            ->where('status', 'active')
            ->set([
                'status'     => 'superseded',
                'updated_at' => $now,
            ])
            ->update();

        return $this->db->affectedRows();
    }

    /**
     * Tandai SEMUA saran 'active' pada percakapan ini sebagai 'expired'
     * (AC-10: kasir sudah membalas -> saran tidak relevan lagi). Baris TIDAK
     * dihapus (keputusan user: tetap ada sebagai jejak audit).
     *
     * @return int jumlah baris yang di-expire
     */
    public function expireForConversation(int $conversationId, ?string $nowWib = null): int
    {
        $now = $nowWib ?? self::nowWib();

        $this->where('conversation_id', $conversationId)
            ->where('status', 'active')
            ->set([
                'status'     => 'expired',
                'updated_at' => $now,
            ])
            ->update();

        return $this->db->affectedRows();
    }

    /**
     * Waktu sekarang dalam format DATETIME WIB -- satu sumber kebenaran
     * supaya penyimpanan & perbandingan expires_at konsisten (pola sama
     * dengan Inbox/MaraController).
     */
    public static function nowWib(): string
    {
        return (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    }
}

<?php

namespace App\Controllers;

use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\MaraResolveLogModel;
use Config\Database;
use Config\Mara as MaraConfig;

/**
 * Endpoint machine-to-machine untuk M.A.R.A (agent AI pemantau Inbox,
 * jalan di aulia7, satu LAN dengan server ini) -- BUKAN untuk
 * dipanggil dari browser kasir. Diproteksi Bearer token lewat filter
 * 'maratoken' (lihat Routes.php & app/Filters/MaraTokenFilter.php),
 * dikecualikan dari filter session 'auth' sama seperti InboxGatewayApi
 * (M.A.R.A tidak pernah punya session/cookie).
 *
 * Kontrak M.A.R.A ada 3 jalur, PRINSIP KEAMANAN dasar: anonim secara
 * default, identitas asli hanya bisa diambil on-demand + tercatat.
 *
 * 1. export()  -- GET histori (training/belajar pola). Data CUSTOMER
 *    DIANONIMKAN (customer_ref = hash nomor), SENGAJA tidak membawa
 *    contact_name/phone asli -- M.A.R.A tidak perlu tahu SIAPA,
 *    cukup POLA percakapan untuk belajar.
 * 2. resolve() -- GET identitas ASLI satu conversation (nama, nomor,
 *    histori lengkap). HANYA dipanggil M.A.R.A saat eskalasi kasus
 *    nyata ke pemilik (bukan dipanggil otomatis tiap training) --
 *    setiap panggilan WAJIB tercatat di mara_resolve_log untuk audit.
 * 3. (webhook realtime + suggestions -- TODO, belum diimplementasi
 *    di controller ini; dibahas terpisah saat fase operasional.)
 *
 * SEMUA method di sini pakai koneksi database 'inbox' (aulia_inboxdb),
 * TIDAK PERNAH koneksi default AuliaPos -- konsisten InboxGatewayApi.
 */
class MaraController extends BaseController
{
    /**
     * GET /api/inbox/mara/export/conversations
     *
     * Query params:
     * - since   (opsional, 'Y-m-d H:i:s' atau 'Y-m-d') -- batas bawah created_at conversations
     * - until   (opsional, sama format) -- batas atas created_at conversations
     * - cursor  (opsional, int) -- conversations.id TERAKHIR yang sudah diambil
     *            pemanggil sebelumnya; hasil berikutnya SELALU id > cursor
     *            (urut naik, stabil walau ada insert baru di tengah paginasi)
     * - limit   (opsional, int, default 100, maks 500)
     *
     * Payload per conversation SENGAJA tidak membawa contact_name/phone
     * asli -- hanya customer_ref (hash SHA-256 nomor + salt dari
     * Config\Mara::$resolveSalt). Hash konsisten: nomor yang sama selalu
     * menghasilkan customer_ref yang sama (M.A.R.A tetap bisa melihat
     * "pelanggan ini sudah order N kali" sebagai pola), TAPI tidak bisa
     * dibalik ke nomor asli tanpa salt (yang hanya ada di server ini).
     *
     * conversation_id DISERTAKAN APA ADANYA (bukan data pribadi, cuma
     * ID internal) -- dipakai M.A.R.A sebagai kunci rujukan kalau nanti
     * perlu eskalasi lewat resolve().
     */
    public function export()
    {
        $since  = $this->request->getGet('since');
        $until  = $this->request->getGet('until');
        $cursor = (int) ($this->request->getGet('cursor') ?? 0);
        $limit  = (int) ($this->request->getGet('limit') ?? 100);

        if ($limit < 1) {
            $limit = 100;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        $conversationModel = new ConversationModel();

        $builder = $conversationModel
            ->orderBy('id', 'ASC')
            ->where('id >', $cursor);

        if ($since) {
            $parsedSince = $this->parseDateBoundary((string) $since);
            if ($parsedSince === null) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status'  => 'error',
                    'message' => "Parameter 'since' tidak valid.",
                ]);
            }
            $builder = $builder->where('created_at >=', $parsedSince);
        }

        if ($until) {
            $parsedUntil = $this->parseDateBoundary((string) $until);
            if ($parsedUntil === null) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status'  => 'error',
                    'message' => "Parameter 'until' tidak valid.",
                ]);
            }
            $builder = $builder->where('created_at <=', $parsedUntil);
        }

        $conversations = $builder->findAll($limit);

        $messageModel = new MessageModel();
        $resolveSalt  = (new MaraConfig())->resolveSalt;

        $data = [];
        foreach ($conversations as $conv) {
            $messages = $messageModel
                ->where('conversation_id', $conv['id'])
                ->orderBy('message_timestamp', 'ASC')
                ->findAll();

            $data[] = [
                'conversation_id' => (int) $conv['id'],
                'customer_ref'    => $this->hashCustomerRef($conv['phone'] ?? null, $resolveSalt),
                'status'          => $conv['status'],
                'created_at'      => $conv['created_at'],
                'closed_at'       => $conv['closed_at'] ?? null,
                'order_outcome'   => [
                    'completed'           => ($conv['status'] === 'closed'),
                    'resolution_time_min' => $this->resolutionMinutes($conv['created_at'] ?? null, $conv['closed_at'] ?? null),
                ],
                'messages' => array_map(static function (array $m): array {
                    return [
                        'direction'  => $m['direction'],
                        'type'       => $m['message_type'],
                        'text'       => $m['text'],
                        'created_at' => $m['message_timestamp'],
                        // sent_by_user_id DISERTAKAN (ID staff internal, bukan
                        // data pribadi customer) -- berguna buat pola "siapa
                        // yang menangani cepat/lambat" tanpa membuka identitas
                        // pelanggan sama sekali.
                        'sent_by_user_id' => $m['sent_by_user_id'] ?? null,
                    ];
                }, $messages),
            ];
        }

        $nextCursor = $conversations !== [] ? (int) end($conversations)['id'] : $cursor;

        return $this->response->setJSON([
            'status'      => 'success',
            'data'        => $data,
            'next_cursor' => $nextCursor,
            'has_more'    => count($conversations) === $limit,
        ]);
    }

    /**
     * GET /api/inbox/mara/resolve/{conversation_id}
     *
     * Membuka IDENTITAS ASLI satu conversation (contact_name, phone,
     * chat_id, histori pesan lengkap) -- TIDAK dianonimkan. Dipanggil
     * HANYA saat M.A.R.A eskalasi kasus (query string 'reason' opsional,
     * bebas teks, buat jejak audit kenapa resolve ini dipanggil).
     *
     * SETIAP panggilan dicatat ke mara_resolve_log (conversation_id,
     * reason, IP pemanggil, waktu) SEBELUM data dikembalikan -- supaya
     * log tetap tercatat walau conversation ternyata tidak ditemukan.
     */
    public function resolve($conversationId = null)
    {
        $conversationId = (int) $conversationId;
        if ($conversationId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => "Parameter conversation_id tidak valid.",
            ]);
        }

        $reason = $this->request->getGet('reason');

        $this->logResolveAccess($conversationId, $reason !== null ? (string) $reason : null);

        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        if ($conversation === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        $messageModel = new MessageModel();
        $messages = $messageModel
            ->where('conversation_id', $conversationId)
            ->orderBy('message_timestamp', 'ASC')
            ->findAll();

        return $this->response->setJSON([
            'status' => 'success',
            'data'   => [
                'conversation_id' => (int) $conversation['id'],
                'chat_id'         => $conversation['chat_id'],
                'contact_name'    => $conversation['contact_name'] ?? ($conversation['whatsapp_name'] ?? null),
                'phone'           => $conversation['phone'] ?? ($conversation['manual_phone'] ?? null),
                'status'          => $conversation['status'],
                'messages'        => array_map(static function (array $m): array {
                    return [
                        'direction'  => $m['direction'],
                        'type'       => $m['message_type'],
                        'text'       => $m['text'],
                        'created_at' => $m['message_timestamp'],
                    ];
                }, $messages),
            ],
        ]);
    }

    /**
     * Catat panggilan resolve() ke mara_resolve_log. Kegagalan simpan
     * log TIDAK menggagalkan request (best-effort + error log) --
     * tapi SELALU dicoba SEBELUM data identitas dikembalikan ke
     * pemanggil, bukan setelahnya, supaya urutan "akses tercatat dulu"
     * terjaga walau proses setelahnya terputus.
     */
    private function logResolveAccess(int $conversationId, ?string $reason): void
    {
        $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

        try {
            (new MaraResolveLogModel())->insert([
                'conversation_id'   => $conversationId,
                'reason'            => $reason,
                'requested_from_ip' => $this->request->getIPAddress(),
                'created_at'        => $now,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'MaraController::resolve() gagal menulis mara_resolve_log untuk conversation_id=' . $conversationId . ': ' . $e->getMessage());
        }
    }

    /**
     * Hash SHA-256(phone + salt) -> customer_ref. Nomor kosong/null
     * (conversation @lid yang belum terverifikasi) -> null (tidak ada
     * yang bisa di-hash, BUKAN di-hash dari string kosong -- itu akan
     * membuat SEMUA conversation tanpa nomor punya customer_ref yang
     * SAMA, salah secara statistik untuk pembelajaran pola).
     */
    private function hashCustomerRef(?string $phone, string $salt): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        return 'cust_' . substr(hash('sha256', $phone . $salt), 0, 16);
    }

    /**
     * Delta menit created_at -> closed_at, null kalau belum closed atau
     * salah satu timestamp tidak ada -- dipakai M.A.R.A sebagai sinyal
     * "order_outcome.resolution_time_min" untuk belajar pola kecepatan
     * respons.
     */
    private function resolutionMinutes(?string $createdAt, ?string $closedAt): ?int
    {
        if (!$createdAt || !$closedAt) {
            return null;
        }

        try {
            $start = new \DateTime($createdAt);
            $end   = new \DateTime($closedAt);
        } catch (\Throwable $e) {
            return null;
        }

        return (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
    }

    /**
     * Terima 'Y-m-d' atau 'Y-m-d H:i:s' dari query string (input tak
     * tepercaya dari M.A.R.A) -> format DATETIME MySQL. Null kalau
     * tidak bisa di-parse sama sekali (trust boundary, sama pola
     * dengan InboxGatewayApi::parseTimestamp()).
     */
    private function parseDateBoundary(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $dt = new \DateTime($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
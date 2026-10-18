<?php

namespace App\Controllers;

use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\MaraResolveLogModel;
use App\Models\MaraSuggestionModel;
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
 * 3. suggestions() -- POST saran M.A.R.A untuk kasir (balasan /
 *    daftar-periksa / peringatan). Arah KEBALIKAN dari webhook: di
 *    sini M.A.R.A yang menulis, AuliaPos menyimpan ke mara_suggestions
 *    (grup inbox) dan kasir membacanya lewat endpoint session-auth
 *    terpisah (Inbox::apiSuggestions()). Lihat
 *    docs/requirements/2026-10-13-mara-saran-ke-kasir.md.
 *
 * SEMUA method di sini pakai koneksi database 'inbox' (aulia_inboxdb),
 * TIDAK PERNAH koneksi default AuliaPos -- konsisten InboxGatewayApi.
 */
class MaraController extends BaseController
{
    /** Tipe saran yang diterima dari M.A.R.A. */
    private const SUGGESTION_TYPES = ['balasan', 'daftar-periksa', 'peringatan'];

    /** Tingkat prioritas saran (menentukan warna di UI kasir). */
    private const SUGGESTION_PRIORITIES = ['normal', 'tinggi', 'darurat'];

    /** Batas panjang teks saran / satu langkah checklist. */
    private const SUGGESTION_TEXT_MAX = 4096;
    private const SUGGESTION_ITEM_MAX = 500;

    /** Batas jumlah langkah dalam satu daftar-periksa. */
    private const SUGGESTION_ITEMS_MAX = 50;

    /** Batas baris produk dan rentang jumlah dalam `cart_items`. */
    private const CART_ITEMS_MAX = 20;
    private const CART_QTY_MAX   = 999;

    /**
     * Aksi UI opsional yang boleh menempel pada saran. Whitelist ketat:
     * hanya pengenal, BUKAN URL/parameter -- tombolnya memanggil endpoint
     * sesi kasir untuk percakapan yang sedang terbuka.
     */
    private const SUGGESTION_ACTIONS = ['buka_kasir'];

    /** Tipe saran yang boleh membawa `action`. */
    private const SUGGESTION_ACTION_TYPES = ['balasan', 'peringatan'];

    /** TTL default saran bila M.A.R.A tidak mengirim expires_at. */
    private const SUGGESTION_DEFAULT_TTL_MINUTES = 30;

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
     * POST /api/inbox/mara/suggestions
     *
     * M.A.R.A mengirim SATU saran untuk kasir pada satu percakapan.
     * Body JSON (final shape ditentukan AuliaPos, M.A.R.A menyesuaikan):
     *   {
     *     "conversation_id": 403,
     *     "type":     "balasan" | "daftar-periksa" | "peringatan",
     *     "priority": "normal" | "tinggi" | "darurat",
     *     "text":     "draft balasan / isi peringatan" (boleh kosong utk daftar-periksa),
     *     "items":    ["langkah 1", "langkah 2"] (WAJIB utk type='daftar-periksa'),
     *     "reason":   "kenapa saran ini dibuat" (opsional, audit),
     *     "action":   "buka_kasir" (opsional; hanya type balasan/peringatan),
     *     "expires_at": "2026-10-13T17:00:00+07:00" (opsional; default now+30m)
     *   }
     *
     * Aturan (lihat requirements AC-1..AC-7):
     * - conversation_id WAJIB bilangan bulat positif yang ADA di conversations.
     * - type & priority WAJIB salah satu nilai yang diizinkan.
     * - type='daftar-periksa' WAJIB membawa `items` array string non-kosong.
     * - maks 1 saran 'active' per tipe per percakapan: saran bertipe sama
     *   yang lama di-supersede; supersede + insert dalam SATU transaksi.
     * - expires_at hilang -> default now+30 menit (Asia/Jakarta).
     * - Semua validasi DIJALANKAN PENUH sebelum insert; body ditolak utuh,
     *   tidak pernah menyimpan baris setengah jadi.
     *
     * Balasan sukses: {"status":"ok","data":{"id":<int>}}.
     */
    public function suggestions()
    {
        $body = $this->request->getJSON(true);

        if (!is_array($body)) {
            return $this->suggestionError(400, 'Body JSON tidak valid.');
        }

        // --- conversation_id ---------------------------------------------
        $conversationId = isset($body['conversation_id']) && is_numeric($body['conversation_id'])
            ? (int) $body['conversation_id']
            : 0;

        if ($conversationId <= 0) {
            return $this->suggestionError(400, 'conversation_id wajib bilangan bulat positif.');
        }

        // --- type ---------------------------------------------------------
        $type = is_string($body['type'] ?? null) ? trim($body['type']) : '';
        if (!in_array($type, self::SUGGESTION_TYPES, true)) {
            return $this->suggestionError(400, "type harus salah satu dari: " . implode(', ', self::SUGGESTION_TYPES) . '.');
        }

        // --- priority -----------------------------------------------------
        // Default 'normal' bila M.A.R.A tidak mengirimkannya (aman: bukan
        // data kritis, dan spek menyebut normal sebagai tingkat dasar).
        $priority = is_string($body['priority'] ?? null) ? trim($body['priority']) : 'normal';
        if (!in_array($priority, self::SUGGESTION_PRIORITIES, true)) {
            return $this->suggestionError(400, "priority harus salah satu dari: " . implode(', ', self::SUGGESTION_PRIORITIES) . '.');
        }

        // --- text ---------------------------------------------------------
        $text = $body['text'] ?? null;
        if ($text !== null && !is_string($text)) {
            return $this->suggestionError(400, 'text harus berupa string.');
        }
        $text = $text === null ? null : trim($text);
        if ($text !== null && strlen($text) > self::SUGGESTION_TEXT_MAX) {
            return $this->suggestionError(400, 'text terlalu panjang (maksimal ' . self::SUGGESTION_TEXT_MAX . ' karakter).');
        }

        // --- items (khusus daftar-periksa) --------------------------------
        $items = $body['items'] ?? null;
        $itemsJson = null;

        if ($type === 'daftar-periksa') {
            if (!is_array($items) || $items === []) {
                return $this->suggestionError(400, "items wajib berupa array string non-kosong untuk type='daftar-periksa'.");
            }

            foreach ($items as $item) {
                if (!is_string($item) || trim($item) === '') {
                    return $this->suggestionError(400, 'items harus berisi string tidak kosong.');
                }
                if (strlen($item) > self::SUGGESTION_ITEM_MAX) {
                    return $this->suggestionError(400, 'item terlalu panjang (maksimal ' . self::SUGGESTION_ITEM_MAX . ' karakter).');
                }
            }

            if (count($items) > self::SUGGESTION_ITEMS_MAX) {
                return $this->suggestionError(400, 'terlalu banyak item (maksimal ' . self::SUGGESTION_ITEMS_MAX . ').');
            }

            $itemsJson = json_encode(array_values(array_map('strval', $items)), JSON_UNESCAPED_UNICODE);
        } else {
            // Untuk tipe lain `items` diabaikan -- tidak disimpan (hanya
            // $itemsJson yang dipakai insert, dan itu tetap null di sini), dan
            // tidak ada bagian lain yang membaca $items lagi.
        }

        // Sebuah saran harus punya KONTEN: teks ATAU items. Daftar-periksa
        // sudah dijamin punya items di atas; balasan/peringatan butuh teks.
        if ($type !== 'daftar-periksa' && ($text === null || $text === '')) {
            return $this->suggestionError(400, "text wajib diisi untuk type='{$type}'.");
        }

        // --- reason (opsional, audit) -------------------------------------
        $reason = $body['reason'] ?? null;
        if ($reason !== null && !is_string($reason)) {
            return $this->suggestionError(400, 'reason harus berupa string.');
        }
        $reason = $reason === null ? null : trim($reason);

        // --- action (opsional) ---------------------------------------------
        // null / '' = tanpa aksi. Selain itu harus ada di whitelist dan hanya
        // untuk balasan/peringatan; ditolak utuh bila tidak.
        $action = $body['action'] ?? null;
        if ($action === '') {
            $action = null;
        }
        if ($action !== null) {
            if (!is_string($action) || !in_array($action, self::SUGGESTION_ACTIONS, true)) {
                return $this->suggestionError(400, 'action harus salah satu dari: ' . implode(', ', self::SUGGESTION_ACTIONS) . '.');
            }
            if (!in_array($type, self::SUGGESTION_ACTION_TYPES, true)) {
                return $this->suggestionError(400, 'action hanya berlaku untuk type: ' . implode(', ', self::SUGGESTION_ACTION_TYPES) . '.');
            }
        }

        // --- cart_items (opsional) ------------------------------------------
        // Hanya {produk_id, jumlah} yang diterima; harga/nama/satuan dari
        // M.A.R.A DIABAIKAN (POS membacanya sendiri dari `produk`). Wajib
        // bersama action='buka_kasir' (daftar tanpa tombol tidak berguna).
        // Kosong / tidak dikirim = tanpa keranjang.
        $cartItemsJson = null;
        if (array_key_exists('cart_items', $body) && $body['cart_items'] !== null) {
            $cartItems = $this->normalizeCartItems($body['cart_items']);
            if (is_string($cartItems)) {
                return $this->suggestionError(400, $cartItems);
            }
            if ($cartItems !== []) {
                if ($action !== 'buka_kasir') {
                    return $this->suggestionError(400, "cart_items hanya berlaku bersama action='buka_kasir'.");
                }
                $cartItemsJson = json_encode($cartItems, JSON_UNESCAPED_UNICODE);
            }
        }

        // --- conversation_id harus ADA di conversations -------------------
        $conversationModel = new ConversationModel();
        if ($conversationModel->find($conversationId) === null) {
            return $this->suggestionError(404, 'Conversation tidak ditemukan.');
        }

        // --- expires_at ---------------------------------------------------
        $now = MaraSuggestionModel::nowWib();

        if (array_key_exists('expires_at', $body) && $body['expires_at'] !== null && $body['expires_at'] !== '') {
            if (!is_string($body['expires_at'])) {
                return $this->suggestionError(400, 'expires_at harus berupa string tanggal-time.');
            }

            $expiresAt = $this->parseExpiresAt($body['expires_at']);
            if ($expiresAt === null) {
                return $this->suggestionError(400, 'expires_at tidak bisa di-parse.');
            }
            if ($expiresAt <= $now) {
                return $this->suggestionError(400, 'expires_at harus di masa depan.');
            }
        } else {
            // Default now + 30 menit (AC-6): saran tidak pernah tersimpan
            // tanpa batas waktu.
            $expiresAt = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))
                ->modify('+' . self::SUGGESTION_DEFAULT_TTL_MINUTES . ' minutes')
                ->format('Y-m-d H:i:s');
        }

        // --- supersede (tipe sama) + insert dalam SATU transaksi ----------
        // Lock baris conversation dulu (AC-5): transaksi saja tidak cukup
        // untuk menjamin "maks 1 active per tipe" di bawah konkurensi --
        // lihat MaraSuggestionModel::lockConversation().
        $suggestionModel = new MaraSuggestionModel();
        $db = $suggestionModel->db;
        $db->transBegin();

        try {
            $suggestionModel->lockConversation($conversationId);
            $suggestionModel->supersedeSameType($conversationId, $type, $now);

            $row = [
                'conversation_id' => $conversationId,
                'type'            => $type,
                'priority'        => $priority,
                'text'            => $text === '' ? null : $text,
                'items'           => $itemsJson,
                'reason'          => $reason,
                'status'          => 'active',
                'expires_at'      => $expiresAt,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];

            // Kolom `action` hanya disertakan bila dikirim, supaya kode yang
            // sudah ter-deploy sebelum migrasi tetap bisa menyimpan saran biasa.
            if ($action !== null) {
                $row['action'] = $action;
            }

            // Sama seperti `action`: kolom baru hanya ditulis bila dikirim,
            // jadi kode yang ter-deploy sebelum migrasi tetap menyimpan saran biasa.
            if ($cartItemsJson !== null) {
                $row['cart_items'] = $cartItemsJson;
            }

            $suggestionModel->insert($row);

            $newId = (int) $suggestionModel->getInsertID();

            if ($db->transStatus() === false || $newId <= 0) {
                $db->transRollback();

                return $this->suggestionError(500, 'Gagal menyimpan saran.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            log_message('error', 'MaraController::suggestions() gagal menyimpan saran conversation_id=' . $conversationId . ': ' . $e->getMessage());

            return $this->suggestionError(500, 'Gagal menyimpan saran.');
        }

        return $this->response->setStatusCode(201)->setJSON([
            'status' => 'ok',
            'data'   => [
                'id' => $newId,
            ],
        ]);
    }

    /**
     * Validasi + normalisasi `cart_items`. Kembalian: array baris bersih
     * [{produk_id, jumlah}] (id kembar dijumlahkan), atau string pesan error.
     * Field lain (harga, nama, satuan) dibuang -- tidak pernah dipercaya.
     *
     * @param mixed $raw
     *
     * @return array<int, array{produk_id:int, jumlah:int}>|string
     */
    private function normalizeCartItems($raw)
    {
        if (!is_array($raw)) {
            return 'cart_items harus berupa array.';
        }
        if (count($raw) > self::CART_ITEMS_MAX) {
            return 'cart_items maksimal ' . self::CART_ITEMS_MAX . ' baris.';
        }

        $gabung = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                return 'cart_items harus berisi objek {produk_id, jumlah}.';
            }

            $id  = $row['produk_id'] ?? null;
            $qty = $row['jumlah'] ?? null;

            if (!is_int($id) || $id <= 0) {
                return 'cart_items.produk_id harus bilangan bulat > 0.';
            }
            if (!is_int($qty) || $qty < 1 || $qty > self::CART_QTY_MAX) {
                return 'cart_items.jumlah harus bilangan bulat 1-' . self::CART_QTY_MAX . '.';
            }

            $gabung[$id] = ($gabung[$id] ?? 0) + $qty;
            if ($gabung[$id] > self::CART_QTY_MAX) {
                return 'cart_items.jumlah gabungan melebihi ' . self::CART_QTY_MAX . '.';
            }
        }

        $hasil = [];
        foreach ($gabung as $id => $qty) {
            $hasil[] = ['produk_id' => (int) $id, 'jumlah' => $qty];
        }

        return $hasil;
    }

    /**
     * Bentuk balasan error yang konsisten untuk suggestions().
     */
    private function suggestionError(int $code, string $message)
    {
        return $this->response->setStatusCode($code)->setJSON([
            'status'  => 'error',
            'message' => $message,
        ]);
    }

    /**
     * Parse expires_at dari M.A.R.A (ISO-8601, mis. "2026-10-13T17:00:00+07:00")
     * -> DATETIME WIB 'Y-m-d H:i:s'. Null kalau tidak bisa di-parse.
     *
     * Timestamp apa pun dinormalkan ke zona Asia/Jakarta dulu (setTimezone)
     * supaya instant-nya benar -- BEDA dari parseDateBoundary() di file ini
     * yang TIDAK menormalkan zona (bug 7 jam pernah terjadi di
     * InboxGatewayApi::parseTimestamp() karena format() tanpa konversi).
     * Karena M.A.R.A mengirim +07:00 (offset), normalisasi ini WAJIB di sini
     * supaya perbandingan expires_at > now (WIB) konsisten.
     */
    private function parseExpiresAt(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $dt = new \DateTime($value);
        } catch (\Throwable $e) {
            return null;
        }

        $dt->setTimezone(new \DateTimeZone('Asia/Jakarta'));

        return $dt->format('Y-m-d H:i:s');
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
<?php

namespace App\Controllers;

use App\Models\ConversationModel;
use App\Models\ConversationHandoffModel;
use App\Models\MessageModel;
use App\Models\GatewayStatusModel;
use App\Models\UserModel;
use App\Libraries\PhoneNumber;
use App\Libraries\InboxMediaStorage;
use App\Services\InboxSlaService;
use Config\Inbox as InboxConfig;

/**
 * Controller module Shared WhatsApp Inbox untuk sisi browser POS
 * (session-authenticated, dipakai kasir/admin) -- BEDA dari
 * InboxGatewayApi.php yang khusus machine-to-machine dengan Bearer
 * token.
 *
 * Phase 3: outgoing text message (kirim balasan dari POS). Phase 4
 * (UI daftar conversation, dsb) akan menambah method lain di
 * controller ini.
 */
class Inbox extends BaseController
{
    /** Nilai sah parameter `status` di GET /inbox/api/conversations (CL-002). */
    private const QUEUE_STATUSES = ['belum_diambil', 'open', 'menunggu', 'ditunda', 'selesai'];

    /** Ukuran halaman GET /inbox/api/conversations (CL-010). */
    private const CONVERSATIONS_PER_PAGE = 50;

    /**
     * Kolom yang dicocokkan parameter `q` (REQ-013, CL-015): semua nama/nomor
     * yang bisa tampil di daftar. Dicek per kolom, tidak pernah digabung.
     */
    private const SEARCH_COLUMNS = ['contact_name', 'whatsapp_name', 'phone', 'manual_phone', 'chat_id'];

    /**
     * GET /inbox
     *
     * UI Inbox utama (Phase 4): daftar conversation + riwayat pesan +
     * form kirim + status Gateway, dengan polling sederhana (bukan
     * WebSocket, sesuai spec). Data awal (conversation list + status
     * Gateway) dirender langsung dari server untuk first-paint cepat;
     * update berikutnya lewat AJAX ke apiConversations()/
     * apiMessages()/apiGatewayStatus() di bawah.
     */
    public function index()
    {
        $conversationModel = new ConversationModel();
        $conversations = $this->attachResponseState($this->attachAssignedNames($conversationModel->orderBy('last_message_at', 'DESC')->findAll()));

        $gatewayStatusModel = new GatewayStatusModel();
        $gatewayStatus = $this->buildGatewayStatusPayload($gatewayStatusModel);

        $data = [
            'title'          => 'Inbox WhatsApp | AULIA',
            'content'        => 'inbox/index',
            'conversations'  => $conversations,
            'gatewayStatus'  => $gatewayStatus,
            'currentUserId'  => (int) session()->get('id_user'),
            'currentUserRole' => (string) session()->get('role'),
            // M3 Fase 2a (TB-01/TASK-004): daftar target dialog Handoff.
            // Sumber sama dengan validasi server (UserModel::daftarKasirAktif,
            // Q6) -- server tetap 403 kalau dropdown basi (RISK-05).
            'daftarKasir'    => (new UserModel())->daftarKasirAktif(),
        ];

        return view('layout/minimal', $data);
    }

    /**
     * GET /inbox/api/conversations
     *
     * Daftar conversation dalam JSON, dipakai polling berkala oleh
     * halaman index() untuk memperbarui daftar (mis. ada conversation
     * baru masuk, atau last_message_at berubah).
     *
     * Spec M3 4.4: parameter divalidasi dulu (400) sebelum query;
     * filter status/q tetap filter-after-fetch atas seluruh dataset,
     * baru hasilnya dipotong 50 per halaman (CL-010) -- paging tidak
     * membatasi dataset yang dicari.
     *
     * `q` cocok bila terkandung (tanpa beda huruf besar/kecil, tanpa
     * normalisasi nomor) di minimal satu dari contact_name, whatsapp_name,
     * phone, manual_phone, chat_id (Fase 1d, REQ-013).
     */
    public function apiConversations()
    {
        $status = trim((string) ($this->request->getGet('status') ?? ''));
        $q = trim((string) ($this->request->getGet('q') ?? ''));
        $pageParam = $this->request->getGet('page');

        if ($status !== '' && !in_array($status, self::QUEUE_STATUSES, true)) {
            return $this->badRequest('Parameter status tidak valid.');
        }

        if (mb_strlen($q) > 255) {
            return $this->badRequest('Kata pencarian maksimal 255 karakter.');
        }

        // Hanya digit dan tanpa nol di depan: menolak 0, negatif, desimal,
        // teks, dan string kosong (CL-012). Absen = halaman 1 (CL-011).
        if ($pageParam !== null && !(is_string($pageParam) && preg_match('/^[1-9]\d{0,8}$/', $pageParam))) {
            return $this->badRequest('Parameter page harus bilangan bulat mulai dari 1.');
        }
        $page = $pageParam === null ? 1 : (int) $pageParam;

        $conversationModel = new ConversationModel();
        $conversations = $conversationModel
            ->orderBy('last_message_at', 'DESC')
            ->findAll();

        $conversations = $this->attachResponseState($this->attachAssignedNames($conversations));

        $slaService = new InboxSlaService();
        foreach ($conversations as &$conversation) {
            $conversation['sla_color'] = $slaService->hitung(
                $conversation['last_message_at'] ?? null,
                $conversation['queue_status'] ?? 'selesai'
            );
        }
        unset($conversation);

        if ($status !== '') {
            $conversations = array_values(array_filter(
                $conversations,
                static fn(array $conversation): bool => ($conversation['queue_status'] ?? null) === $status
            ));
        }

        if ($q !== '') {
            $conversations = array_values(array_filter(
                $conversations,
                static function (array $conversation) use ($q): bool {
                    foreach (self::SEARCH_COLUMNS as $column) {
                        if (mb_stripos((string) ($conversation[$column] ?? ''), $q) !== false) {
                            return true;
                        }
                    }

                    return false;
                }
            ));
        }

        // Halaman di luar data terakhir = [] (CL-013), bukan 404/400.
        $conversations = array_slice($conversations, ($page - 1) * self::CONVERSATIONS_PER_PAGE, self::CONVERSATIONS_PER_PAGE);

        return $this->response->setJSON([
            'status'        => 'success',
            'conversations' => $conversations,
        ]);
    }

    private function badRequest(string $message)
    {
        return $this->response->setStatusCode(400)->setJSON([
            'status'  => 'error',
            'message' => $message,
        ]);
    }

    /**
     * GET /inbox/api/conversations/(:num)/messages
     *
     * Riwayat pesan 1 conversation dalam JSON, dipakai polling
     * berkala saat kasir sedang membuka satu conversation, supaya
     * pesan masuk baru muncul tanpa refresh manual penuh (sesuai
     * spec bagian REALTIME).
     */
    public function apiMessages($conversationId = null)
    {
        $conversationId = (int) $conversationId;

        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        $messageModel = new MessageModel();
        $messages = $this->attachSenderNames($messageModel->getByConversation($conversationId, 500));

        foreach ($messages as &$message) {
            $message['is_internal'] = (bool) ($message['is_internal'] ?? false);
        }
        unset($message);

        return $this->response->setJSON([
            'status'       => 'success',
            'conversation' => $this->attachResponseState($this->attachAssignedNames([$conversation]))[0],
            'messages'     => $messages,
        ]);
    }

    /**
     * GET /inbox/api/perlu-dibalas-count
     *
     * Hitung ringan jumlah conversation 'perlu_dibalas', dipakai badge
     * sidebar lintas halaman (bukan cuma /inbox). Reuse
     * attachResponseState() supaya tidak ada logic kedua (WHERE SQL
     * terpisah) yang bisa drift dari yang dipakai index()/apiConversations().
     */
    public function apiPerluDibalasCount()
    {
        $conversationModel = new ConversationModel();
        $conversations = $conversationModel
            ->select('status, last_message_direction, last_message_at, last_seen_by_assignee_at, snoozed_until')
            ->where('status', 'open')
            ->findAll();

        $conversations = $this->attachResponseState($conversations);
        $count = count(array_filter($conversations, fn($c) => $c['response_state'] === 'perlu_dibalas'));

        return $this->response->setJSON(['status' => 'success', 'count' => $count]);
    }

    /**
     * GET /inbox/api/gateway-status
     *
     * Status Gateway dalam JSON, dipakai polling berkala untuk badge
     * status (Terhubung/Menghubungkan/Terputus) di header halaman
     * Inbox.
     */
    public function apiGatewayStatus()
    {
        $gatewayStatusModel = new GatewayStatusModel();

        return $this->response->setJSON([
            'status'  => 'success',
            'gateway' => $this->buildGatewayStatusPayload($gatewayStatusModel),
        ]);
    }

    /**
     * GET /inbox/media/(:num)
     *
     * Streaming file gambar/dokumen ON-DEMAND ke browser kasir.
     *
     * PENTING (sesuai keputusan desain): file media TIDAK PERNAH
     * disimpan permanen di server AuliaPos -- yang tersimpan di
     * `messages.media_metadata` cuma REFERENSI (direct_path +
     * media_key). Method ini minta Gateway mengambil & mendekripsi
     * file itu dari server WhatsApp SAAT INI JUGA, lalu langsung
     * di-stream ke browser tanpa disimpan ke disk di CI4 juga.
     *
     * Konsekuensinya: kalau media sudah "basi"/kadaluarsa di server
     * WhatsApp (bisa terjadi untuk pesan yang cukup lama), method ini
     * akan gagal dengan pesan jelas -- itu keterbatasan yang disadari
     * sejak awal, bukan bug.
     */
    public function media($messageId = null)
    {
        $messageId = (int) $messageId;

        $messageModel = new MessageModel();
        $message = $messageModel->find($messageId);

        if (!$message) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Pesan tidak ditemukan.',
            ]);
        }

        if (!in_array($message['message_type'], ['image', 'document', 'sticker'], true) || empty($message['media_metadata'])) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Pesan ini bukan gambar/dokumen/sticker, atau referensi media-nya tidak ada.',
            ]);
        }

        // 'private'/'immutable' HARUS jadi elemen array tanpa key (bukan
        // 'private' => true) -- Header::getValueLine() cuma menghasilkan
        // "key=value" untuk elemen ber-key string, elemen tanpa key
        // (numeric-indexed) ditulis apa adanya tanpa "=".
        $etag = '"inbox-media-' . $messageId . '"';
        $cacheOptions = ['private', 'immutable', 'max-age' => 604800, 'etag' => $etag];

        // Tahap C: cek disk lokal/HDD eksternal dulu SEBELUM live-fetch
        // ke Gateway (lihat InboxGatewayApi::messages(), yang prefetch
        // media ini saat pesan masuk). ETag tetap dicek dulu di sini
        // juga -- kalau match, 304 seperti biasa tanpa perlu baca file
        // dari disk sama sekali.
        if (!empty($message['media_local_filename'])) {
            $storage = new InboxMediaStorage((new InboxConfig())->mediaStoragePath);
            $binary = $storage->read($message['media_local_filename']);

            if ($binary !== null) {
                if ($this->request->getHeaderLine('If-None-Match') === $etag) {
                    return $this->response->setStatusCode(304)->setCache($cacheOptions);
                }

                return $this->response
                    ->setStatusCode(200)
                    ->setContentType($message['media_mime_type'] ?: 'application/octet-stream')
                    ->setHeader('Content-Disposition', ($message['message_type'] === 'document' ? 'attachment' : 'inline') . '; filename="' . addslashes($message['media_filename'] ?: 'media-' . $messageId) . '"')
                    ->setCache($cacheOptions)
                    ->setBody($binary);
            }
            // $binary === null: file HARUSNYA ada (filename tercatat)
            // tapi tidak terbaca -- HDD eksternal sedang tercabut.
            // JANGAN error, lanjut ke blok fallback live-fetch di bawah
            // seperti biasa.
        }

        if (empty($message['media_local_filename']) && !empty($message['media_confirmed_gone_at'])) {
            // Tahap E -- sudah pernah dipastikan gagal PERMANEN (410 dari
            // WhatsApp) -- jangan hubungi Gateway lagi sama sekali, balas
            // cepat. Beda dari media_download_attempted_at (Tahap C) yang
            // bisa gagal sementara dan masih layak dicoba ulang -- kolom
            // ini SENGAJA hanya diisi untuk 410 yang pasti final.
            return $this->response->setStatusCode(410)->setJSON([
                'status'  => 'error',
                'message' => 'Media sudah tidak tersedia (kedaluwarsa).',
            ]);
        }

        // Media per message_id TIDAK PERNAH berubah setelah pesan
        // tersimpan (tidak ada jalur kode yang UPDATE media_metadata) --
        // aman di-cache lama oleh browser via ETag berbasis ID saja.
        // Kalau browser masih menyimpan ETag ini (baik dari cache aktif
        // maupun revalidation setelah cache kedaluwarsa), balas 304
        // SEBELUM memanggil Gateway sama sekali -- mencegah polling
        // Inbox (setiap 4 detik, me-render ulang SELURUH thread) memicu
        // Gateway download+decrypt ulang media yang sudah pernah diambil.
        if ($this->request->getHeaderLine('If-None-Match') === $etag) {
            return $this->response
                ->setStatusCode(304)
                ->setCache($cacheOptions);
        }

        $mediaRef = json_decode($message['media_metadata'], true);

        if (!is_array($mediaRef) || empty($mediaRef['direct_path']) || empty($mediaRef['media_key_base64'])) {
            return $this->response->setStatusCode(500)->setJSON([
                'status'  => 'error',
                'message' => 'Referensi media rusak/tidak lengkap.',
            ]);
        }

        $config = new InboxConfig();

        if ($config->gatewayBaseUrl === '') {
            return $this->response->setStatusCode(503)->setJSON([
                'status'  => 'error',
                'message' => 'Gateway belum dikonfigurasi di server.',
            ]);
        }

        $result = $this->callGatewayMediaDownload($config, $mediaRef, $message['media_mime_type']);

        if (!$result['ok']) {
            // 410 Gone paling umum: media sudah kadaluarsa di server
            // WhatsApp. Dikembalikan sebagai JSON (bukan gambar rusak)
            // supaya UI bisa menampilkan pesan yang jelas, bukan ikon
            // "broken image" generik browser.
            //
            // Tahap E -- 410 SPESIFIK dicatat sebagai final (beda dari
            // timeout/502/dst yang tetap boleh dicoba lagi nanti) supaya
            // request berikutnya ke pesan ini short-circuit di blok
            // media_confirmed_gone_at di atas, tidak menghubungi Gateway
            // lagi sama sekali.
            if ((int) $result['status'] === 410) {
                $messageModel->update($messageId, [
                    'media_confirmed_gone_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return $this->response->setStatusCode($result['status'] ?: 502)->setJSON([
                'status'  => 'error',
                'message' => $result['error'],
            ]);
        }

        $filename = $message['media_filename'] ?: ('media-' . $messageId);

        return $this->response
            ->setStatusCode(200)
            ->setContentType($message['media_mime_type'] ?: 'application/octet-stream')
            ->setHeader('Content-Disposition', ($message['message_type'] === 'document' ? 'attachment' : 'inline') . '; filename="' . addslashes($filename) . '"')
            ->setCache($cacheOptions)
            ->setBody($result['binary']);
    }

    /**
     * Panggil POST /media/download milik Gateway -- minta Gateway
     * ambil+dekripsi 1 file media dari server WhatsApp berdasarkan
     * referensi yang tersimpan, lalu kembalikan isinya (binary) di
     * sini. TIDAK ADA file yang disimpan ke disk CI4 di titik manapun.
     *
     * $timeoutSeconds default 30 (live-fetch on-demand dari
     * Inbox::media()) -- InboxGatewayApi::messages() memanggil dengan
     * timeout lebih pendek (8 detik) untuk prefetch saat pesan masuk,
     * supaya tidak menahan respons webhook Gateway lama-lama kalau
     * memang lambat.
     *
     * Visibility public (bukan private) supaya bisa dipanggil dari
     * InboxGatewayApi::messages() lewat instance Inbox -- method ini
     * TIDAK bergantung pada state/session controller, aman dipanggil
     * lintas controller.
     *
     * @return array{ok: bool, binary?: string, status?: int, error?: string}
     */
    public function callGatewayMediaDownload(InboxConfig $config, array $mediaRef, ?string $mimetype, int $timeoutSeconds = 30): array
    {
        $url = $config->gatewayBaseUrl . '/media/download';

        $payload = json_encode([
            'media_type'        => $mediaRef['media_type'] ?? null,
            'direct_path'       => $mediaRef['direct_path'] ?? null,
            'media_key_base64'  => $mediaRef['media_key_base64'] ?? null,
            'mimetype'          => $mimetype,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config->gatewayToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            // Lebih lama dari kirim teks -- unduh+dekripsi file butuh
            // waktu lebih, terutama untuk dokumen berukuran besar.
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($rawResponse === false) {
            return ['ok' => false, 'status' => 502, 'error' => 'Tidak bisa menghubungi Gateway: ' . $curlError];
        }

        // Gateway balas binary langsung kalau sukses (Content-Type
        // sesuai mimetype file), atau JSON {success:false,...} kalau
        // gagal (mis. media kadaluarsa). Bedakan lewat Content-Type
        // response, bukan cuma HTTP status, supaya tidak salah
        // interpretasi binary sebagai JSON atau sebaliknya.
        $isJsonError = $httpCode >= 400 || str_contains((string) $contentType, 'application/json');

        if ($isJsonError) {
            $json = json_decode($rawResponse, true);
            $message = is_array($json) ? ($json['message'] ?? 'Gateway menolak permintaan media.') : ('HTTP ' . $httpCode);

            return ['ok' => false, 'status' => $httpCode ?: 502, 'error' => $message];
        }

        return ['ok' => true, 'binary' => $rawResponse];
    }

    /**
     * Susun payload status Gateway yang siap ditampilkan UI --
     * termasuk "effective_status": kalau baris DB bilang 'connected'
     * tapi heartbeat sudah basi (lihat
     * GatewayStatusModel::isUsable()), effective_status diturunkan
     * jadi 'disconnected' supaya UI tidak menampilkan status
     * "Terhubung" yang menyesatkan.
     */
    private function buildGatewayStatusPayload(GatewayStatusModel $model): array
    {
        $status = $model->getStatus();
        $usable = $model->isUsable();

        $rawStatus = $status['status'] ?? 'disconnected';
        $effectiveStatus = ($rawStatus === 'connected' && !$usable) ? 'disconnected' : $rawStatus;

        return [
            'raw_status'        => $rawStatus,
            'effective_status'  => $effectiveStatus,
            'phone'             => $status['phone'] ?? null,
            'last_heartbeat_at' => $status['last_heartbeat_at'] ?? null,
            'usable'            => $usable,
        ];
    }

    /**
     * Lengkapi setiap message dengan 'sender_name' (nama/username
     * dari aulia_kasirdb.users, koneksi DEFAULT -- bukan koneksi
     * inbox). Ini contoh nyata pola "logical reference" yang
     * didokumentasikan di ConversationModel/MessageModel: gabungkan
     * di PHP, bukan lewat JOIN SQL (tidak mungkin, beda database).
     */
    private function attachSenderNames(array $messages): array
    {
        $userIds = array_values(array_unique(array_filter(array_column($messages, 'sent_by_user_id'))));

        $namesByUserId = [];
        if ($userIds) {
            $users = (new UserModel())->whereIn('id', $userIds)->findAll();
            foreach ($users as $user) {
                $namesByUserId[$user['id']] = $user['nama'] ?: $user['username'];
            }
        }

        foreach ($messages as &$message) {
            if ($message['sent_by_user_id']) {
                $message['sender_name'] = $namesByUserId[$message['sent_by_user_id']] ?? ('User #' . $message['sent_by_user_id']);
            } elseif ($message['direction'] === 'outgoing') {
                // Balasan outgoing tanpa sent_by_user_id berarti dikirim
                // LANGSUNG dari WA Web/HP (di luar POS) -- Gateway tidak
                // tahu staff mana yang mengirim. Diberi label jelas supaya
                // kasir lain yang lihat Inbox tidak mengira ini dikirim
                // dari POS oleh seseorang yang tidak disebutkan namanya.
                $message['sender_name'] = 'Staff (WA Web/HP)';
            } else {
                $message['sender_name'] = null;
            }
        }

        return $messages;
    }

    /**
     * Lengkapi setiap conversation dengan 'assigned_to_name' (nama
     * staff yang sedang menangani, dari aulia_kasirdb.users -- lihat
     * catatan pola "logical reference" yang sama di
     * attachSenderNames()). null kalau belum ada yang menangani.
     */
    private function attachAssignedNames(array $conversations): array
    {
        $userIds = array_values(array_unique(array_filter(array_column($conversations, 'assigned_to'))));

        $namesByUserId = [];
        if ($userIds) {
            $users = (new UserModel())->whereIn('id', $userIds)->findAll();
            foreach ($users as $user) {
                $namesByUserId[$user['id']] = $user['nama'] ?: $user['username'];
            }
        }

        foreach ($conversations as &$conversation) {
            $conversation['assigned_to_name'] = $conversation['assigned_to']
                ? ($namesByUserId[$conversation['assigned_to']] ?? ('User #' . $conversation['assigned_to']))
                : null;
        }

        return $conversations;
    }

    /**
     * Hitung Response State per conversation -- COMPUTED, tidak pernah
     * disimpan sebagai kolom fisik (lihat review Section 5: kalau jadi
     * kolom yang ditulis manual di banyak endpoint, risiko "kebalik"/lupa
     * update jauh lebih tinggi). Satu-satunya tempat logic ini boleh ada.
     *
     * @return array Conversation dengan tambahan key 'response_state':
     *   'selesai' | 'follow_up' | 'perlu_dibalas' | 'menunggu_customer'
     */
    private function attachResponseState(array $conversations): array
    {
        return (new ConversationModel())->withComputedStatus($conversations);
    }

    /**
     * Cek apakah user yang sedang login boleh membalas/mengirim
     * media/menghapus sebuah conversation -- SATU-SATUNYA aturan
     * pembatasan yang ditambahkan sekarang bahwa assignment sudah ada
     * (sebelumnya sengaja tidak ada pembatasan apa pun, lihat catatan
     * lama di kirim()/hapusPercakapan()).
     *
     * Aturan: boleh kalau conversation belum ditangani siapa pun
     * (assigned_to NULL), ATAU ditangani oleh user ini sendiri, ATAU
     * user ini admin (admin selalu boleh, untuk supervisi/override).
     *
     * @return string|null Pesan error kalau DITOLAK, null kalau BOLEH.
     */
    private function cekOwnership(array $conversation, int $userId, string $role): ?string
    {
        $assignedTo = $conversation['assigned_to'] ? (int) $conversation['assigned_to'] : null;

        if ($assignedTo === null || $assignedTo === $userId || $role === 'admin') {
            return null;
        }

        $penangan = (new UserModel())->find($assignedTo);
        $namaPenangan = $penangan ? ($penangan['nama'] ?: $penangan['username']) : ('User #' . $assignedTo);

        return "Percakapan ini sedang ditangani oleh {$namaPenangan}. Hanya {$namaPenangan} atau admin yang bisa membalas/menghapusnya.";
    }

    /**
     * GET /inbox/test
     *
     * Halaman TEST SEMENTARA -- bukan UI Inbox final (itu Phase 4).
     * Tujuannya cuma supaya Phase 3 (outgoing) bisa diverifikasi
     * lewat browser (pakai session yang sudah login otomatis),
     * tanpa perlu curl manual + copy cookie session.
     *
     * Menampilkan daftar conversation apa adanya (tanpa styling
     * bagus, tanpa polling, tanpa riwayat pesan) + form kirim text
     * yang manggil endpoint kirim() di atas lewat AJAX.
     *
     * DIBIARKAN TETAP ADA setelah Phase 4 selesai (tidak
     * mengganggu), tapi sudah tidak diperlukan lagi -- silakan hapus
     * kalau mau, halaman utama sekarang ada di index() / GET /inbox.
     */
    public function testPage()
    {
        $conversationModel = new ConversationModel();
        $conversations = $conversationModel->orderBy('last_message_at', 'DESC')->findAll(50);

        $data = [
            'title'         => 'Test Inbox (Sementara) | AULIA',
            'content'       => 'inbox/test',
            'conversations' => $conversations,
        ];

        return view('layout/main', $data);
    }

    /**
     * POST /inbox/mulai-percakapan
     *
     * Fitur baru: mulai chat ke NOMOR yang belum pernah masuk sama
     * sekali (belum ada baris conversation-nya). Sebelum fitur ini,
     * kasir cuma bisa MEMBALAS conversation yang sudah ada -- tidak
     * bisa memulai kontak duluan ke nomor customer.
     *
     * Kalau nomor itu SUDAH punya conversation (customer ini pernah
     * chat sebelumnya), pesan otomatis masuk ke conversation yang
     * sudah ada tsb (bukan bikin duplikat).
     */
    public function mulaiPercakapan()
    {
        $phoneRaw = trim((string) ($this->request->getPost('phone') ?? ''));
        $text     = trim((string) ($this->request->getPost('text') ?? ''));

        if ($phoneRaw === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Nomor telepon wajib diisi.',
            ]);
        }

        if ($text === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Teks pesan tidak boleh kosong.',
            ]);
        }

        if (strlen($text) > 4096) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Teks pesan terlalu panjang (maksimal 4096 karakter).',
            ]);
        }

        $normalized = $this->normalizePhoneToJid($phoneRaw);

        if ($normalized === null) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Format nomor telepon tidak dikenali. Gunakan format 08xx, 62xx, atau +62xx.',
            ]);
        }

        [$chatId, $phoneClean] = $normalized;

        // resolveConversationId() (Task Group 1.5) -- chat_id di sini
        // SELALU jid_type='pn' (dibentuk langsung dari nomor yang
        // diketik kasir), jadi $phoneClean aman dipakai sebagai
        // canonicalPhone: kalau nomor ini SUDAH pernah dikenal sebagai
        // `phone` ter-verifikasi milik conversation lain (mis. yang
        // tadinya cuma dikenal lewat @lid, lalu nomornya terbukti sama
        // persis ini), kasir langsung diarahkan ke conversation yang
        // SAMA -- bukan bikin duplikat baru.
        //
        // $allowManualPhoneMatch=true SENGAJA hanya di sini (bukan di
        // InboxGatewayApi::messages()) -- "+ Chat Baru" adalah tindakan
        // SADAR kasir mengetik nomor tujuan sendiri, jadi aman juga
        // mencocokkan ke conversation yang nomornya baru tersimpan di
        // `manual_phone` (mis. dari fitur Edit Profil biasa, belum
        // pernah dikonfirmasi/terverifikasi via WhatsApp) -- mencegah
        // duplicate persis kasus "AAN XL 2" (nomor cuma di manual_phone).
        $conversationModel = new ConversationModel();
        $resolved = $conversationModel->resolveConversationId($chatId, 'pn', $phoneClean, null, null, true);
        $conversation = $conversationModel->find($resolved['conversation_id']);

        return $this->kirimKeConversation($conversation, $text);
    }

    /**
     * POST /inbox/kirim
     *
     * Kasir/admin kirim balasan text ke satu conversation YANG SUDAH
     * ADA. Untuk mulai chat ke nomor baru, lihat mulaiPercakapan().
     *
     * Ownership: boleh dibalas siapa saja SELAMA belum ada yang
     * menangani (assigned_to NULL, auto-assign ke pengirim pertama),
     * ditangani oleh user ini sendiri, atau oleh admin -- lihat
     * cekOwnership().
     */
    public function kirim()
    {
        $conversationId = (int) ($this->request->getPost('conversation_id') ?? 0);
        $text           = trim((string) ($this->request->getPost('text') ?? ''));

        if (!$conversationId) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'conversation_id wajib diisi.',
            ]);
        }

        if ($text === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Teks pesan tidak boleh kosong.',
            ]);
        }

        if (strlen($text) > 4096) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Teks pesan terlalu panjang (maksimal 4096 karakter).',
            ]);
        }

        $conversationModel = new ConversationModel();
        $conversation       = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        return $this->kirimKeConversation($conversation, $text);
    }

    /**
     * POST /inbox/kirim-media
     *
     * Kasir/admin kirim gambar/dokumen dari POS ke satu conversation
     * YANG SUDAH ADA (belum ada versi "mulai chat baru" untuk media --
     * kalau perlu, ketik teks dulu lewat mulaiPercakapan(), baru
     * lanjut kirim media ke conversation yang sudah terbentuk).
     *
     * PENTING (konsisten dengan prinsip media MASUK): file yang
     * di-upload di sini TIDAK PERNAH ditulis ke disk CI4 -- hanya
     * dipegang di memory (dibaca isinya, di-base64-encode, dikirim ke
     * Gateway), lalu dibuang begitu request selesai. Kalau Gateway
     * mengembalikan referensi WhatsApp untuk file yang baru diunggah
     * (media_ref -- direct_path + media_key, sama seperti media
     * MASUK), referensi itu yang disimpan ke media_metadata supaya
     * media ini bisa dibuka ulang lewat GET /inbox/media/(:num) yang
     * sudah ada, TANPA endpoint/logic baru untuk itu. Kalau Gateway
     * tidak mengembalikan referensi (mediaRef null), pesan tetap
     * tersimpan (sudah terlanjur terkirim ke WhatsApp), hanya saja
     * tidak bisa dibuka ulang nanti dari Inbox.
     */
    public function kirimMedia()
    {
        $conversationId = (int) ($this->request->getPost('conversation_id') ?? 0);
        $caption        = trim((string) ($this->request->getPost('caption') ?? ''));
        $file           = $this->request->getFile('media');

        if (!$conversationId) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'conversation_id wajib diisi.',
            ]);
        }

        if (!$file || !$file->isValid()) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'File media wajib diupload.',
            ]);
        }

        if ($caption !== '' && strlen($caption) > 1024) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Caption terlalu panjang (maksimal 1024 karakter).',
            ]);
        }

        $config = new InboxConfig();
        $maxBytes = $config->maxMediaUploadMb * 1024 * 1024;

        if ($file->getSize() > $maxBytes) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => "Ukuran file melebihi batas maksimum ({$config->maxMediaUploadMb}MB).",
            ]);
        }

        $conversationModel = new ConversationModel();
        $conversation       = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        $ownershipError = $this->cekOwnership($conversation, (int) session()->get('id_user'), (string) session()->get('role'));
        if ($ownershipError) {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => $ownershipError,
            ]);
        }

        $gatewayStatusModel = new GatewayStatusModel();

        if (!$gatewayStatusModel->isUsable()) {
            return $this->response->setStatusCode(503)->setJSON([
                'status'  => 'error',
                'message' => 'Gateway WhatsApp sedang tidak terhubung. Coba lagi setelah Gateway online.',
            ]);
        }

        if ($config->gatewayBaseUrl === '') {
            log_message('critical', 'Inbox::kirimMedia -- inbox.gatewayBaseUrl belum dikonfigurasi di .env.');

            return $this->response->setStatusCode(503)->setJSON([
                'status'  => 'error',
                'message' => 'Gateway belum dikonfigurasi di server.',
            ]);
        }

        // Mimetype asli dari isi file (bukan dari nama/ekstensi, supaya
        // tidak mudah dikelabui) -- CodeIgniter sudah pakai fileinfo di
        // baliknya. image/webp dianggap 'sticker' (WhatsApp sticker
        // SELALU WebP -- foto kamera normal tidak pernah WebP, jadi
        // deteksi otomatis dari mimetype ini aman tanpa perlu tombol/
        // toggle terpisah di UI). image/* lain dianggap 'image', sisanya
        // 'document' -- konsisten dengan VALID_MEDIA_TYPES Gateway.
        $mimetype  = $file->getMimeType();
        $mediaType = $mimetype === 'image/webp'
            ? 'sticker'
            : (str_starts_with((string) $mimetype, 'image/') ? 'image' : 'document');
        $fileName  = $file->getClientName();
        $fileSize  = $file->getSize();

        // Sticker TIDAK PERNAH punya caption di protokol WhatsApp (sama
        // seperti sisi masuk -- lihat InboxGatewayApi::messages()) --
        // caption yang mungkin diisi kasir diabaikan, tidak dikirim ke
        // Gateway maupun disimpan sebagai text pesan, supaya tidak
        // menyesatkan (terisi di form tapi diam-diam hilang).
        $captionUntukGateway = $mediaType === 'sticker' ? '' : $caption;

        $mediaBase64 = base64_encode(file_get_contents($file->getTempName()));

        $result = $this->callGatewaySendMedia($config, $conversation['chat_id'], $mediaType, $mediaBase64, $mimetype, $fileName, $captionUntukGateway);

        if (!$result['ok']) {
            log_message('warning', 'Inbox::kirimMedia gagal mengirim ke Gateway. conversation_id=' . $conversationId . ' error=' . $result['error']);

            return $this->response->setStatusCode(502)->setJSON([
                'status'  => 'error',
                'message' => 'Gagal mengirim media: ' . $result['error'],
            ]);
        }

        $userId = (int) session()->get('id_user');
        $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

        $mediaMetadata = null;
        if ($result['media_ref']) {
            $mediaMetadata = json_encode([
                'media_type'       => $mediaType,
                'direct_path'      => $result['media_ref']['direct_path'],
                'media_key_base64' => $result['media_ref']['media_key_base64'],
            ]);
        }

        $messageModel = new MessageModel();
        $messageModel->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => $result['wa_message_id'] ?: ('local-' . bin2hex(random_bytes(8))),
            'direction'         => 'outgoing',
            'message_type'      => $mediaType,
            'sender_jid'        => null,
            'text'              => $captionUntukGateway !== '' ? $captionUntukGateway : null,
            'media_path'        => null, // SENGAJA selalu NULL -- tidak pernah menyimpan file lokal.
            'media_mime_type'   => $mimetype,
            'media_filename'    => $fileName,
            'media_size'        => $fileSize,
            'media_metadata'    => $mediaMetadata,
            'message_timestamp' => $now,
            'sent_by_user_id'   => $userId,
            'send_status'       => 'sent',
        ]);

        $newMessageId = $messageModel->getInsertID();

        $conversationUpdate = [
            'last_message_at'          => $now,
            'last_message_direction'   => 'outgoing',
            'last_replied_by'          => $userId,
            'last_seen_by_assignee_at' => $now,
        ];
        // Auto-assign ke pengirim pertama kalau belum ada yang menangani
        // -- lihat catatan sama di kirimKeConversation().
        if (empty($conversation['assigned_to'])) {
            $conversationUpdate['assigned_to'] = $userId;
        }
        $conversationModel->update($conversationId, $conversationUpdate);

        log_message('info', "Inbox::kirimMedia sukses. conversation_id={$conversationId}, user_id={$userId}, media_ref=" . ($mediaMetadata ? 'ada' : 'tidak ada'));

        $newMessage = $messageModel->find($newMessageId);
        $newMessage = $this->attachSenderNames([$newMessage])[0];

        return $this->response->setStatusCode(200)->setJSON([
            'status'          => 'success',
            'conversation_id' => $conversationId,
            'message'         => $newMessage,
        ]);
    }


    /**
     * POST /inbox/percakapan/(:num)/catatan
     *
     * Simpan catatan internal staff ke conversation tanpa mengirim
     * apa pun ke Gateway dan tanpa mengubah denormalized last-message
     * fields di conversations. Endpoint ini sengaja tidak memakai
     * cekOwnership(): semua staff yang sudah login boleh menulis note.
     */
    public function catatanInternal($conversationId = null)
    {
        $conversationId = (int) $conversationId;

        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        $text = trim((string) ($this->request->getPost('teks') ?? ''));

        if ($text === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Teks catatan tidak boleh kosong.',
            ]);
        }

        if (strlen($text) > 4096) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Teks catatan terlalu panjang (maksimal 4096 byte).',
            ]);
        }

        $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
        $userId = (int) session()->get('id_user');

        $messageModel = new MessageModel();
        $messageModel->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'internal-' . $conversationId . '-' . bin2hex(random_bytes(8)),
            'direction'         => 'outgoing',
            'message_type'      => 'text',
            'sender_jid'        => null,
            'text'              => $text,
            'message_timestamp' => $now,
            'sent_by_user_id'   => $userId,
            'send_status'       => 'sent',
            'is_internal'       => true,
        ]);

        $messageId = $messageModel->getInsertID();
        $message = $messageModel->find($messageId);
        $message = $this->attachSenderNames([$message])[0];

        return $this->response->setStatusCode(200)->setJSON([
            'status'          => 'success',
            'conversation_id' => $conversationId,
            'message'         => $message,
        ]);
    }

    /**
     * POST /inbox/percakapan/(:num)/handoff
     *
     * M3 Phase 2a (TB-01) -- serah-terima (Handoff) percakapan antar
     * kasir aktif. Method BARU: tujuh method lama (ambil, lepas, tutup,
     * snooze, tandai-dibaca, hapus, catatan) TIDAK diubah -- hanya pola
     * dipinjam (DEP-04 conditional write, DEP-05 envelope, DEP-06
     * dual-read form/JSON).
     *
     * Urutan normatif (Plan TASK-003, Q3 locked):
     *  (1) 404 conversation tak dikenal;
     *  (2) withComputedStatus() -> 'selesai' = 409 (P-01, bukan 403);
     *  (3) validasi payload 400: summary/next_action wajib non-blank &
     *      maks 4096 (P-02), note opsional maks 4096, to_user_id valid,
     *      self-Handoff = 400, expected_owner ABSEN = 400 / null atau
     *      string-kosong = klaim sah 'saw unassigned' (Q5);
     *  (4) gerbang inisiator P-05/Q1: inisiator = assignee saat ini,
     *      ATAU pada percakapan 'belum_diambil' inisiator wajib kasir
     *      aktif (Q6); selain itu 403 (AC-H08);
     *  (5) target wajib anggota daftarKasirAktif (P-03);
     *  (6) transaksi grup inbox: conditional write NULL-safe
     *      `assigned_to <=> expected` + insert riwayat; 0 affected rows
     *      = rollback + 409 bernama + current_owner_id; insert gagal =
     *      rollback, ownership utuh (REQ-H09).
     *
     * Tanpa menulis `messages`, tanpa memanggil Gateway, tanpa
     * presence/notifikasi/unread (CON-H01/CON-H03/REQ-H10).
     */
    public function handoffPercakapan($conversationId = null)
    {
        $conversationId = (int) $conversationId;

        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        // (1) 404 -- id tak dikenal.
        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        // (2) Eligibility dari sumber tunggal queue_status (DEP-01).
        // 'selesai' = 409 keluarga state-reload (P-01 memperbaiki 403
        // pada teks Spec v1.0 -- plan menang per RISK-01).
        $assignedTo = $conversation['assigned_to'] !== null
            ? (int) $conversation['assigned_to']
            : null;

        $computed = $conversationModel->withComputedStatus([$conversation])[0];
        if (($computed['queue_status'] ?? null) === 'selesai') {
            // REQ-004 (CR-06): the two 409 families now share one shape;
            // current_owner_id is nullable (null when the thread is unowned).
            return $this->response->setStatusCode(409)->setJSON([
                'status'           => 'error',
                'message'          => 'Percakapan sudah selesai dan tidak bisa diserahkan.',
                'current_owner_id' => $assignedTo,
            ]);
        }

        // (3) Dual-read form-encoded ATAU JSON (DEP-06).
        $body = $this->request->getPost();
        if (!is_array($body) || $body === []) {
            $json = $this->request->getJSON(true);
            $body = is_array($json) ? $json : [];
        }

        $userId = (int) session()->get('id_user');

        // REQ-001 (CR-01): reject non-string values BEFORE any coercion so
        // an array can never be stored as the string "Array" in a required
        // text column. An absent field (null) is rejected here as well.
        $summaryRaw    = $body['summary'] ?? null;
        $nextActionRaw = $body['next_action'] ?? null;
        $noteRaw       = $body['note'] ?? null;

        if (
            !is_string($summaryRaw) || !is_string($nextActionRaw)
            || ($noteRaw !== null && !is_string($noteRaw))
        ) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Ringkasan, tindakan berikutnya, dan catatan harus berupa teks.',
            ]);
        }

        $summary    = trim($summaryRaw);
        $nextAction = trim($nextActionRaw);
        $note       = ($noteRaw === null || trim($noteRaw) === '')
            ? null
            : $noteRaw;

        if ($summary === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Ringkasan Handoff (summary) wajib diisi.',
            ]);
        }
        if ($nextAction === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Tindakan berikutnya (next_action) wajib diisi.',
            ]);
        }
        // REQ-003 (CR-05): the 4096 cap is measured in CHARACTERS
        // (mb_strlen), matching VARCHAR(4096) and the UI maxlength.
        if (
            mb_strlen($summary) > 4096 || mb_strlen($nextAction) > 4096
            || ($note !== null && mb_strlen($note) > 4096)
        ) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Ringkasan, tindakan berikutnya, dan catatan maksimal 4096 karakter.',
            ]);
        }

        $rawTo    = $body['to_user_id'] ?? null;
        $toUserId = is_int($rawTo)
            ? $rawTo
            : (is_string($rawTo) && ctype_digit($rawTo) ? (int) $rawTo : null);
        if ($toUserId === null || $toUserId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Target Handoff (to_user_id) tidak valid.',
            ]);
        }

        // REQ-H05 -- Handoff ke diri sendiri selalu 400, tanpa
        // pengecualian role (termasuk admin).
        if ($toUserId === $userId) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Tidak bisa menyerahkan percakapan ke diri sendiri.',
            ]);
        }

        // Q5 -- field expected_owner WAJIB ada; null / string-kosong =
        // klaim sah 'belum diambil siapa pun' yang diteruskan ke
        // conditional write `<=>`. Nilai lain harus integer valid.
        if (!array_key_exists('expected_owner', $body)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Field expected_owner wajib dikirim.',
            ]);
        }
        $expectedRaw = $body['expected_owner'];
        if ($expectedRaw === null || $expectedRaw === '') {
            $expectedOwner = null;
        } elseif (
            is_int($expectedRaw)
            || (is_string($expectedRaw) && ctype_digit($expectedRaw) && (int) $expectedRaw > 0)
        ) {
            $expectedOwner = (int) $expectedRaw;
        } else {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Field expected_owner tidak valid.',
            ]);
        }

        // (4)+(5) Daftar kasir aktif = satu sumber kebenaran (Q6):
        // dipakai untuk gerbang inisiator 'belum_diambil', validasi
        // target, dan penamaan pemenang pada 409.
        $daftarKasir  = (new UserModel())->daftarKasirAktif();
        $idKasirAktif = array_map('intval', array_column($daftarKasir, 'id'));

        // (4) Gerbang inisiator (P-05/Q1). TASK-202 (REQ-007/CR-03 = A,
        // LOCKED): pengecualian tanpa pemilik dipersempit ke tab
        // `belum_diambil` (queue_status), BUKAN sekadar `assigned_to IS NULL`
        // -- supaya percakapan tanpa pemilik di tab lain (mis. `menunggu`/
        // `ditunda` akibat lepasPercakapan) tidak bisa diserahkan diam-diam.
        // `$computed` sudah tersedia (DEP-01), tanpa query tambahan.
        $inisiatorDiizinkan = $assignedTo !== null
            ? ($assignedTo === $userId)
            : (($computed['queue_status'] ?? null) === 'belum_diambil' && in_array($userId, $idKasirAktif, true));
        if (!$inisiatorDiizinkan) {
            // Pesan 403 punya TIGA cabang (mengikuti state SERVER, bukan
            // klaim klien). Cabang (i)/(ii) verbatim (locked E04(c)/E04(b));
            // cabang (iii) baru agar kasir aktif di tab non-belum_diambil
            // tidak disesatkan pesan (ii).
            if ($assignedTo !== null) {
                $pesan403 = 'Hanya staff yang sedang menangani percakapan ini yang bisa menyerahkannya.';
            } elseif (($computed['queue_status'] ?? null) === 'belum_diambil') {
                $pesan403 = 'Hanya kasir aktif yang bisa menyerahkan percakapan yang belum diambil.';
            } else {
                $pesan403 = 'Percakapan tanpa pemilik hanya bisa diserahkan dari tab Belum Diambil. Ambil dulu percakapan ini.';
            }

            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => $pesan403,
            ]);
        }

        // (5) Target wajib kasir aktif (P-03): admin, unknown, inactive
        // semuanya 403.
        if (!in_array($toUserId, $idKasirAktif, true)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => 'Target Handoff harus kasir aktif.',
            ]);
        }
        $target = null;
        foreach ($daftarKasir as $kandidat) {
            if ((int) $kandidat['id'] === $toUserId) {
                $target = $kandidat;
                break;
            }
        }

        // (6) Fail-fast 409 (REQ-008/CR-04 = A1, LOCKED): bila klaim klien
        // `expected_owner` TIDAK sama dengan `assigned_to` yang dibaca server
        // saat ini, conditional write MUSTAHIL menang -- jadi jawab 409 di
        // sini, SEBELUM transaksi, tanpa menulis apa pun. Diletakkan SETELAH
        // kedua gerbang 403 (Q3) supaya non-assignee tetap 403 dan nama
        // pemilik tidak bocor ke non-assignee.
        if ($expectedOwner !== $assignedTo) {
            return $this->balas409KepemilikanBasi($assignedTo);
        }

        // (7) Transaksi grup inbox (REQ-H09): conditional write + insert
        // riwayat atomik. ConversationHandoffModel juga memakai grup
        // 'inbox', jadi transBegin() mencakup kedua langkah.
        $db  = \Config\Database::connect('inbox');
        $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

        $db->transBegin();

        try {
            // Conditional write NULL-safe (REQ-C01): hanya menang bila
            // pemilik SAAT INI == expected_owner yang dilihat klien.
            $db->query(
                'UPDATE `conversations`
                    SET `assigned_to` = ?, `updated_at` = ?
                  WHERE `id` = ? AND `assigned_to` <=> ?',
                [$toUserId, $now, $conversationId, $expectedOwner]
            );
            $affected = $db->affectedRows();

            if ($affected === 0) {
                // Kalah race/state-reload (REQ-C02): tanpa perubahan,
                // tanpa insert, 409 menyebut pemilik sah + id-nya.
                $db->transRollback();

                $conversationTerkini = $conversationModel->find($conversationId);
                $currentOwnerId = $conversationTerkini['assigned_to'] !== null
                    ? (int) $conversationTerkini['assigned_to']
                    : null;

                return $this->balas409KepemilikanBasi($currentOwnerId);
            }

            // Riwayat Handoff (REQ-H07): from = pemilik sebelum write
            // (NULL bila sebelumnya belum diambil, K-06); initiated_by
            // SELALU id sesi -- boleh beda dari from pada kasus unassigned.
            $handoffId = (new ConversationHandoffModel())->insertHandoff([
                'conversation_id'      => $conversationId,
                'from_user_id'         => $assignedTo,
                'to_user_id'           => $toUserId,
                'initiated_by_user_id' => $userId,
                'summary'              => $summary,
                'next_action'          => $nextAction,
                'note'                 => $note,
                'created_at'           => $now,
            ]);

            // TB-02/TASK-006 hardening residual: dengan DBDebug=false
            // (produksi) CI4 mengembalikan false alih-alih melempar
            // exception, sehingga insert yang gagal akan lolos diam-diam
            // sebagai "sukses" tanpa baris riwayat. id <= 0 berarti TIDAK
            // ada baris tersimpan -- perlakukan sama seperti insert gagal
            // supaya jaminan AC-C03/REQ-H09 tidak bergantung pada konfigurasi.
            if ($handoffId <= 0) {
                throw new \RuntimeException('insertHandoff tidak menyimpan baris riwayat.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            // Insert riwayat gagal -> rollback, ownership tetap utuh
            // (REQ-H09 / AC-C03).
            $db->transRollback();
            log_message('error', "Inbox::handoffPercakapan gagal, transaksi di-rollback. conversation_id={$conversationId}, error={$e->getMessage()}");

            return $this->response->setStatusCode(500)->setJSON([
                'status'  => 'error',
                'message' => 'Gagal menyimpan riwayat Handoff, percakapan tidak berpindah.',
            ]);
        }

        $targetNama = ($target['nama'] ?? null) ?: ('Kasir #' . $toUserId);
        log_message('info', "Inbox::handoffPercakapan sukses. conversation_id={$conversationId}, from={$assignedTo}, to={$toUserId}, initiated_by={$userId}, handoff_id={$handoffId}");

        // Sukses = envelope tutupPercakapan() (DEP-05/GUD-H01) + id
        // pemilik baru & id riwayat (Spec Section 4.3).
        $updated = $this->attachAssignedNames([$conversationModel->find($conversationId)])[0];
        $updated = $conversationModel->withComputedStatus([$updated])[0];

        return $this->response->setStatusCode(200)->setJSON([
            'status'       => 'success',
            'message'      => "Percakapan berhasil diserahkan ke {$targetNama}.",
            'conversation' => $updated,
            'to_user_id'   => $toUserId,
            'handoff_id'   => $handoffId,
        ]);
    }

    /**
     * Bangun respons 409 "kepemilikan basi" (REQ-C02) dari id pemilik yang
     * SUDAH dibaca pemanggil -- helper ini TIDAK melakukan query ulang
     * kepemilikan (tanpa ConversationModel::find() di dalamnya). Nama
     * pemilik diresolusi via UserModel dengan fallback 'User #{id}' supaya
     * body 409 tetap byte-identical dengan jalur kalah conditional write.
     *
     * Dua pemanggil: jalur kalah conditional write (race, TASK-108) dan
     * jalur fail-fast (Fase 2, TASK-201).
     */
    private function balas409KepemilikanBasi(?int $currentOwnerId)
    {
        $pemilik = $currentOwnerId !== null ? (new UserModel())->find($currentOwnerId) : null;
        $namaPemilik = $pemilik
            ? ($pemilik['nama'] ?: $pemilik['username'])
            : 'User #' . $currentOwnerId;

        return $this->response->setStatusCode(409)->setJSON([
            'status'           => 'error',
            'message'          => $currentOwnerId !== null
                ? "Percakapan ini sudah ditangani oleh {$namaPemilik}."
                : 'Percakapan ini sudah berpindah, silakan muat ulang daftar.',
            'current_owner_id' => $currentOwnerId,
        ]);
    }

    /**
     * GET /inbox/percakapan/(:num)/handoff
     *
     * Riwayat penyerahan (Handoff) satu percakapan -- TERBARU DULU, cap
     * 50 entri (REQ-H08/P-04). Endpoint KHUSUS: `GET /inbox/api/
     * conversations/(:num)/messages` sengaja TIDAK diubah supaya thread
     * pesan tidak pernah tercampur riwayat Handoff.
     *
     * Gerbang baca cukup filter `auth` (Q7/PRD GH-006: riwayat "bisa
     * dibaca kembali oleh staff") -- BEDA dari jalur TULIS
     * (handoffPercakapan) yang mensyaratkan assignee/`belum_diambil`.
     * 404 hanya untuk id percakapan yang tidak dikenal.
     */
    public function apiHandoffs($conversationId = null)
    {
        $conversationId = (int) $conversationId;

        $conversationModel = new ConversationModel();

        if (!$conversationModel->find($conversationId)) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        $limit = 50;

        return $this->response->setJSON([
            'status'   => 'success',
            'handoffs' => (new ConversationHandoffModel())->forConversation($conversationId, $limit),
            'limit'    => $limit,
        ]);
    }





    /**
     * POST /inbox/percakapan/(:num)/hapus
     *
     * Hapus satu conversation dari aulia_inboxdb -- TIDAK
     * menghapus/mempengaruhi apa pun di WhatsApp maupun di Gateway
     * (Gateway tidak menyimpan riwayat percakapan sama sekali, jadi
     * tidak ada yang perlu disinkronkan ke sana).
     *
     * Tahap D -- SOFT delete (bukan lagi hard delete): identitas
     * customer yang sudah dikonfirmasi (nomor asli, nama benar) hidup
     * di baris `conversations` itu sendiri (tidak ada tabel
     * `customers` terpisah), jadi hard delete permanen menghilangkan
     * identitas itu tanpa bisa dipulihkan. deleted_at diisi
     * ($useSoftDeletes=true di ConversationModel), baris `messages`
     * TIDAK ikut disentuh (tetap ada, cuma "tersembunyi" karena induk
     * conversation-nya soft-deleted). Kalau customer yang sama kirim
     * pesan baru, ConversationModel::resolveConversationId() otomatis
     * menghidupkan kembali lewat revive() -- lihat catatan di sana.
     *
     * Guard admin-only + wajib status='closed' -- sesuai kesepakatan
     * sejak docs/CHAT.md awal, baru ditegakkan di kode sekarang
     * (Tahap D). Soft-delete di atas adalah jaring pengaman kalau ini
     * tetap terjadi, tapi guard ini mencegah dari awal.
     */
    public function hapusPercakapan($conversationId = null)
    {
        $conversationId = (int) $conversationId;

        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        if ((string) session()->get('role') !== 'admin') {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => 'Hanya admin yang bisa menghapus percakapan.',
            ]);
        }

        if ($conversation['status'] !== 'closed') {
            return $this->response->setStatusCode(409)->setJSON([
                'status'  => 'error',
                'message' => 'Percakapan harus ditutup (Selesai) dulu sebelum bisa dihapus.',
            ]);
        }

        $ownershipError = $this->cekOwnership($conversation, (int) session()->get('id_user'), (string) session()->get('role'));
        if ($ownershipError) {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => $ownershipError,
            ]);
        }

        $conversationModel->delete($conversationId); // sekarang SOFT delete (Tahap D)

        $userId = (int) session()->get('id_user');
        log_message('info', "Inbox::hapusPercakapan sukses. conversation_id={$conversationId}, chat_id={$conversation['chat_id']}, dihapus_oleh_user_id={$userId}");

        return $this->response->setStatusCode(200)->setJSON([
            'status'          => 'success',
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * POST /inbox/percakapan/(:num)/ambil
     *
     * Kasir/admin secara eksplisit "mengambil" satu conversation --
     * menandai dirinya sebagai penanggung jawab (assigned_to), supaya
     * staff lain tahu percakapan ini sedang ditangani dan (lewat
     * cekOwnership()) tidak bisa ikut membalas/menghapusnya tanpa
     * sepengetahuan. Beda dari auto-assign di kirimKeConversation()/
     * kirimMedia() (yang baru assign SETELAH benar-benar membalas) --
     * ini dipakai untuk "klaim duluan" sebelum sempat membalas apa pun,
     * mis. supaya tidak ada 2 kasir mengetik balasan bersamaan.
     *
     * Kalau sudah ditangani orang lain: admin boleh mengambil alih
     * (override), kasir non-admin ditolak dengan pesan jelas siapa
     * yang sedang menangani.
     *
     * --- Tahap 2: race condition (dua staff menekan "Ambil" hampir
     * bersamaan) ---
     * Versi lama cuma find() lalu update() terpisah -- dua request yang
     * datang nyaris bersamaan bisa SAMA-SAMA lolos pengecekan
     * "assigned_to masih NULL" (dibaca sebelum salah satu sempat
     * menyimpan), lalu SAMA-SAMA menganggap dirinya berhasil mengambil.
     * Diperbaiki dengan SATU UPDATE ber-syarat (`WHERE assigned_to IS
     * NULL`, atau tanpa syarat untuk admin/override) -- MySQL mengunci
     * baris per statement UPDATE, jadi kalau dua request bentrok, hanya
     * SATU yang benar-benar mengubah baris (`affectedRows() === 1`);
     * yang kalah otomatis dapat 0 baris berubah, TANPA perlu locking
     * aplikasi tambahan.
     */
    public function ambilPercakapan($conversationId = null)
    {
        $conversationId = (int) $conversationId;

        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        $userId = (int) session()->get('id_user');
        $role   = (string) session()->get('role');
        $assignedTo = $conversation['assigned_to'] ? (int) $conversation['assigned_to'] : null;

        // Idempotent: sudah milik sendiri -- tidak perlu race-check,
        // langsung sukses (mis. klik "Ambil" dobel karena koneksi lambat).
        if ($assignedTo === $userId) {
            return $this->response->setStatusCode(200)->setJSON([
                'status'          => 'success',
                'conversation_id' => $conversationId,
            ]);
        }

        $db = \Config\Database::connect('inbox');
        $builder = $db->table('conversations')->where('id', $conversationId);

        // Non-admin HANYA boleh menang kalau baris masih benar-benar
        // unassigned SAAT UPDATE dieksekusi (bukan saat find() di atas).
        // Admin sengaja tanpa syarat tambahan (override/take over diizinkan).
        if ($role !== 'admin') {
            $builder->where('assigned_to', null);
        }

        $builder->update(['assigned_to' => $userId]);
        $affected = $db->affectedRows();

        if ($affected === 0) {
            // Kalah race (atau memang sudah dipegang orang lain sejak
            // awal) -- re-fetch untuk kasih tahu siapa yang menangani.
            $conversationTerkini = $conversationModel->find($conversationId);
            $assignedTerkini = $conversationTerkini['assigned_to'] ? (int) $conversationTerkini['assigned_to'] : null;

            $penangan = $assignedTerkini ? (new UserModel())->find($assignedTerkini) : null;
            $namaPenangan = $penangan ? ($penangan['nama'] ?: $penangan['username']) : ('User #' . $assignedTerkini);

            return $this->response->setStatusCode(409)->setJSON([
                'status'  => 'error',
                'message' => $assignedTerkini
                    ? "Percakapan ini sudah diambil oleh {$namaPenangan}."
                    : 'Gagal mengambil percakapan, silakan coba lagi.',
            ]);
        }

        log_message('info', "Inbox::ambilPercakapan sukses. conversation_id={$conversationId}, user_id={$userId}");

        return $this->response->setStatusCode(200)->setJSON([
            'status'          => 'success',
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * POST /inbox/percakapan/(:num)/lepas
     *
     * Kebalikan dari ambilPercakapan() -- kosongkan assigned_to
     * supaya conversation ini kembali "bebas" (boleh diambil/dibalas
     * siapa saja lagi). Aturan siapa yang boleh melepas SAMA dengan
     * cekOwnership() (diri sendiri yang menangani, atau admin).
     */
    public function lepasPercakapan($conversationId = null)
    {
        $conversationId = (int) $conversationId;

        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        $ownershipError = $this->cekOwnership($conversation, (int) session()->get('id_user'), (string) session()->get('role'));
        if ($ownershipError) {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => $ownershipError,
            ]);
        }

        $conversationModel->update($conversationId, ['assigned_to' => null]);

        log_message('info', "Inbox::lepasPercakapan sukses. conversation_id={$conversationId}, user_id=" . (int) session()->get('id_user'));

        return $this->response->setStatusCode(200)->setJSON([
            'status'          => 'success',
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * POST /inbox/percakapan/(:num)/tandai-dibaca
     *
     * Tandai conversation sudah dilihat oleh yang menangani -- dipakai
     * tombol "Tandai Dibaca" di header thread, supaya response_state
     * tidak lagi 'perlu_dibalas' walau belum ada balasan outgoing baru.
     */
    public function tandaiDibaca($conversationId = null)
    {
        $conversationId = (int) $conversationId;
        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Conversation tidak ditemukan.']);
        }

        $ownershipError = $this->cekOwnership($conversation, (int) session()->get('id_user'), (string) session()->get('role'));
        if ($ownershipError) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => $ownershipError]);
        }

        $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
        $conversationModel->update($conversationId, ['last_seen_by_assignee_at' => $now]);

        return $this->response->setStatusCode(200)->setJSON(['status' => 'success', 'conversation_id' => $conversationId]);
    }

    /**
     * POST /inbox/percakapan/(:num)/snooze
     *
     * Follow-up sementara: sembunyikan conversation dari 'perlu_dibalas'
     * sampai waktu tertentu. Body: { "menit": 120 } (0/kosong = batalkan
     * snooze). Otomatis di-reset ke null saat ada incoming baru masuk
     * (lihat InboxGatewayApi::messages()).
     */
    public function snoozePercakapan($conversationId = null)
    {
        $conversationId = (int) $conversationId;
        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Conversation tidak ditemukan.']);
        }

        $ownershipError = $this->cekOwnership($conversation, (int) session()->get('id_user'), (string) session()->get('role'));
        if ($ownershipError) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => $ownershipError]);
        }

        $menit = (int) ($this->request->getJSON(true)['menit'] ?? 0);
        $snoozedUntil = $menit > 0
            ? (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->modify("+{$menit} minutes")->format('Y-m-d H:i:s')
            : null;

        $conversationModel->update($conversationId, ['snoozed_until' => $snoozedUntil]);

        return $this->response->setStatusCode(200)->setJSON(['status' => 'success', 'conversation_id' => $conversationId]);
    }

    /**
     * POST /inbox/percakapan/(:num)/tutup
     *
     * Tahap 1 lifecycle status conversation (docs/aturan-bisnis-CHAT.md
     * Section 12) -- kasir/admin menutup conversation yang sedang OPEN.
     * `status`/`closed_at`/`closed_by` SUDAH ADA di skema sejak Phase 1
     * (migration 2026-09-07-000001_CreateInboxTables.php), tidak ada
     * migration baru.
     *
     * SENGAJA TIDAK menghapus/mengubah data lain apa pun (assignment,
     * history pesan tetap utuh) -- murni penanda lifecycle. Reopen
     * (CLOSED -> OPEN) TIDAK punya tombol manual di sini, sesuai spec:
     * satu-satunya pemicu reopen adalah pesan masuk baru dari customer
     * (lihat InboxGatewayApi::messages(), sudah memaksa status='open'
     * untuk direction='incoming' sejak awal, tidak diubah oleh fitur
     * ini).
     *
     * Idempotent: kalau sudah closed, kembalikan sukses apa adanya
     * tanpa menimpa closed_at/closed_by yang sudah tercatat.
     */
    public function tutupPercakapan($conversationId = null)
    {
        $conversationId = (int) $conversationId;

        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        $ownershipError = $this->cekOwnership($conversation, (int) session()->get('id_user'), (string) session()->get('role'));
        if ($ownershipError) {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => $ownershipError,
            ]);
        }

        if ($conversation['status'] !== 'closed') {
            $userId = (int) session()->get('id_user');
            $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

            $conversationModel->update($conversationId, [
                'status'    => 'closed',
                'closed_at' => $now,
                'closed_by' => $userId,
            ]);

            log_message('info', "Inbox::tutupPercakapan sukses. conversation_id={$conversationId}, user_id={$userId}");
        }

        $updated = $this->attachAssignedNames([$conversationModel->find($conversationId)])[0];

        return $this->response->setStatusCode(200)->setJSON([
            'status'       => 'success',
            'conversation' => $updated,
        ]);
    }

    /**
     * POST /inbox/percakapan/(:num)/profil
     *
     * Task Group 1.5 -- kasir/admin mengubah profil customer secara
     * manual: nama pelanggan (`contact_name`) dan/atau nomor telepon
     * (`manual_phone`). KEDUANYA field informasional/manual -- TIDAK
     * PERNAH ditimpa otomatis oleh event WhatsApp berikutnya (lihat
     * catatan contact_name vs whatsapp_name, phone vs manual_phone di
     * ConversationModel), dan `manual_phone` TIDAK PERNAH dipakai
     * untuk reconciliation/pencarian conversation (nomor yang diketik
     * manusia belum tentu benar-benar nomor WhatsApp yang valid).
     *
     * Boleh mengosongkan salah satu/kedua field (kirim string kosong)
     * untuk menghapus data yang salah -- bukan wajib diisi keduanya.
     */
    public function updateCustomerProfile($conversationId = null)
    {
        $conversationId = (int) $conversationId;

        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        $ownershipError = $this->cekOwnership($conversation, (int) session()->get('id_user'), (string) session()->get('role'));
        if ($ownershipError) {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => $ownershipError,
            ]);
        }

        $customerNameRaw = trim((string) ($this->request->getPost('customer_name') ?? ''));
        $phoneRaw        = trim((string) ($this->request->getPost('phone') ?? ''));

        if (strlen($customerNameRaw) > 255) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Nama pelanggan terlalu panjang (maksimal 255 karakter).',
            ]);
        }

        $manualPhone = null;
        if ($phoneRaw !== '') {
            $manualPhone = PhoneNumber::normalize($phoneRaw);

            if ($manualPhone === null) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status'  => 'error',
                    'message' => 'Format nomor telepon tidak dikenali. Gunakan format 08xx, 62xx, atau +62xx.',
                ]);
            }
        }

        $userId = (int) session()->get('id_user');
        $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

        $conversationModel->update($conversationId, [
            'contact_name'       => $customerNameRaw !== '' ? $customerNameRaw : null,
            'manual_phone'       => $manualPhone,
            'profile_updated_at' => $now,
            'profile_updated_by' => $userId,
        ]);

        log_message('info', "Inbox::updateCustomerProfile sukses. conversation_id={$conversationId}, user_id={$userId}");

        $updated = $this->attachAssignedNames([$conversationModel->find($conversationId)])[0];

        return $this->response->setStatusCode(200)->setJSON([
            'status'       => 'success',
            'conversation' => $updated,
        ]);
    }

    /**
     * POST /inbox/percakapan/(:num)/konfirmasi-nomor
     *
     * Revisi LID-FIRST -> PN-LATER (Task Group 1.5) -- jalur FALLBACK
     * MANUAL untuk kasus @lid yang belum bisa direkonsiliasi otomatis
     * (Gateway tidak berhasil resolve LID lewat onWhatsApp(), atau
     * belum ada pesan PN yang masuk sama sekali dari nomor itu).
     * Kasir/admin secara SADAR & EKSPLISIT mengkonfirmasi "nomor ini
     * benar-benar nomor WhatsApp customer di percakapan ini".
     *
     * BEDA PENTING dari updateCustomerProfile() (edit nama/nomor
     * biasa): endpoint itu menulis ke `manual_phone` (informasional,
     * TIDAK PERNAH dipakai reconciliation). Endpoint INI menulis ke
     * `phone` (kolom yang SAMA dipakai reconciliation otomatis) --
     * begitu dikonfirmasi, PESAN PN BERIKUTNYA dengan nomor yang sama
     * akan otomatis nyambung ke conversation ini (lewat
     * ConversationModel::resolveConversationId() langkah 3), TANPA
     * perlu logic baru -- endpoint ini murni "isi phone dengan sengaja
     * oleh manusia", bukan mekanisme merge terpisah.
     *
     * SENGAJA TIDAK menggabungkan/memindahkan message dari conversation
     * lain mana pun -- kalau nomor ini KEBETULAN sudah terpakai di
     * conversation lain, request ini DITOLAK (409) dengan info
     * conversation itu, supaya kasir bisa pindah ke sana sendiri
     * (mencegah 2 conversation punya `phone` yang sama, yang akan
     * membuat pencarian nomor jadi ambigu).
     */
    public function konfirmasiNomorWhatsapp($conversationId = null)
    {
        $conversationId = (int) $conversationId;

        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->find($conversationId);

        if (!$conversation) {
            return $this->response->setStatusCode(404)->setJSON([
                'status'  => 'error',
                'message' => 'Conversation tidak ditemukan.',
            ]);
        }

        $ownershipError = $this->cekOwnership($conversation, (int) session()->get('id_user'), (string) session()->get('role'));
        if ($ownershipError) {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => $ownershipError,
            ]);
        }

        $phoneRaw = trim((string) ($this->request->getPost('phone') ?? ''));

        if ($phoneRaw === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Nomor telepon wajib diisi untuk konfirmasi.',
            ]);
        }

        $canonicalPhone = PhoneNumber::normalize($phoneRaw);

        if ($canonicalPhone === null) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Format nomor telepon tidak dikenali. Gunakan format 08xx, 62xx, atau +62xx.',
            ]);
        }

        // Cegah 2 conversation punya `phone` ter-verifikasi yang sama --
        // kalau sudah dipakai conversation LAIN, tolak & arahkan ke sana
        // (bukan menggabungkan otomatis -- kasir yang putuskan sendiri).
        $existingWithPhone = $conversationModel->where('phone', $canonicalPhone)
            ->where('id !=', $conversationId)
            ->first();

        if ($existingWithPhone) {
            $namaLain = $existingWithPhone['contact_name'] ?: $existingWithPhone['whatsapp_name'] ?: $existingWithPhone['chat_id'];

            return $this->response->setStatusCode(409)->setJSON([
                'status'  => 'error',
                'message' => "Nomor ini sudah terhubung ke percakapan lain ({$namaLain}, #{$existingWithPhone['id']}). Pindah ke percakapan itu, jangan konfirmasi dobel.",
                'conversation_id_lain' => (int) $existingWithPhone['id'],
            ]);
        }

        $userId = (int) session()->get('id_user');
        $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

        $conversationModel->update($conversationId, [
            'phone'              => $canonicalPhone,
            'profile_updated_at' => $now,
            'profile_updated_by' => $userId,
        ]);

        log_message('info', "Inbox::konfirmasiNomorWhatsapp sukses (KONFIRMASI MANUAL). conversation_id={$conversationId}, phone={$canonicalPhone}, user_id={$userId}");

        $updated = $this->attachAssignedNames([$conversationModel->find($conversationId)])[0];

        return $this->response->setStatusCode(200)->setJSON([
            'status'       => 'success',
            'conversation' => $updated,
        ]);
    }

    /**
     * Logic inti kirim pesan (dipakai bersama oleh kirim() dan
     * mulaiPercakapan(), supaya tidak duplikat kode).
     *
     * Alur (persis sesuai spec): cek ownership -> cek Gateway usable
     * -> panggil Gateway HTTP -> HANYA simpan sebagai outgoing 'sent'
     * & update conversation KALAU Gateway konfirmasi sukses. Kalau
     * gagal di titik manapun, TIDAK ada yang disimpan ke database
     * sama sekali -- browser cukup diberi tahu gagal, silakan retry
     * (tidak ada outgoing queue, sesuai spec: "Gateway offline =>
     * reject segera", "tidak boleh membuat outgoing queue").
     */
    private function kirimKeConversation(array $conversation, string $text)
    {
        $conversationId = (int) $conversation['id'];
        $chatId         = $conversation['chat_id'];

        $ownershipError = $this->cekOwnership($conversation, (int) session()->get('id_user'), (string) session()->get('role'));
        if ($ownershipError) {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => $ownershipError,
            ]);
        }

        // --- Cek Gateway usable DULU, sebelum mencoba HTTP call ------------
        // Supaya kalau Gateway jelas-jelas offline, kasir langsung tahu
        // dalam waktu singkat -- tidak menunggu timeout HTTP penuh.
        $gatewayStatusModel = new GatewayStatusModel();

        if (!$gatewayStatusModel->isUsable()) {
            return $this->response->setStatusCode(503)->setJSON([
                'status'  => 'error',
                'message' => 'Gateway WhatsApp sedang tidak terhubung. Coba lagi setelah Gateway online.',
            ]);
        }

        $config = new InboxConfig();

        if ($config->gatewayBaseUrl === '') {
            log_message('critical', 'Inbox::kirimKeConversation -- inbox.gatewayBaseUrl belum dikonfigurasi di .env.');

            return $this->response->setStatusCode(503)->setJSON([
                'status'  => 'error',
                'message' => 'Gateway belum dikonfigurasi di server.',
            ]);
        }

        $result = $this->callGatewaySend($config, $chatId, $text);

        if (!$result['ok']) {
            log_message('warning', 'Inbox::kirimKeConversation gagal mengirim ke Gateway. conversation_id=' . $conversationId . ' error=' . $result['error']);

            return $this->response->setStatusCode(502)->setJSON([
                'status'  => 'error',
                'message' => 'Gagal mengirim pesan: ' . $result['error'],
            ]);
        }

        // --- Sukses: BARU sekarang simpan sebagai outgoing 'sent' ----------
        $userId = (int) session()->get('id_user');
        // Eksplisit Asia/Jakarta, TIDAK bergantung setting timezone
        // default PHP di server (lihat catatan bug jam di
        // InboxGatewayApi::parseTimestamp()).
        $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

        $messageModel = new MessageModel();
        $messageModel->insert([
            'conversation_id'   => $conversationId,
            // Fallback kalau karena suatu alasan Gateway tidak mengirim
            // wa_message_id (seharusnya selalu ada kalau success=true,
            // tapi kolom ini NOT NULL UNIQUE, jadi tetap butuh fallback
            // yang aman/tidak akan collide dengan wa_message_id asli).
            'wa_message_id'     => $result['wa_message_id'] ?: ('local-' . bin2hex(random_bytes(8))),
            'direction'         => 'outgoing',
            'message_type'      => 'text',
            'sender_jid'        => null,
            'text'              => $text,
            'message_timestamp' => $now,
            'sent_by_user_id'   => $userId,
            'send_status'       => 'sent',
        ]);

        $newMessageId = $messageModel->getInsertID();

        $conversationUpdate = [
            'last_message_at'          => $now,
            'last_message_direction'   => 'outgoing',
            'last_replied_by'          => $userId,
            'last_seen_by_assignee_at' => $now,
        ];
        // Auto-assign ke pengirim pertama kalau belum ada yang menangani
        // conversation ini -- masuk akal untuk kasir yang membalas
        // duluan otomatis "memegang" percakapan itu, tanpa perlu klik
        // "Ambil" secara terpisah. Tidak menimpa assignment yang sudah
        // ada (cekOwnership() di atas sudah memastikan hanya yang
        // berhak yang sampai ke titik ini).
        if (empty($conversation['assigned_to'])) {
            $conversationUpdate['assigned_to'] = $userId;
        }

        $conversationModel = new ConversationModel();
        $conversationModel->update($conversationId, $conversationUpdate);

        log_message('info', "Inbox::kirimKeConversation sukses. conversation_id={$conversationId}, user_id={$userId}");

        // Sertakan data pesan yang baru dibuat + conversation_id, supaya
        // UI bisa langsung menampilkannya di thread tanpa menunggu
        // siklus polling berikutnya -- sesuai spec: "outgoing langsung
        // terlihat setelah sukses". conversation_id penting terutama
        // untuk mulaiPercakapan(), di mana browser belum tahu ID
        // conversation yang baru dibuat sebelum request ini.
        $newMessage = $messageModel->find($newMessageId);
        $newMessage = $this->attachSenderNames([$newMessage])[0];

        return $this->response->setStatusCode(200)->setJSON([
            'status'          => 'success',
            'conversation_id' => $conversationId,
            'message'         => $newMessage,
        ]);
    }

    /**
     * Normalisasi nomor telepon Indonesia (format umum: 08xx, 8xx,
     * 62xx, +62xx, boleh ada spasi/strip) menjadi JID WhatsApp.
     * Mengembalikan null kalau formatnya tidak bisa dikenali dengan
     * yakin -- SENGAJA tidak menebak-nebak nomor yang ambigu.
     *
     * @return array{0: string, 1: string}|null [chat_id, phone_bersih]
     */
    private function normalizePhoneToJid(string $input): ?array
    {
        $normalized = PhoneNumber::normalize($input);

        if ($normalized === null) {
            return null;
        }

        return [$normalized . '@s.whatsapp.net', $normalized];
    }

    /**
     * Panggil POST /send milik Gateway lewat cURL langsung -- tidak
     * bergantung ke library HTTP client tambahan (Guzzle dkk) yang
     * belum tentu ter-install di project ini.
     *
     * @return array{ok: bool, wa_message_id?: ?string, timestamp?: ?string, error?: string}
     */
    private function callGatewaySend(InboxConfig $config, string $chatId, string $text): array
    {
        $url = $config->gatewayBaseUrl . '/send';

        $payload = json_encode([
            'chat_id' => $chatId,
            'text'    => $text,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config->gatewayToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            return ['ok' => false, 'error' => 'Tidak bisa menghubungi Gateway: ' . $curlError];
        }

        $json = json_decode($rawResponse, true);

        if ($httpCode >= 200 && $httpCode < 300 && is_array($json) && ($json['success'] ?? false) === true) {
            return [
                'ok'            => true,
                'wa_message_id' => $json['wa_message_id'] ?? null,
                'timestamp'     => $json['timestamp'] ?? null,
            ];
        }

        $errorMessage = is_array($json)
            ? ($json['message'] ?? ('Gateway menolak (HTTP ' . $httpCode . ')'))
            : ('HTTP ' . $httpCode . ', respons Gateway tidak valid: ' . substr((string) $rawResponse, 0, 200));

        return ['ok' => false, 'error' => $errorMessage];
    }

    /**
     * Panggil POST /send-media milik Gateway -- versi media dari
     * callGatewaySend(). Timeout lebih lama (30 detik, sama seperti
     * callGatewayMediaDownload()) karena upload base64 + kirim ke
     * Baileys butuh waktu lebih dibanding teks biasa.
     *
     * @return array{ok: bool, wa_message_id?: ?string, timestamp?: ?string, media_ref?: ?array, error?: string}
     */
    private function callGatewaySendMedia(InboxConfig $config, string $chatId, string $mediaType, string $mediaBase64, ?string $mimetype, ?string $fileName, string $caption): array
    {
        $url = $config->gatewayBaseUrl . '/send-media';

        $payload = json_encode([
            'chat_id'      => $chatId,
            'media_type'   => $mediaType,
            'media_base64' => $mediaBase64,
            'mimetype'     => $mimetype,
            'file_name'    => $fileName,
            // $caption selalu string (lihat signature method) -- Gateway
            // menolak null untuk field ini, cuma menerima string (boleh
            // kosong ''). Sebelumnya dikirim null saat kosong, tidak
            // pernah ketahuan salah karena outgoing media belum pernah
            // dites sampai ke Gateway asli.
            'caption'      => $caption,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config->gatewayToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            return ['ok' => false, 'error' => 'Tidak bisa menghubungi Gateway: ' . $curlError];
        }

        $json = json_decode($rawResponse, true);

        if ($httpCode >= 200 && $httpCode < 300 && is_array($json) && ($json['success'] ?? false) === true) {
            $mediaRef = null;
            if (
                !empty($json['media_ref']) && is_array($json['media_ref'])
                && !empty($json['media_ref']['direct_path']) && !empty($json['media_ref']['media_key_base64'])
            ) {
                $mediaRef = [
                    'direct_path'      => $json['media_ref']['direct_path'],
                    'media_key_base64' => $json['media_ref']['media_key_base64'],
                ];
            }

            return [
                'ok'            => true,
                'wa_message_id' => $json['wa_message_id'] ?? null,
                'timestamp'     => $json['timestamp'] ?? null,
                'media_ref'     => $mediaRef,
            ];
        }

        $errorMessage = is_array($json)
            ? ($json['message'] ?? ('Gateway menolak (HTTP ' . $httpCode . ')'))
            : ('HTTP ' . $httpCode . ', respons Gateway tidak valid: ' . substr((string) $rawResponse, 0, 200));

        return ['ok' => false, 'error' => $errorMessage];
    }
}

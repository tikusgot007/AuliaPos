<?php

namespace App\Controllers;

use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\GatewayStatusModel;
use App\Models\UserModel;
use App\Libraries\PhoneNumber;
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
        $conversations = $this->attachAssignedNames($conversationModel->orderBy('last_message_at', 'DESC')->findAll(100));

        $gatewayStatusModel = new GatewayStatusModel();
        $gatewayStatus = $this->buildGatewayStatusPayload($gatewayStatusModel);

        $data = [
            'title'          => 'Inbox WhatsApp | AULIA',
            'content'        => 'inbox/index',
            'conversations'  => $conversations,
            'gatewayStatus'  => $gatewayStatus,
            'currentUserId'  => (int) session()->get('id_user'),
            'currentUserRole' => (string) session()->get('role'),
        ];

        return view('layout/main', $data);
    }

    /**
     * GET /inbox/api/conversations
     *
     * Daftar conversation dalam JSON, dipakai polling berkala oleh
     * halaman index() untuk memperbarui daftar (mis. ada conversation
     * baru masuk, atau last_message_at berubah).
     */
    public function apiConversations()
    {
        $conversationModel = new ConversationModel();
        $conversations = $this->attachAssignedNames($conversationModel->orderBy('last_message_at', 'DESC')->findAll(100));

        return $this->response->setJSON([
            'status'        => 'success',
            'conversations' => $conversations,
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

        return $this->response->setJSON([
            'status'       => 'success',
            'conversation' => $this->attachAssignedNames([$conversation])[0],
            'messages'     => $messages,
        ]);
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

        if (!in_array($message['message_type'], ['image', 'document'], true) || empty($message['media_metadata'])) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Pesan ini bukan gambar/dokumen, atau referensi media-nya tidak ada.',
            ]);
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
            ->setBody($result['binary']);
    }

    /**
     * Panggil POST /media/download milik Gateway -- minta Gateway
     * ambil+dekripsi 1 file media dari server WhatsApp berdasarkan
     * referensi yang tersimpan, lalu kembalikan isinya (binary) di
     * sini. TIDAK ADA file yang disimpan ke disk CI4 di titik manapun.
     *
     * @return array{ok: bool, binary?: string, status?: int, error?: string}
     */
    private function callGatewayMediaDownload(InboxConfig $config, array $mediaRef, ?string $mimetype): array
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
            CURLOPT_TIMEOUT        => 30,
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
        $conversationModel = new ConversationModel();
        $resolved = $conversationModel->resolveConversationId($chatId, 'pn', $phoneClean);
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
        // baliknya. image/* dianggap 'image', selain itu 'document'
        // (konsisten dengan VALID_MEDIA_TYPES Gateway yang cuma dua ini).
        $mimetype  = $file->getMimeType();
        $mediaType = str_starts_with((string) $mimetype, 'image/') ? 'image' : 'document';
        $fileName  = $file->getClientName();
        $fileSize  = $file->getSize();

        $mediaBase64 = base64_encode(file_get_contents($file->getTempName()));

        $result = $this->callGatewaySendMedia($config, $conversation['chat_id'], $mediaType, $mediaBase64, $mimetype, $fileName, $caption);

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
            'text'              => $caption !== '' ? $caption : null,
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
            'last_message_at'        => $now,
            'last_message_direction' => 'outgoing',
            'last_replied_by'        => $userId,
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
     * POST /inbox/percakapan/(:num)/hapus
     *
     * Hapus satu conversation BESERTA SEMUA pesannya dari
     * aulia_inboxdb. Ini penghapusan PERMANEN dari sisi POS/CI4 saja
     * -- TIDAK menghapus/mempengaruhi apa pun di WhatsApp maupun di
     * Gateway (Gateway tidak menyimpan riwayat percakapan sama
     * sekali, jadi tidak ada yang perlu disinkronkan ke sana).
     *
     * SENGAJA hard delete (bukan soft delete/arsip) -- tabel ini
     * tidak punya kolom deleted_at ($useSoftDeletes = false di kedua
     * model), dan tidak ada spec yang minta riwayat hapus disimpan.
     * Semua baris `messages` milik conversation ini ikut terhapus
     * otomatis lewat FK `ON DELETE CASCADE` (lihat migration
     * `2026-09-07-000001_CreateInboxTables.php`) -- tidak perlu query
     * DELETE terpisah untuk messages.
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

        $ownershipError = $this->cekOwnership($conversation, (int) session()->get('id_user'), (string) session()->get('role'));
        if ($ownershipError) {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => $ownershipError,
            ]);
        }

        $conversationModel->delete($conversationId);

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

        if ($assignedTo !== null && $assignedTo !== $userId && $role !== 'admin') {
            $penangan = (new UserModel())->find($assignedTo);
            $namaPenangan = $penangan ? ($penangan['nama'] ?: $penangan['username']) : ('User #' . $assignedTo);

            return $this->response->setStatusCode(409)->setJSON([
                'status'  => 'error',
                'message' => "Percakapan ini sudah diambil oleh {$namaPenangan}.",
            ]);
        }

        $conversationModel->update($conversationId, ['assigned_to' => $userId]);

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
            'last_message_at'        => $now,
            'last_message_direction' => 'outgoing',
            'last_replied_by'        => $userId,
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
            'caption'      => $caption !== '' ? $caption : null,
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
            if (!empty($json['media_ref']) && is_array($json['media_ref'])
                && !empty($json['media_ref']['direct_path']) && !empty($json['media_ref']['media_key_base64'])) {
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

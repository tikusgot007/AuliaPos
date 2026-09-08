<?php

namespace App\Controllers;

use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\GatewayStatusModel;
use App\Models\UserModel;
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
        $conversations = $conversationModel->orderBy('last_message_at', 'DESC')->findAll(100);

        $gatewayStatusModel = new GatewayStatusModel();
        $gatewayStatus = $this->buildGatewayStatusPayload($gatewayStatusModel);

        $data = [
            'title'         => 'Inbox WhatsApp | AULIA',
            'content'       => 'inbox/index',
            'conversations' => $conversations,
            'gatewayStatus' => $gatewayStatus,
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
        $conversations = $conversationModel->orderBy('last_message_at', 'DESC')->findAll(100);

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
            'conversation' => $conversation,
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

        $conversationModel = new ConversationModel();
        $conversation = $conversationModel->findByChatId($chatId);

        if (!$conversation) {
            $conversationModel->insert([
                'chat_id'      => $chatId,
                'jid_type'     => 'pn',
                'contact_name' => null,
                'phone'        => $phoneClean,
                'status'       => 'open',
                'assigned_to'  => null,
            ]);
            $conversationId = $conversationModel->getInsertID();
        } else {
            // Nomor ini sudah pernah chat sebelumnya -- pakai
            // conversation yang sudah ada, jangan bikin duplikat.
            $conversationId = (int) $conversation['id'];
        }

        return $this->kirimKeConversation($conversationId, $chatId, $text);
    }

    /**
     * POST /inbox/kirim
     *
     * Kasir/admin kirim balasan text ke satu conversation YANG SUDAH
     * ADA. Untuk mulai chat ke nomor baru, lihat mulaiPercakapan().
     *
     * SENGAJA belum ada ownership restriction (siapa saja yang login
     * boleh membalas conversation manapun) -- sesuai spec Phase 3:
     * "karena assignment belum diimplementasikan, jangan membuat
     * ownership restriction dulu."
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

        return $this->kirimKeConversation($conversationId, $conversation['chat_id'], $text);
    }

    /**
     * Logic inti kirim pesan (dipakai bersama oleh kirim() dan
     * mulaiPercakapan(), supaya tidak duplikat kode).
     *
     * Alur (persis sesuai spec): cek Gateway usable -> panggil
     * Gateway HTTP -> HANYA simpan sebagai outgoing 'sent' &
     * update conversation KALAU Gateway konfirmasi sukses. Kalau
     * gagal di titik manapun, TIDAK ada yang disimpan ke database
     * sama sekali -- browser cukup diberi tahu gagal, silakan retry
     * (tidak ada outgoing queue, sesuai spec: "Gateway offline =>
     * reject segera", "tidak boleh membuat outgoing queue").
     */
    private function kirimKeConversation(int $conversationId, string $chatId, string $text)
    {
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

        $conversationModel = new ConversationModel();
        $conversationModel->update($conversationId, [
            'last_message_at'        => $now,
            'last_message_direction' => 'outgoing',
            'last_replied_by'        => $userId,
        ]);

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
        // Buang semua karakter selain digit (termasuk spasi, strip,
        // tanda kurung); '+' di depan ditangani terpisah di bawah.
        $hasPlus   = str_starts_with(trim($input), '+');
        $digitsOnly = preg_replace('/\D/', '', $input);

        if ($hasPlus && str_starts_with($digitsOnly, '62')) {
            $normalized = $digitsOnly; // "+62xxx" -> "62xxx"
        } elseif (str_starts_with($digitsOnly, '62')) {
            $normalized = $digitsOnly; // sudah "62xxx"
        } elseif (str_starts_with($digitsOnly, '0')) {
            $normalized = '62' . substr($digitsOnly, 1); // "08xxx" -> "628xxx"
        } else {
            return null; // format tidak dikenali -- jangan menebak
        }

        // Validasi panjang wajar untuk nomor Indonesia (62 + 8-13 digit).
        if (strlen($normalized) < 10 || strlen($normalized) > 15 || !ctype_digit($normalized)) {
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
}

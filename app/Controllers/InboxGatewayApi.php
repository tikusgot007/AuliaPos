<?php

namespace App\Controllers;

use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\GatewayStatusModel;
use App\Libraries\PhoneNumber;
use Config\Database;

/**
 * Endpoint machine-to-machine untuk Gateway WhatsApp (Node.js/Baileys)
 * -- BUKAN untuk dipanggil dari browser kasir. Diproteksi Bearer
 * token lewat filter 'gatewaytoken' (lihat Routes.php & 
 * app/Filters/GatewayTokenFilter.php), dan sengaja dikecualikan dari
 * filter session 'auth' (lihat app/Config/Filters.php) karena
 * Gateway tidak pernah punya session/cookie.
 *
 * Phase 2 dari module Shared WhatsApp Inbox -- lihat
 * docs/aturan-bisnis-AULIA.md Section 28/29 untuk konteks lengkap.
 *
 * SEMUA method di sini pakai koneksi database 'inbox'
 * (aulia_inboxdb), TIDAK PERNAH koneksi default AuliaPos.
 */
class InboxGatewayApi extends BaseController
{
    /**
     * POST /api/inbox/gateway/messages
     *
     * Menerima 1 pesan (text/image/document/audio/video) dari Gateway.
     * Idempotent berdasarkan
     * wa_message_id -- request yang sama dikirim ulang (mis. karena
     * Gateway retry setelah timeout) TIDAK akan membuat baris message
     * kedua.
     *
     * Field 'direction' (opsional, default 'incoming' untuk kompatibel
     * dengan kontrak Phase 2 awal):
     * - 'incoming' -- pesan asli dari customer (fromMe=false di
     *   Baileys). Selalu membuka kembali conversation (status=open).
     * - 'outgoing' -- balasan staff yang dikirim LANGSUNG dari WA
     *   Web/HP (fromMe=true di Baileys), BUKAN lewat POS. Gateway
     *   tidak tahu staff mana yang membalas, jadi disimpan dengan
     *   sent_by_user_id=NULL. TIDAK memaksa conversation jadi 'open'
     *   (beda dari 'incoming') -- cukup update last_message_at/
     *   last_message_direction, supaya Inbox POS tetap merefleksikan
     *   kenyataan tanpa mengubah keputusan status secara tidak sengaja.
     */
    public function messages()
    {
        $payload = $this->request->getJSON(true) ?? [];

        // --- Validasi minimal ---------------------------------------------
        $required = ['wa_message_id', 'chat_id', 'jid_type', 'message_type', 'message_timestamp'];
        foreach ($required as $field) {
            if (empty($payload[$field]) && $payload[$field] !== '0') {
                return $this->response->setStatusCode(400)->setJSON([
                    'status'  => 'error',
                    'message' => "Field '{$field}' wajib diisi.",
                ]);
            }
        }

        $waMessageId = (string) $payload['wa_message_id'];
        $chatId      = (string) $payload['chat_id'];
        $jidType     = (string) $payload['jid_type'];
        $messageType = (string) $payload['message_type'];
        $text        = isset($payload['text']) ? (string) $payload['text'] : null;

        $direction = ($payload['direction'] ?? 'incoming') === 'outgoing' ? 'outgoing' : 'incoming';

        if ($messageType === 'text' && ($text === null || $text === '')) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => "Field 'text' wajib diisi untuk message_type='text'.",
            ]);
        }

        // --- Validasi & ekstrak referensi media (image/document) -------------
        // Untuk image/document, 'text' adalah CAPTION (opsional, boleh
        // kosong) -- BEDA dari message_type='text' di atas yang wajib.
        // File-nya sendiri TIDAK dikirim ke sini -- cuma referensi
        // (directPath + mediaKey) untuk didekripsi ulang ON-DEMAND
        // nanti saat kasir benar-benar membuka pesannya (lihat
        // Inbox::media()). Sesuai keputusan desain: simpan referensi
        // saja, bukan file permanen.
        $mediaColumns = [
            'media_path'      => null, // SENGAJA selalu NULL -- tidak pernah menyimpan file lokal.
            'media_mime_type' => null,
            'media_filename'  => null,
            'media_size'      => null,
            'media_sha256'    => null,
            'media_metadata'  => null,
        ];

        if (in_array($messageType, ['image', 'document'], true)) {
            $media = $payload['media'] ?? null;

            if (!is_array($media) || empty($media['direct_path'] ?? $media['directPath'] ?? null)
                || empty($media['media_key_base64'] ?? $media['mediaKeyBase64'] ?? null)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status'  => 'error',
                    'message' => "Field 'media' (direct_path + media_key_base64) wajib diisi untuk message_type='{$messageType}'.",
                ]);
            }

            // Terima snake_case ATAUPUN camelCase untuk toleransi kecil
            // terhadap variasi penamaan dari sisi Gateway.
            $directPath     = $media['direct_path'] ?? $media['directPath'];
            $mediaKeyBase64 = $media['media_key_base64'] ?? $media['mediaKeyBase64'];
            $mimetype       = $media['mimetype'] ?? null;
            $fileLength     = $media['file_length'] ?? $media['fileLength'] ?? null;
            $fileSha256B64  = $media['file_sha256_base64'] ?? $media['fileSha256Base64'] ?? null;
            $fileName       = $media['file_name'] ?? $media['fileName'] ?? null;

            $mediaColumns['media_mime_type'] = $mimetype;
            $mediaColumns['media_filename']  = $fileName;
            $mediaColumns['media_size']      = $fileLength !== null ? (int) $fileLength : null;
            // Kolom media_sha256 CHAR(64) -- sha256 dalam hex, bukan
            // base64 (base64 dari Gateway perlu di-decode dulu).
            $mediaColumns['media_sha256'] = $fileSha256B64 ? bin2hex(base64_decode($fileSha256B64)) : null;
            // media_metadata: SATU-SATUNYA tempat referensi yang benar-benar
            // dibutuhkan untuk fetch ulang nanti (direct_path + media_key).
            $mediaColumns['media_metadata'] = json_encode([
                'media_type'       => $messageType,
                'direct_path'      => $directPath,
                'media_key_base64' => $mediaKeyBase64,
            ]);
        } elseif (in_array($messageType, ['audio', 'video'], true)) {
            // BEDA PRINSIP dari image/document: audio/video TIDAK PERNAH
            // dibuka ulang lewat Inbox (lihat keputusan desain Task Group
            // 1 -- UI cuma menampilkan placeholder "cek WhatsApp Web"),
            // jadi TIDAK ADA referensi (direct_path/media_key) yang perlu
            // disimpan sama sekali -- media_metadata TETAP NULL. 'media'
            // di sini SEPENUHNYA opsional, cuma metadata ringan
            // (mimetype/ukuran) kalau Gateway kebetulan mengirimkannya --
            // request TETAP diterima walau 'media' kosong/tidak ada.
            $media = $payload['media'] ?? null;

            if (is_array($media)) {
                $mimetype   = $media['mimetype'] ?? null;
                $fileLength = $media['file_length'] ?? $media['fileLength'] ?? null;

                $mediaColumns['media_mime_type'] = $mimetype !== null ? (string) $mimetype : null;
                $mediaColumns['media_size']      = $fileLength !== null ? (int) $fileLength : null;
            }
        }

        $messageTimestamp = $this->parseTimestamp($payload['message_timestamp']);
        if ($messageTimestamp === null) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => "Field 'message_timestamp' tidak valid.",
            ]);
        }

        $messageModel      = new MessageModel();
        $conversationModel = new ConversationModel();

        // --- Idempotency: cek duplikat SEBELUM transaksi -------------------
        // Sesuai spec: "Jika pesan dengan wa_message_id yang sama dikirim
        // ulang, jangan membuat message kedua, kembalikan response yang
        // menandakan message sudah diterima / duplicate-safe."
        if ($messageModel->existsByWaMessageId($waMessageId)) {
            return $this->response->setStatusCode(200)->setJSON([
                'status'    => 'success',
                'duplicate' => true,
                'message'   => 'Message sudah pernah diterima sebelumnya (idempotent).',
            ]);
        }

        $db = Database::connect('inbox');
        $db->transStart();

        // --- Cari/buat conversation (Task Group 1.5: reconciliation) --------
        // canonicalPhone HANYA diisi kalau jid_type='pn' (nomor ter-verifikasi
        // WhatsApp asli, di-derive Gateway dari @s.whatsapp.net) -- TIDAK
        // PERNAH untuk @lid/@g.us, sesuai aturan "jangan menebak nomor dari
        // @lid". Ini SATU-SATUNYA sinyal yang dipercaya untuk mencegah
        // duplicate conversation saat JID berubah (lihat
        // ConversationModel::resolveConversationId()).
        $canonicalPhone = null;
        if ($jidType === 'pn' && !empty($payload['phone'])) {
            $canonicalPhone = PhoneNumber::normalize((string) $payload['phone']);
        }
        $whatsappNameFromPayload = !empty($payload['contact_name']) ? (string) $payload['contact_name'] : null;

        // Revisi LID-FIRST -> PN-LATER (Task Group 1.5): $identity_hint.lid
        // HANYA dipercaya kalau pesan ini sendiri jid_type='pn' -- defense in
        // depth (Gateway seharusnya sudah tidak pernah mengisi ini untuk
        // pesan @lid, tapi CI4 tidak boleh bergantung buta ke situ). Lihat
        // ConversationModel::resolveConversationId() untuk cara pemakaiannya.
        $knownLid = null;
        if ($jidType === 'pn' && !empty($payload['identity_hint']['lid'])) {
            $knownLid = (string) $payload['identity_hint']['lid'];
        }

        $resolved = $conversationModel->resolveConversationId($chatId, $jidType, $canonicalPhone, $whatsappNameFromPayload, $knownLid);
        $conversationId = $resolved['conversation_id'];
        $conversation   = $conversationModel->find($conversationId);

        if ($resolved['reconciled']) {
            $viaApa = $knownLid !== null ? "LID hint ({$knownLid})" : "nomor ({$canonicalPhone})";
            log_message('info', "InboxGatewayApi::messages() reconciliation: chat_id={$chatId} (jid_type={$jidType}) ditempelkan ke conversation_id={$conversationId} yang sudah ada lewat {$viaApa}.");
        }

        // whatsapp_name SELALU dimutakhirkan (push name boleh berubah kapan
        // saja, TIDAK dilindungi) -- BEDA dari contact_name (nama manual
        // customer profile) yang TIDAK PERNAH disentuh di sini sama sekali,
        // hanya lewat Inbox::updateCustomerProfile(). phone (ter-verifikasi)
        // juga selalu dimutakhirkan untuk jid_type='pn' -- idempotent/aman
        // karena satu chat_id @pn seharusnya konsisten dengan nomor yang sama.
        if (!$resolved['created']) {
            $update = [];
            if ($whatsappNameFromPayload !== null && $whatsappNameFromPayload !== $conversation['whatsapp_name']) {
                $update['whatsapp_name'] = $whatsappNameFromPayload;
            }
            if ($canonicalPhone !== null && $canonicalPhone !== $conversation['phone']) {
                $update['phone'] = $canonicalPhone;
            }
            if ($update) {
                $conversationModel->update($conversationId, $update);
            }
        }

        // --- Insert message --------------------------------------------------
        $messageModel->insert(array_merge([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => $waMessageId,
            'direction'         => $direction,
            'message_type'      => $messageType,
            'sender_jid'        => $payload['sender_jid'] ?? null,
            'text'              => $text,
            'message_timestamp' => $messageTimestamp,
            // Balasan sinkron dari WA Web/HP TIDAK bisa diketahui staff
            // mana yang mengirim -- Baileys/Gateway tidak punya info itu.
            'sent_by_user_id'   => null,
            'send_status'       => $direction === 'outgoing' ? 'sent' : 'received',
        ], $mediaColumns));

        // --- Update conversation ---------------------------------------------
        $conversationUpdate = [
            'last_message_at'        => $messageTimestamp,
            'last_message_direction' => $direction,
        ];

        // Sesuai spec: "incoming message selalu membuat conversation
        // open" -- HANYA berlaku untuk incoming. Balasan outgoing yang
        // disinkronkan dari WA Web/HP TIDAK memaksa status berubah,
        // supaya tidak membuka kembali conversation yang sengaja
        // ditutup hanya karena staff membalas dari luar POS.
        if ($direction === 'incoming') {
            $conversationUpdate['status'] = 'open';
        }

        $conversationModel->update($conversationId, $conversationUpdate);

        $db->transComplete();

        if ($db->transStatus() === false) {
            log_message('error', 'InboxGatewayApi::messages() gagal commit transaksi untuk wa_message_id=' . $waMessageId);

            return $this->response->setStatusCode(500)->setJSON([
                'status'  => 'error',
                'message' => 'Gagal menyimpan pesan (transaksi database gagal).',
            ]);
        }

        log_message('info', "InboxGatewayApi::messages() sukses menyimpan pesan ({$direction}). chat_id={$chatId}, wa_message_id={$waMessageId}");

        return $this->response->setStatusCode(200)->setJSON([
            'status'          => 'success',
            'duplicate'       => false,
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * POST /api/inbox/gateway/status
     *
     * Heartbeat dari Gateway (dikirim tiap ~15 detik sesuai spec).
     * Selalu upsert baris tunggal id=1 di gateway_status.
     */
    public function status()
    {
        $payload = $this->request->getJSON(true) ?? [];

        $status = isset($payload['status']) ? (string) $payload['status'] : '';
        $validStatuses = ['connected', 'connecting', 'disconnected', 'logged_out'];

        if (!in_array($status, $validStatuses, true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => "Field 'status' wajib salah satu dari: " . implode(', ', $validStatuses),
            ]);
        }

        // Eksplisit Asia/Jakarta -- lihat catatan bug jam di
        // parseTimestamp() di atas.
        $now = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');

        $data = [
            'status'            => $status,
            'phone'             => $payload['phone'] ?? null,
            'gateway_version'   => $payload['gateway_version'] ?? null,
            'last_heartbeat_at' => $now,
        ];

        if ($status === 'connected') {
            $data['last_connected_at'] = $now;
        }

        $model = new GatewayStatusModel();
        $ok    = $model->upsertStatus($data);

        if (!$ok) {
            log_message('error', 'InboxGatewayApi::status() gagal upsert gateway_status. Payload: ' . json_encode($payload));

            return $this->response->setStatusCode(500)->setJSON([
                'status'  => 'error',
                'message' => 'Gagal menyimpan status gateway.',
            ]);
        }

        return $this->response->setStatusCode(200)->setJSON(['status' => 'success']);
    }

    /**
     * Parse timestamp dari Gateway (SELALU UTC, format ISO 8601
     * dengan akhiran 'Z', mis. "2026-09-07T10:07:10.000Z" -- lihat
     * connectionManager.js Gateway, pakai .toISOString() yang
     * standarnya UTC) menjadi format DATETIME MySQL DALAM WAKTU
     * LOKAL (Asia/Jakarta).
     *
     * BUG YANG PERNAH TERJADI: versi awal fungsi ini cuma
     * memformat() DateTime apa adanya tanpa konversi timezone --
     * hasilnya nilai UTC tersimpan seolah-olah itu waktu lokal,
     * membuat semua jam pesan tampil mundur 7 jam dari jam Jakarta
     * asli. Fungsi ini SELALU eksplisit convert ke Asia/Jakarta,
     * TIDAK bergantung ke setting timezone default PHP di server
     * (yang mungkin belum tentu WIB).
     *
     * Mengembalikan null kalau tidak bisa di-parse sama sekali.
     */
    private function parseTimestamp($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            $dt = new \DateTime((string) $value);
            $dt->setTimezone(new \DateTimeZone('Asia/Jakarta'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}

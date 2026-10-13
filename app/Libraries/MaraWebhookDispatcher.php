<?php

namespace App\Libraries;

use Config\Mara as MaraConfig;

/**
 * Pembentuk + pengirim event `inbox-event` REALTIME ke M.A.R.A, SATU
 * titik untuk semua jalur yang menulis baris `messages` (ARCH-001
 * single builder, sama alasan dengan
 * MessageModel::findByOperationIdIncludingDeleted() yang dipakai
 * bersama oleh kirimKeConversation()/kirimMedia()).
 *
 * Dipanggil SETELAH baris `messages` + update `conversations` berhasil
 * COMMIT (best-effort, DILUAR transaksi DB utama) -- dari:
 *  - InboxGatewayApi::messages()      (pesan MASUK dari pelanggan)
 *  - Inbox::kirimTeksViaGateway()     (kasir kirim teks KELUAR)
 *  - Inbox::kirimMedia()              (kasir kirim media KELUAR)
 *  - Inbox::catatan()/nudgeUnduh()    (catatan internal KELUAR, is_internal)
 *
 * Payload fields mengikuti jawaban M.A.R.A via Telegram (2026-10-13):
 *   event, event_id, conversation_id, chat_id, jid_type, direction,
 *   message_type, text, is_internal, wa_message_id, timestamp,
 *   contact_name/whatsapp_name (kalau ada). SENGAJA TIDAK menyertakan
 *   nomor telepon mentah atau isi berkas media (hanya jenisnya) --
 *   M.A.R.A meminta keduanya DIHILANGKAN dari payload webhook.
 */
class MaraWebhookDispatcher
{
    public function __construct(
        private readonly MaraWebhookClient $client,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(new MaraWebhookClient(new MaraConfig()));
    }

    /**
     * Bangun payload dari satu baris `messages` (array seperti yang
     * dikembalikan MessageModel/query builder) + ringkasan conversation
     * terkait, lalu kirim ke M.A.R.A. Gagal dicatat ke log, TIDAK PERNAH
     * dilempar sebagai exception ke pemanggil (best-effort).
     *
     * @param array<string, mixed> $message      Baris `messages` yang baru di-insert.
     * @param array<string, mixed> $conversation Baris `conversations` terkait.
     */
    public function dispatchAfterInsert(array $message, array $conversation): void
    {
        if (!$this->client->isConfigured()) {
            // Webhook belum dikonfigurasi (TODO-MARA1: menunggu alamat
            // final M.A.R.A) -- no-op senyap, BUKAN error: AuliaPos tetap
            // harus jalan normal tanpa M.A.R.A.
            return;
        }

        $event = $this->buildEvent($message, $conversation);

        $result = $this->client->sendInboxEvent($event);

        if (!$result['ok']) {
            log_message(
                'warning',
                'MaraWebhookDispatcher: gagal kirim event ke M.A.R.A. event_id=' . $event['event_id']
                . ', conversation_id=' . $event['conversation_id']
                . ', error=' . ($result['error'] ?? 'unknown')
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEvent(array $message, array $conversation): array
    {
        $direction = (string) ($message['direction'] ?? '');

        return [
            'event'           => $direction === 'incoming' ? 'message.incoming' : 'message.outgoing',
            // ID unik per event -- dikirim JUGA di header `Webhook-Id`
            // (lihat MaraWebhookClient::sendInboxEvent()). messages.id
            // sudah unik & stabil di AuliaPos, cukup sebagai basis tanpa
            // perlu UUID tambahan.
            'event_id'        => 'auliapos-msg-' . $message['id'],
            'conversation_id' => (int) $conversation['id'],
            'chat_id'         => $conversation['chat_id'] ?? null,
            'jid_type'        => $conversation['jid_type'] ?? null,
            'direction'       => $direction,
            'message_type'    => $message['message_type'] ?? null,
            // SENGAJA null, bukan '', untuk pesan tanpa teks (file/media
            // tanpa caption) -- konsisten dengan jawaban M.A.R.A "text
            // boleh kosong kalau cuma kirim file".
            'text'            => $message['text'] ?? null,
            'is_internal'     => (bool) ($message['is_internal'] ?? false),
            'wa_message_id'   => $message['wa_message_id'] ?? null,
            'timestamp'       => $this->withTimezone($message['message_timestamp'] ?? null),
            // Identitas customer TANPA nomor telepon mentah (M.A.R.A
            // minta dihilangkan) -- hanya nama, konsisten dengan prinsip
            // anonimisasi jalur training (lihat Config\Mara).
            'contact_name'    => $conversation['contact_name'] ?? null,
            'whatsapp_name'   => $conversation['whatsapp_name'] ?? null,
        ];
    }

    /**
     * `messages.message_timestamp` tersimpan tanpa offset zona waktu
     * (string 'Y-m-d H:i:s', selalu Asia/Jakarta -- lihat catatan bug jam
     * di InboxGatewayApi::parseTimestamp()). M.A.R.A minta "timestamp
     * dengan zona waktu", jadi offset +07:00 ditempel eksplisit di sini,
     * BUKAN diasumsikan dari timezone default PHP server.
     */
    private function withTimezone(?string $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return (new \DateTime($timestamp, new \DateTimeZone('Asia/Jakarta')))->format('c');
        } catch (\Throwable) {
            return $timestamp;
        }
    }
}

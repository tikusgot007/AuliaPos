<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\PhoneNumber;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use Config\Inbox;

class InboxReconcile extends BaseController
{
    /**
     * Endpoint rekonsiliasi fail-safe WAGRAB -> AuliaPos Inbox.
     * POST /api/inbox/sync-missing
     *
     * Header: X-Internal-Token: <token>
     * Body: JSON array pesan atau { "messages": [...] }
     */
    public function syncMissing()
    {
        if (! $this->validateToken()) {
            return $this->response->setStatusCode(401)->setJSON([
                'status'  => 'error',
                'message' => 'Unauthorized: token internal tidak valid.',
            ]);
        }

        $rawJson = $this->request->getJSON(true);
        if ($rawJson === null) {
            $rawJson = json_decode($this->request->getBody(), true);
        }
        if ($rawJson === null) {
            $post = $this->request->getPost();
            if (! empty($post)) {
                $rawJson = $post;
            }
        }

        if (! is_array($rawJson)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Format payload harus JSON array atau object valid.',
            ]);
        }

        $items = [];
        if (isset($rawJson['messages']) && is_array($rawJson['messages'])) {
            $items = $rawJson['messages'];
        } elseif (array_is_list($rawJson)) {
            $items = $rawJson;
        } elseif (isset($rawJson['wa_message_id'])) {
            $items = [$rawJson];
        }

        $inserted = 0;
        $ignored  = 0;

        if (empty($items)) {
            return $this->response->setJSON([
                'status'   => 'ok',
                'inserted' => $inserted,
                'ignored'  => $ignored,
            ]);
        }

        $conversationModel = new ConversationModel();
        $messageModel      = new MessageModel();

        foreach ($items as $item) {
            if (! is_array($item)) {
                $ignored++;
                continue;
            }

            $waMessageId = trim((string) ($item['wa_message_id'] ?? ''));
            if ($waMessageId === '') {
                $ignored++;
                continue;
            }

            // Cek idempotensi: lewati jika pesan sudah tersimpan
            if ($messageModel->withDeleted()->where('wa_message_id', $waMessageId)->countAllResults() > 0) {
                $ignored++;
                continue;
            }

            // Normalisasi identitas pengirim / conversation
            $phoneRaw = (string) ($item['phone_raw'] ?? $item['phone'] ?? $item['chat_id'] ?? $item['chat_jid'] ?? '');
            $canonicalPhone = PhoneNumber::normalize($phoneRaw);

            if ($canonicalPhone !== null) {
                $chatId  = $canonicalPhone . '@s.whatsapp.net';
                $jidType = 'pn';
            } else {
                $chatId = trim((string) ($item['chat_jid'] ?? $item['chat_id'] ?? ''));
                if ($chatId === '') {
                    $ignored++;
                    continue;
                }
                $jidType = str_contains($chatId, '@g.us') ? 'group' : 'pn';
                $digits  = preg_replace('/\D/', '', explode('@', $chatId)[0]);
                $canonicalPhone = PhoneNumber::normalize($digits);
            }

            $chatLabel = trim((string) ($item['chat_label'] ?? $item['whatsapp_name'] ?? ''));

            try {
                $resolved = $conversationModel->resolveConversationId(
                    $chatId,
                    $jidType,
                    $canonicalPhone,
                    $chatLabel !== '' ? $chatLabel : null
                );
                $conversationId = (int) ($resolved['conversation_id'] ?? 0);
            } catch (\Throwable $e) {
                $conversationId = 0;
            }

            if ($conversationId <= 0) {
                $ignored++;
                continue;
            }

            // Normalisasi tipe pesan
            $rawType = strtolower(trim((string) ($item['message_type'] ?? 'text')));
            if (in_array($rawType, ['chat', 'text', 'conversation', 'extendedtextmessage'], true)) {
                $messageType = 'text';
            } elseif (in_array($rawType, ['image', 'photo', 'imagemessage'], true)) {
                $messageType = 'image';
            } elseif (in_array($rawType, ['document', 'doc', 'pdf', 'documentmessage'], true)) {
                $messageType = 'document';
            } elseif (in_array($rawType, ['audio', 'ptt', 'voice', 'audiomessage'], true)) {
                $messageType = 'audio';
            } elseif (in_array($rawType, ['video', 'videomessage'], true)) {
                $messageType = 'video';
            } elseif (in_array($rawType, ['sticker', 'stickermessage'], true)) {
                $messageType = 'sticker';
            } else {
                $messageType = $rawType ?: 'text';
            }

            // Format isi teks dan prefix media
            $rawBody = (string) ($item['caption'] ?? $item['text'] ?? '');
            if ($messageType !== 'text') {
                $text = '[AUTO-HEAL WAGRAB]' . ($rawBody !== '' ? ' ' . $rawBody : '');
            } else {
                $text = $rawBody;
            }

            // Format message_timestamp
            $rawTs = $item['message_timestamp'] ?? $item['ts'] ?? null;
            if (is_numeric($rawTs)) {
                $tsNum = (float) $rawTs;
                if ($tsNum > 100000000000) {
                    $tsNum = $tsNum / 1000;
                }
                $messageTimestamp = date('Y-m-d H:i:s', (int) $tsNum);
            } elseif (! empty($rawTs) && is_string($rawTs)) {
                $parsed = strtotime($rawTs);
                $messageTimestamp = $parsed ? date('Y-m-d H:i:s', $parsed) : date('Y-m-d H:i:s');
            } else {
                $messageTimestamp = date('Y-m-d H:i:s');
            }

            $messageData = [
                'conversation_id'   => $conversationId,
                'wa_message_id'     => $waMessageId,
                'direction'         => 'incoming',
                'is_internal'       => 0,
                'message_type'      => $messageType,
                'sender_jid'        => $item['sender_jid'] ?? $chatId,
                'text'              => $text,
                'media_path'        => null,
                'media_filename'    => $item['file_name'] ?? null,
                'message_timestamp' => $messageTimestamp,
                'send_status'       => 'received',
                'created_at'        => date('Y-m-d H:i:s'),
            ];

            try {
                $insertedId = $messageModel->insert($messageData);
                if ($insertedId) {
                    $conversationModel->updateLastMessageIfNewer(
                        $conversationId,
                        $messageTimestamp,
                        'incoming',
                        ['status' => 'open']
                    );
                    $inserted++;
                } else {
                    $ignored++;
                }
            } catch (\Throwable $e) {
                // Tangani benturan duplikat atau constraint error
                $ignored++;
            }
        }

        return $this->response->setJSON([
            'status'   => 'ok',
            'inserted' => $inserted,
            'ignored'  => $ignored,
        ]);
    }

    /**
     * Validasi token X-Internal-Token atau fallback internal.
     */
    protected function validateToken(): bool
    {
        $expected = (string) (env('INTERNAL_API_TOKEN') ?: env('inbox.reconcileToken') ?: (new Inbox())->gatewayToken);
        if ($expected === '') {
            return false;
        }

        $header = $this->request->getHeaderLine('X-Internal-Token');
        if ($header === '') {
            $authHeader = $this->request->getHeaderLine('Authorization');
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $header = $matches[1];
            }
        }

        return hash_equals($expected, trim($header));
    }
}

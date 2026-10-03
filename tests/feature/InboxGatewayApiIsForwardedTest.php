<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * Regression test for TODO-F5 (docs/TODO.md): incoming WhatsApp messages that
 * the customer FORWARDED were never flagged in the Inbox.
 *
 * Gateway (evolution-gateway, src/evolution/normalize.js extractForwardFlag())
 * now reads `contextInfo.isForwarded` / `forwardingScore` from the raw webhook
 * and sends an OPTIONAL `is_forwarded: true` field to this endpoint for
 * incoming forwards only. This controller (InboxGatewayApi::messages()) must:
 *   - persist it into the EXISTING `messages.is_forwarded` column (no new
 *     migration -- the column already drives the "Diteruskan" UI label), and
 *   - treat the value as untrusted input from the Gateway (AGENTS.md SS6):
 *     force it to a boolean, so a malformed value degrades to `0` instead of
 *     failing message ingestion.
 *
 * The field is OPTIONAL in the contract: ordinary (non-forwarded) messages do
 * not send it, so absence must mean `0` (payload shape for old Gateway
 * versions stays unchanged).
 *
 * Runs against the `inbox` group, redirected to `aulia_inboxdb_test` under
 * testing (tests/_support/bootstrap-feature.php, app/Config/Database.php).
 *
 * @internal
 */
final class InboxGatewayApiIsForwardedTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
    }

    protected function tearDown(): void
    {
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function isForwarded(string $waMessageId): ?int
    {
        $row = $this->inbox->table('messages')
            ->select('is_forwarded')
            ->where('wa_message_id', $waMessageId)
            ->get()
            ->getRowArray();

        return $row === null ? null : (int) $row['is_forwarded'];
    }

    public function testIncomingForwardedMessageIsFlagged(): void
    {
        $chatId = '6281300000001@s.whatsapp.net';
        $this->seedConversation($chatId);

        $response = $this->postGatewayMessage([
            'wa_message_id'     => 'WAMSG-FWD-IN-0001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'ini dari acil Yani, pp kirim duit',
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'incoming',
            'is_forwarded'      => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, $this->isForwarded('WAMSG-FWD-IN-0001'), 'Forward masuk harus tersimpan is_forwarded=1.');
    }

    public function testIncomingNormalMessageStaysUnflagged(): void
    {
        $chatId = '6281300000002@s.whatsapp.net';
        $this->seedConversation($chatId);

        // Field `is_forwarded` ABSEN sepenuhnya -- kontrak lama tidak berubah.
        $response = $this->postGatewayMessage([
            'wa_message_id'     => 'WAMSG-FWD-NORMAL-0001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'pesan biasa',
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'incoming',
        ]);

        $response->assertStatus(200);
        $this->assertSame(0, $this->isForwarded('WAMSG-FWD-NORMAL-0001'), 'Pesan biasa tanpa field is_forwarded harus 0.');
    }

    public function testMalformedIsForwardedDegradesToFalse(): void
    {
        $chatId = '6281300000003@s.whatsapp.net';
        $this->seedConversation($chatId);

        // Trust boundary (AGENTS.md SS6): nilai tak tepercaya dari Gateway.
        // Array tidak boleh lolos jadi truthy / menimbulkan TypeError.
        $response = $this->postGatewayMessage([
            'wa_message_id'     => 'WAMSG-FWD-MALFORMED-0001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'payload aneh',
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'incoming',
            'is_forwarded'      => ['bukan', 'boolean'],
        ]);

        $response->assertStatus(200);
        $this->assertSame(0, $this->isForwarded('WAMSG-FWD-MALFORMED-0001'), 'Nilai is_forwarded yang malformed harus didegradasi ke 0.');
    }

    public function testOutgoingSyncedMessageDefaultsToUnflagged(): void
    {
        $chatId = '6281300000004@s.whatsapp.net';
        $this->seedConversation($chatId);

        // Outgoing yang disinkronkan dari WA Web/HP tidak memakai field ini di
        // jalur webhook (cakupan TODO-F5 = masuk saja) -> tetap 0.
        $response = $this->postGatewayMessage([
            'wa_message_id'     => 'WAMSG-FWD-OUT-0001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'staf membalas dari HP',
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'outgoing',
        ]);

        $response->assertStatus(200);
        $this->assertSame(0, $this->isForwarded('WAMSG-FWD-OUT-0001'), 'Outgoing tersinkron tanpa field ini tetap 0.');
    }
}

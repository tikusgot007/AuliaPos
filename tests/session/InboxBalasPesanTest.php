<?php

use App\Controllers\Inbox;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Inbox as GatewayInboxConfig;
use Psr\Log\NullLogger;

/**
 * Balas Pesan (Tahap 3, TASK-002) -- feature test untuk kirim TEKS berkutipan
 * di seam HTTP `Inbox::kirimKeConversation()`.
 *
 * Cakupan: pembentukan snapshot dari DB server (bukan dari kiriman client),
 * aturan `fromMe` yang hanya boleh diturunkan dari kolom `direction`,
 * indikator `quote_applied` di respons, dan guard taksonomi F-A/F-D
 * (sumber tidak ada / lintas percakapan -> 400 tanpa kolom snapshot).
 *
 * @internal
 */
final class InboxBalasPesanTest extends CIUnitTestCase
{
    private ?Inbox $controller = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('aulia_inboxdb_test', db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db);

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversations')->emptyTable();
        $db->table('gateway_status')->emptyTable();
        $db->table('gateway_status')->insert([
            'id'                => 1,
            'status'            => 'connected',
            'last_heartbeat_at' => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        // UserModel::find() membaca tabel `db_users` pada koneksi default --
        // pola yang sama dipakai InboxOutgoingIdempotencyTest.
        db_connect()->query('CREATE TABLE IF NOT EXISTS db_users (id INTEGER PRIMARY KEY, nama TEXT, username TEXT)');
        db_connect()->table('db_users')->delete(['id' => 7]);
        db_connect()->table('db_users')->insert(['id' => 7, 'nama' => 'Test Kasir', 'username' => 'kasir']);

        $_SESSION = ['id_user' => 7, 'role' => 'kasir'];
    }

    protected function tearDown(): void
    {
        $this->controller = null;
        unset($_SESSION);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // AC-001 / REQ-007: kutipan tersimpan sebagai snapshot
    // ------------------------------------------------------------------

    public function testKirimBerkutipanMengisiKeempatKolomSnapshot(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'Kapan pesanan saya dikirim?',
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId]);
        $response   = $this->invoke($controller, $conversationId, 'Baik, akan saya proseskan.');
        $body       = $this->body($controller);

        $this->assertSame(200, $response->getStatusCode());

        $row = $this->lastOutgoing($conversationId);
        $this->assertSame('SRC-' . $sourceId, $row['quoted_wa_message_id'], 'AC-001: ID pesan sumber tersimpan.');
        $this->assertSame('628999888777', $row['quoted_sender_label'], 'AC-001: nama pengirim sumber tersimpan.');
        $this->assertSame('Kapan pesanan saya dikirim?', $row['quoted_snippet'], 'AC-001: cuplikan sumber tersimpan.');
        $this->assertNull($row['quoted_media_available'], 'REQ-008: sumber teks -> NULL.');
        $this->assertTrue($body['quote_applied'], 'REQ-003: quote_applied:true saat Gateway menerapkan kutipan.');
    }

    public function testSnapshotDiambilDariDatabaseServerBukanDariKirimanClient(): void
    {
        // ALT-002: client hanya mengirim quoted_message_id. Kalau ia mencoba
        // menyuntikkan isi kutipan lewat field lain, server harus mengabaikannya.
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'TEKS ASLI DARI DB',
        ]);

        $controller = $this->controller([
            'quoted_message_id'      => $sourceId,
            'quoted_snippet'         => 'TEKS PALSU DARI CLIENT',
            'quoted_sender_label'    => 'PALSU',
            'quoted_media_available' => 0,
        ]);
        $this->invoke($controller, $conversationId, 'Halo');

        $row = $this->lastOutgoing($conversationId);
        $this->assertSame('TEKS ASLI DARI DB', $row['quoted_snippet'], 'ALT-002: snapshot wajib dari DB server.');
        $this->assertNotSame('PALSU', $row['quoted_sender_label']);
    }

    // ------------------------------------------------------------------
    // REQ-001 (F2-1..F2-4): fromMe hanya dari kolom direction
    // ------------------------------------------------------------------

    public function testFromMeHanyaDiturunkanDariKolomDirection(): void
    {
        $conversationId = $this->seedConversation();

        // Baris incoming dengan sender_jid NULL: NULL BUKAN bukti pesan keluar.
        $incomingNullJid = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'dari pelanggan tanpa jid',
            'sender_jid'   => null,
        ]);
        $controller = $this->controller(['quoted_message_id' => $incomingNullJid]);
        $this->invoke($controller, $conversationId, 'balas 1');
        $this->assertFalse($controller->capturedQuoted['fromMe'], 'incoming -> fromMe:false walau sender_jid NULL.');

        // Baris outgoing (sender_jid NULL by design) -> fromMe:true.
        $outgoing = $this->seedMessage($conversationId, [
            'direction'    => 'outgoing',
            'message_type' => 'text',
            'text'         => 'dari kasir sebelumnya',
            'sender_jid'   => null,
        ]);
        $controller = $this->controller(['quoted_message_id' => $outgoing]);
        $this->invoke($controller, $conversationId, 'balas 2');
        $this->assertTrue($controller->capturedQuoted['fromMe'], 'outgoing -> fromMe:true walau sender_jid NULL.');
    }

    // ------------------------------------------------------------------
    // REQ-001: sender_jid TIDAK PERNAH disertakan untuk sumber outgoing
    // ------------------------------------------------------------------

    public function testSenderJidTidakDisertakanUntukSumberOutgoing(): void
    {
        $conversationId = $this->seedConversation();
        // Baris legacy yang kebetulan punya sender_jid terisi -- payload
        // tetap TIDAK boleh menyertakannya karena fromMe:true.
        $outgoing = $this->seedMessage($conversationId, [
            'direction'    => 'outgoing',
            'message_type' => 'text',
            'text'         => 'balasan kasir sebelumnya',
            'sender_jid'   => '628999888777@s.whatsapp.net',
        ]);

        $controller = $this->controller(['quoted_message_id' => $outgoing]);
        $this->invoke($controller, $conversationId, 'balasan baru');

        $this->assertTrue($controller->capturedQuoted['fromMe']);
        $this->assertNull($controller->capturedQuoted['sender_jid'], 'REQ-001: sender_jid null untuk sumber outgoing walau kolom terisi.');
    }

    public function testSenderJidDiteruskanUntukSumberIncoming(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'halo',
            'sender_jid'   => '628999888777@s.whatsapp.net',
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId]);
        $this->invoke($controller, $conversationId, 'halo balik');

        $this->assertSame('628999888777@s.whatsapp.net', $controller->capturedQuoted['sender_jid']);
        $this->assertSame('text', $controller->capturedQuoted['message_type']);
        $this->assertSame('halo', $controller->capturedQuoted['text']);
        $this->assertSame('SRC-' . $sourceId, $controller->capturedQuoted['wa_message_id']);
    }

    public function testSumberMediaMengirimMediaTypeBukanText(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'image',
            'text'         => null,
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId]);
        $this->invoke($controller, $conversationId, 'terima kasih');

        $this->assertSame('image', $controller->capturedQuoted['media_type']);
        $this->assertArrayNotHasKey('text', $controller->capturedQuoted, 'Sumber media tidak mengirim field text.');
    }

    // ------------------------------------------------------------------
    // REQ-006 (reaksi a): quote_applied false
    // ------------------------------------------------------------------

    public function testQuoteAppliedFalseTetapMenyimpanPesanDanMelaporkanPenanda(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);

        // Gateway menjawab sukses tapi TIDAK menerapkan kutipan (CON-001).
        $controller = $this->controller(['quoted_message_id' => $sourceId], ['quote_applied' => false]);
        $response   = $this->invoke($controller, $conversationId, 'isi pesan tetap terkirim');
        $body       = $this->body($controller);

        $this->assertSame(200, $response->getStatusCode(), 'REQ-006: kegagalan kutipan TIDAK menggagalkan pengiriman.');
        $this->assertFalse($body['quote_applied'], 'Kasir diberi tahu kutipan tidak sampai.');
        $this->assertSame('sent', $body['message']['send_status'], 'Pesan tetap tersimpan terkirim.');
        $this->assertNotNull($body['message']['quoted_wa_message_id'], 'Snapshot tetap tercatat apa adanya.');
    }

    public function testGatewayTanpaFieldQuoteAppliedDiperlakukanFalse(): void
    {
        // ASSUMPTION-010: rollout parsial -- Gateway lama tidak mengirim
        // field ini sama sekali. Absen = false, bukan menebak true.
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId], ['quote_applied' => null]);
        $this->invoke($controller, $conversationId, 'halo');
        $body = $this->body($controller);

        $this->assertArrayHasKey('quote_applied', $body);
        $this->assertFalse($body['quote_applied'], 'Field yang hilang diperlakukan false (ASSUMPTION-010).');
    }

    // ------------------------------------------------------------------
    // AC-004: sumber soft-deleted tetap bisa dikutip
    // ------------------------------------------------------------------

    public function testSumberYangSudahSoftDeleteTetapDikutip(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'pesan yang dihapus setelah dipilih',
        ]);

        db_connect('inbox')->table('messages')
            ->where('id', $sourceId)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $controller = $this->controller(['quoted_message_id' => $sourceId]);
        $response   = $this->invoke($controller, $conversationId, 'balas pesan yang dihapus');

        $this->assertSame(200, $response->getStatusCode(), 'AC-004: sumber soft-deleted harus tetap terkirim.');
        $this->assertSame('pesan yang dihapus setelah dipilih', $this->lastOutgoing($conversationId)['quoted_snippet']);
    }

    // ------------------------------------------------------------------
    // Taksonomi F-D: sumber tidak ada -> 400
    // ------------------------------------------------------------------

    public function testSumberTidakAdaDitolakDengan400DanTanpaSnapshot(): void
    {
        $conversationId = $this->seedConversation();
        $controller     = $this->controller(['quoted_message_id' => 999999]);
        $response       = $this->invoke($controller, $conversationId, 'halo');

        $this->assertSame(400, $response->getStatusCode(), 'F-D: ID sumber tidak ada -> 400.');
        $this->assertSame(
            0,
            $this->countOutgoing($conversationId),
            'F-D: tidak ada pesan yang ditulis untuk kasus 400.'
        );
    }

    // ------------------------------------------------------------------
    // F-A: lintas percakapan -> 400 (anti-IDOR)
    // ------------------------------------------------------------------

    public function testKutipanLintasPercakapanDitolakDengan400(): void
    {
        $conversationA = $this->seedConversation('628111111111@s.whatsapp.net');
        $conversationB = $this->seedConversation('628222222222@s.whatsapp.net');
        $sourceId      = $this->seedMessage($conversationA, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'pesan dari percakapan lain',
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId]);
        $response   = $this->invoke($controller, $conversationB, 'mencoba kutip lintas percakapan');

        $this->assertSame(400, $response->getStatusCode(), 'F-A: kutip lintas percakapan -> 400.');
        $this->assertSame(0, $this->countOutgoing($conversationB), 'F-A: tidak ada baris yang ditulis.');
    }

    // ------------------------------------------------------------------
    // SEC-001: otorisasi menang atas resolusi kutipan (anti-oracle)
    // ------------------------------------------------------------------

    public function testKasirTanpaHakTidakDapatMemakaiKutipanSebagaiOracleKeberadaanPesan(): void
    {
        // Percakapan A ditangani kasir id 7 (pemilik sesi) dan berisi pesan
        // NYATA. Percakapan B ditangani user LAIN, sehingga kasir id 7 TIDAK
        // berhak membalasnya (cekOwnership() -> 403).
        $conversationA = $this->seedConversation('628111111111@s.whatsapp.net');
        $conversationB = $this->seedConversation('628222222222@s.whatsapp.net', 9);
        $sourceId      = $this->seedMessage($conversationA, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'pesan nyata di percakapan lain',
        ]);

        // Request 1: kutipan menunjuk pesan yang BENAR-BENAR ADA (tapi di
        // percakapan lain). Request 2: kutipan menunjuk ID yang TIDAK ADA.
        // Keduanya harus menghasilkan jawaban yang TIDAK BISA DIBEDAKAN.
        $controllerAda = $this->controller(['quoted_message_id' => $sourceId]);
        $responseAda   = $this->invoke($controllerAda, $conversationB, 'mencoba membalas');
        $bodyAda       = $this->body($controllerAda);

        $controllerTidakAda = $this->controller(['quoted_message_id' => 999999]);
        $responseTidakAda   = $this->invoke($controllerTidakAda, $conversationB, 'mencoba membalas');
        $bodyTidakAda       = $this->body($controllerTidakAda);

        // Otorisasi menang: 403 (BUKAN 400), dan tak satu pun baris ditulis.
        $this->assertSame(403, $responseAda->getStatusCode(), 'SEC-001: kasir tanpa hak -> 403 sebelum menyentuh kutipan.');
        $this->assertSame(403, $responseTidakAda->getStatusCode(), 'SEC-001: ID tidak ada pun -> 403, bukan 400.');
        $this->assertSame(0, $this->countOutgoing($conversationB), 'SEC-001: tidak ada baris yang ditulis.');

        // Tidak ada oracle: pesan respons identik, tidak menyebut status
        // keberadaan pesan sumber (tidak ada kata "dikutip").
        $this->assertSame(
            $bodyAda['message'],
            $bodyTidakAda['message'],
            'SEC-001: respons tidak boleh membedakan pesan yang ada vs tidak ada.'
        );
        $this->assertStringNotContainsString('dikutip', (string) $bodyAda['message'], 'SEC-001: tidak membocorkan resolusi kutipan.');
    }

    // ------------------------------------------------------------------
    // GUD-001: tanpa kutipan, keempat kolom tetap NULL
    // ------------------------------------------------------------------

    public function testKirimTanpaKutipanTidakMengisiKolomKutipan(): void
    {
        $conversationId = $this->seedConversation();
        $controller     = $this->controller([]);
        $response       = $this->invoke($controller, $conversationId, 'pesan biasa');

        $this->assertSame(200, $response->getStatusCode());

        $row = $this->lastOutgoing($conversationId);
        $this->assertNull($row['quoted_wa_message_id']);
        $this->assertNull($row['quoted_sender_label']);
        $this->assertNull($row['quoted_snippet']);
        $this->assertNull($row['quoted_media_available']);
        $this->assertNull($controller->capturedQuoted, 'Tanpa kutipan, field quoted TIDAK dikirim ke Gateway.');
    }

    public function testResponsTanpaKutipanTidakMenyertakanQuoteApplied(): void
    {
        // Permintaan biasa harus berperilaku persis seperti sebelumnya
        // (REQ-003 hanya berlaku bila request memang berkutipan).
        $conversationId = $this->seedConversation();
        $controller     = $this->controller([]);
        $this->invoke($controller, $conversationId, 'pesan biasa');
        $body = $this->body($controller);

        $this->assertArrayNotHasKey('quote_applied', $body, 'Respons lama tidak berubah (REQ-003/CON-007).');
    }

    // ------------------------------------------------------------------
    // Idempotensi (AC-006) tetap berlaku untuk pesan berkutipan
    // ------------------------------------------------------------------

    public function testReplayDenganOperationIdSamaTidakMenulisBarisKedua(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);

        $post = ['operation_id' => 'ui-quote-1', 'quoted_message_id' => $sourceId];

        $first = $this->controller($post);
        $this->invoke($first, $conversationId, 'balas berkutipan');
        $firstBody = $this->body($first);
        $this->assertFalse($firstBody['replayed'] ?? false);

        $second = $this->controller($post);
        $this->invoke($second, $conversationId, 'balas berkutipan');
        $secondBody = $this->body($second);

        $this->assertTrue($secondBody['replayed'], 'AC-006: replay terdeteksi.');
        $this->assertTrue($secondBody['quote_applied'], 'Replay tetap melaporkan indikator kutipan.');
        $this->assertSame(
            1,
            $this->countOutgoing($conversationId),
            'AC-006: hanya satu baris pesan tersimpan.'
        );
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $gatewayOverrides
     */
    private function controller(array $post, array $gatewayOverrides = []): InboxBalasPesanSpy
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setGlobal('post', $post);
        $controller = new InboxBalasPesanSpy($gatewayOverrides);
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return $controller;
    }

    private function invoke(Inbox $controller, int $conversationId, string $text): \CodeIgniter\HTTP\ResponseInterface
    {
        $method = (new \ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);

        return $method->invoke($controller, $this->conversation($conversationId), $text);
    }

    private function body(Inbox $controller): array
    {
        $property = (new \ReflectionClass($controller))->getParentClass()->getProperty('response');
        $property->setAccessible(true);

        return json_decode($property->getValue($controller)->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedConversation(string $chatId = '628123456789@s.whatsapp.net', int $assignedTo = 7): int
    {
        $db = db_connect('inbox');
        $db->table('conversations')->insert([
            'chat_id'                => $chatId,
            'jid_type'               => 'pn',
            'status'                 => 'open',
            'assigned_to'            => $assignedTo,
            'last_message_direction' => 'incoming',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    /** @param array<string, mixed> $attributes */
    private function seedMessage(int $conversationId, array $attributes): int
    {
        $db = db_connect('inbox');
        $db->table('messages')->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'SRC-TEMP',
            'direction'         => 'incoming',
            'message_type'      => 'text',
            'sender_jid'        => '628999888777@s.whatsapp.net',
            'text'              => null,
            'message_timestamp' => date('Y-m-d H:i:s'),
            'send_status'       => 'received',
        ]);
        $id = (int) $db->insertID();

        $row = ['wa_message_id' => 'SRC-' . $id] + $attributes;
        $db->table('messages')->where('id', $id)->update($row);

        return $id;
    }

    private function conversation(int $id): array
    {
        return db_connect('inbox')->table('conversations')->where('id', $id)->get()->getResultArray()[0];
    }

    /** @return array<string, mixed> */
    private function lastOutgoing(int $conversationId): array
    {
        $rows = db_connect('inbox')->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outgoing')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        $this->assertNotEmpty($rows, 'Harus ada pesan outgoing untuk percakapan ini.');

        return $rows[0];
    }

    private function countOutgoing(int $conversationId): int
    {
        return db_connect('inbox')->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outgoing')
            ->countAllResults();
    }
}

final class InboxBalasPesanSpy extends Inbox
{
    /** @var array<string, mixed>|null Objek `quoted` yang diterima controller. */
    public ?array $capturedQuoted = null;

    public function __construct(private readonly array $gatewayOverrides = [])
    {
    }

    protected function callGatewaySend(GatewayInboxConfig $config, string $chatId, string $text, ?string $operationId = null, ?array $quoted = null): array
    {
        $this->capturedQuoted = $quoted;

        return [
            'ok'            => true,
            'wa_message_id' => 'wa-out-' . bin2hex(random_bytes(4)),
            'quote_applied' => array_key_exists('quote_applied', $this->gatewayOverrides)
                ? $this->gatewayOverrides['quote_applied']
                : true,
        ];
    }
}

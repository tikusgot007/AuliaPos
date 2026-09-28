<?php

use App\Controllers\Inbox;
use App\Libraries\InboxOutgoingRequest;
use CodeIgniter\HTTP\Files\FileCollection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Inbox as GatewayInboxConfig;
use Psr\Log\NullLogger;

/**
 * Teruskan (Tahap 4, TASK-006) -- feature test untuk meneruskan LAMPIRAN
 * (gambar/dokumen/stiker) di seam HTTP `Inbox::kirimMedia()`.
 *
 * Cakupan: payload `/send-media` membawa `forward: true` TANPA `quoted`
 * (CON-001/REQ-001a), byte diambil disk lokal dulu lalu live-fetch Gateway
 * (ASSUMPTION-011), all-or-nothing saat media hilang (CON-002/AC-003),
 * penyalinan atribut media dari baris sumber (TASK-006 (d)), non-stacking
 * `quoted_*` (REQ-009/AC-006/AC-010), penanda `is_forwarded` tunggal
 * (AC-007), dan ownership HANYA pada percakapan tujuan (REQ-007).
 *
 * @internal
 */
final class InboxTeruskanMediaTest extends CIUnitTestCase
{
    private string $mediaDir = '';

    /** @var string|null Nilai $_ENV['inbox.mediaStoragePath'] sebelum test. */
    private ?string $prevMediaStoragePath = null;

    /** @var string|null Nilai $_ENV['inbox.maxMediaUploadMb'] sebelum test. */
    private ?string $prevMaxMediaUploadMb = null;

    /** @var string|null Nilai $_ENV['inbox.maxMediaDownloadMb'] sebelum test. */
    private ?string $prevMaxMediaDownloadMb = null;

    /** Spy terakhir dari panggilResolveForwardMediaSource(). */
    private ?InboxTeruskanMediaSpy $spy = null;

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

        db_connect()->query('CREATE TABLE IF NOT EXISTS db_users (id INTEGER PRIMARY KEY, nama TEXT, username TEXT)');
        db_connect()->table('db_users')->whereIn('id', [7, 9])->delete();
        db_connect()->table('db_users')->insert(['id' => 7, 'nama' => 'Test Kasir', 'username' => 'kasir']);
        db_connect()->table('db_users')->insert(['id' => 9, 'nama' => 'Kasir Lain', 'username' => 'kasir2']);

        $_SESSION = ['id_user' => 7, 'role' => 'kasir'];

        // Media storage di-override ke folder sementara per-test supaya hasil
        // test TIDAK bergantung ke .env mesin mana pun (pola InboxMediaAuthTest).
        $this->mediaDir = sys_get_temp_dir() . '/aulia_inbox_forward_' . bin2hex(random_bytes(4));
        mkdir($this->mediaDir, 0775, true);
        $this->prevMediaStoragePath    = $_ENV['inbox.mediaStoragePath'] ?? null;
        $this->prevMaxMediaDownloadMb  = $_ENV['inbox.maxMediaDownloadMb'] ?? null;
        $_ENV['inbox.mediaStoragePath'] = $this->mediaDir;
    }

    protected function tearDown(): void
    {
        if ($this->prevMediaStoragePath === null) {
            unset($_ENV['inbox.mediaStoragePath']);
        } else {
            $_ENV['inbox.mediaStoragePath'] = $this->prevMediaStoragePath;
        }

        if ($this->prevMaxMediaUploadMb === null) {
            unset($_ENV['inbox.maxMediaUploadMb']);
        } else {
            $_ENV['inbox.maxMediaUploadMb'] = $this->prevMaxMediaUploadMb;
        }

        if ($this->prevMaxMediaDownloadMb === null) {
            unset($_ENV['inbox.maxMediaDownloadMb']);
        } else {
            $_ENV['inbox.maxMediaDownloadMb'] = $this->prevMaxMediaDownloadMb;
        }

        unset($_SESSION);

        // Bersihkan tabel bantu dari koneksi default (`tests`, SQLite
        // `:memory:`) supaya test yang berjalan setelah file ini dan memakai
        // migrasi lengkap (InboxTeruskanScreenTest) tidak menabrak
        // "table `db_users` already exists".
        if (db_connect()->tableExists('db_users')) {
            db_connect()->query('DROP TABLE db_users');
        }

        if ($this->mediaDir !== '' && is_dir($this->mediaDir)) {
            foreach (glob($this->mediaDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->mediaDir);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // AC-009/REQ-001a: forward lampiran sukses dari disk lokal
    // ------------------------------------------------------------------

    public function testTeruskanGambarDariDiskLokalSuksesTanpaLiveFetch(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($this->seedConversation('628111111111@s.whatsapp.net', 9), [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'text'                 => 'Ini foto produknya',
            'media_local_filename' => 'foto.jpg',
            'media_mime_type'      => 'image/jpeg',
            'media_filename'       => 'foto.jpg',
            'media_size'           => 4321,
            'media_metadata'       => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ]);
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'foto.jpg', 'BYTE-DARI-DISK');

        $controller = $this->kirimTeruskan($tujuan, $sumber);
        $body       = $this->body($controller);

        $this->assertSame(200, $this->statusCode($controller));

        // CON-001/REQ-001a: forward:true, dan quoted TIDAK PERNAH ikut.
        $this->assertTrue($controller->capturedForward, 'REQ-001a: forward:true dikirim ke /send-media.');
        $this->assertNull($controller->capturedQuoted, 'CON-001: forward tidak pernah bersama quoted.');
        $this->assertSame('image', $controller->capturedType);
        $this->assertSame('image/jpeg', $controller->capturedMime);
        $this->assertSame('foto.jpg', $controller->capturedFileName);
        $this->assertSame(base64_encode('BYTE-DARI-DISK'), $controller->capturedBase64, 'Byte dari disk lokal, bukan unggahan kasir.');
        $this->assertSame(0, $controller->gatewayMediaDownloadCalls, 'File lokal ada -> JANGAN live-fetch.');

        $row = $this->lastOutgoing($tujuan);
        $this->assertSame(1, (int) $row['is_forwarded'], 'REQ-008: is_forwarded = 1.');
        $this->assertNull($row['media_local_filename'], 'Tidak pernah menulis berkas ke disk (media_local_filename NULL).');
        $this->assertNull($row['media_path'], 'PRD 8.2: media_path tetap NULL.');
        $this->assertNotNull($row['media_metadata'], 'media_ref Gateway tersimpan supaya lampiran bisa dibuka ulang.');
        $this->assertSame('Ini foto produknya', $row['text'], 'Caption sumber ikut tersimpan.');
        $this->assertNull($row['quoted_wa_message_id']);
        $this->assertNull($row['quoted_snippet']);
        $this->assertArrayHasKey('forward_marker_applied', $body);
        $this->assertSame('native', $body['forward_marker_applied']);
    }

    public function testCaptionDiambilDariTeksSumberBukanKirimanBrowser(): void
    {
        // Section 9 "Always do": caption dari DB server, bukan browser.
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'text'                 => 'CAPTION ASLI',
            'media_local_filename' => 'a.jpg',
            'media_mime_type'      => 'image/jpeg',
            'media_filename'       => 'a.jpg',
        ]);
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'a.jpg', 'X');

        $controller = $this->kirimTeruskan($tujuan, $sumber, ['caption' => 'CAPTION PALSU DARI BROWSER']);

        $this->assertSame('CAPTION ASLI', $controller->capturedCaption, 'Caption wajib dari teks sumber.');
        $this->assertSame('CAPTION ASLI', $this->lastOutgoing($tujuan)['text']);
    }

    // ------------------------------------------------------------------
    // ASSUMPTION-011: disk lokal hilang -> live-fetch Gateway
    // ------------------------------------------------------------------

    public function testFileLokalHilangTapiMetadataAdaLiveFetchDanTetapTerkirim(): void
    {
        // Media_local_filename TERCATAT tapi berkasnya tidak ada di disk
        // (HDD eksternal tercabut / storage tidak terpasang) -- byte masih
        // utuh di WhatsApp, jadi live-fetch harus menyelamatkannya.
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'media_local_filename' => 'hilang.jpg',
            'media_mime_type'      => 'image/jpeg',
            'media_filename'       => 'hilang.jpg',
            'media_metadata'       => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ]);

        $controller = $this->kirimTeruskan($tujuan, $sumber);

        $this->assertSame(200, $this->statusCode($controller));
        $this->assertSame(1, $controller->gatewayMediaDownloadCalls, 'ASSUMPTION-011: live-fetch dipanggil saat disk gagal.');
        $this->assertSame(base64_encode('GATEWAY-BYTES'), $controller->capturedBase64);
        $this->assertSame(1, (int) $this->lastOutgoing($tujuan)['is_forwarded']);
    }

    public function testByteBisaJugaDatangSaatTidakAdaBerkasLokalSamaSekali(): void
    {
        // media_local_filename NULL (fitur prefetch tidak aktif) -- jalur
        // normal adalah live-fetch, bukan penolakan.
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'media_local_filename' => null,
            'media_metadata'       => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ]);

        $controller = $this->kirimTeruskan($tujuan, $sumber);

        $this->assertSame(200, $this->statusCode($controller));
        $this->assertSame(1, $controller->gatewayMediaDownloadCalls);
    }

    // ------------------------------------------------------------------
    // CON-002/AC-003: all-or-nothing saat media tidak bisa diperoleh
    // ------------------------------------------------------------------

    public function testMediaHilangPermanenDitolak400TanpaKirim(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'               => 'incoming',
            'message_type'            => 'image',
            'media_local_filename'    => null,
            'media_metadata'          => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
            'media_confirmed_gone_at' => date('Y-m-d H:i:s'),
        ]);

        $controller = $this->kirimTeruskan($tujuan, $sumber);
        $body       = $this->body($controller);

        $this->assertSame(400, $this->statusCode($controller), 'CON-002: media hilang permanen -> 400.');
        $this->assertStringContainsString('tidak tersedia', $body['message'], 'Pesan error jelas ke kasir.');
        $this->assertFalse($controller->gatewaySendMediaCalled, 'TIDAK mengirim apa pun ke Gateway kirim.');
        $this->assertSame(0, $controller->gatewayMediaDownloadCalls, 'Media yang sudah pasti hilang tidak dihubungi lagi.');
        $this->assertSame(0, $this->countOutgoing($tujuan), 'Tidak ada baris yang ditulis.');
    }

    public function testMediaTanpaReferensiSamaSekaliDitolak400(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'media_local_filename' => null,
            'media_metadata'       => null,
        ]);

        $controller = $this->kirimTeruskan($tujuan, $sumber);

        $this->assertSame(400, $this->statusCode($controller));
        $this->assertFalse($controller->gatewaySendMediaCalled);
        $this->assertSame(0, $this->countOutgoing($tujuan));
    }

    public function testLiveFetchGagal410Ditolak400TanpaMengirim(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'media_local_filename' => null,
            'media_metadata'       => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ]);

        $controller = $this->controller([
            'conversation_id'         => $tujuan,
            'forward_from_message_id' => $sumber,
        ]);
        $controller->gatewayMediaResponse = [
            'ok'     => false,
            'status' => 410,
            'error'  => 'Media sudah tidak tersedia (kedaluwarsa).',
        ];
        $controller->kirimMedia();

        $this->assertSame(400, $this->statusCode($controller), 'CON-002: live-fetch 410 -> 400.');
        $this->assertSame(1, $controller->gatewayMediaDownloadCalls);
        $this->assertFalse($controller->gatewaySendMediaCalled, 'Tidak pernah mengirim caption tanpa lampiran.');
        $this->assertSame(0, $this->countOutgoing($tujuan));
    }

    public function testLiveFetchGagalSementaraDitolak400DenganPesanCobaLagi(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'media_local_filename' => null,
            'media_metadata'       => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ]);

        $controller = $this->controller([
            'conversation_id'         => $tujuan,
            'forward_from_message_id' => $sumber,
        ]);
        $controller->gatewayMediaResponse = ['ok' => false, 'status' => 503, 'error' => 'Gateway sibuk'];
        $controller->kirimMedia();
        $body = $this->body($controller);

        $this->assertSame(400, $this->statusCode($controller));
        $this->assertStringContainsString('Coba lagi', $body['message'], 'Kegagalan sementara dibedakan dari media hilang.');
        $this->assertFalse($controller->gatewaySendMediaCalled);
    }

    // ------------------------------------------------------------------
    // TASK-006 (d): dokumen & stiker
    // ------------------------------------------------------------------

    public function testTeruskanDokumenMemakaiAtributSumber(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'document',
            'text'                 => 'Ini invoice-nya',
            'media_local_filename' => 'invoice.pdf',
            'media_mime_type'      => 'application/pdf',
            'media_filename'       => 'invoice.pdf',
        ]);
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'invoice.pdf', 'PDF');

        $controller = $this->kirimTeruskan($tujuan, $sumber);

        $this->assertSame(200, $this->statusCode($controller));
        $this->assertSame('document', $controller->capturedType);
        $this->assertSame('application/pdf', $controller->capturedMime);
        $this->assertSame('invoice.pdf', $controller->capturedFileName);
        $this->assertSame('Ini invoice-nya', $controller->capturedCaption);
        $this->assertSame('document', $this->lastOutgoing($tujuan)['message_type']);
    }

    public function testTeruskanStikerTidakPernahPunyaCaption(): void
    {
        // Stiker tidak punya caption di protokol WhatsApp -- aturan lama yang
        // sama dengan kirimMedia() biasa.
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'sticker',
            'text'                 => 'teks yang harus diabaikan',
            'media_local_filename' => 'st.webp',
            'media_mime_type'      => 'image/webp',
            'media_filename'       => 'st.webp',
        ]);
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'st.webp', 'WEBP');

        $controller = $this->kirimTeruskan($tujuan, $sumber);

        $this->assertSame(200, $this->statusCode($controller));
        $this->assertSame('sticker', $controller->capturedType);
        $this->assertSame('', $controller->capturedCaption, 'Stiker: caption selalu kosong.');
        $this->assertNull($this->lastOutgoing($tujuan)['text']);
    }

    // ------------------------------------------------------------------
    // REQ-009/AC-006/AC-010: kutipan tidak ikut terbawa
    // ------------------------------------------------------------------

    public function testSumberYangPunyaKutipanTidakMembawaKutipanKeBarisBaru(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'                => 'incoming',
            'message_type'             => 'image',
            'media_local_filename'     => 'q.jpg',
            'media_mime_type'          => 'image/jpeg',
            'media_filename'           => 'q.jpg',
            // Sumber adalah hasil Balas Pesan (punya snapshot kutipan).
            'quoted_wa_message_id'     => 'SRC-QUOTED-1',
            'quoted_sender_label'      => 'Pelanggan',
            'quoted_snippet'           => 'pesan lama',
            'quoted_media_available'   => 1,
            'quoted_source_message_id' => 123,
            'quoted_media_type'        => 'text',
        ]);
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'q.jpg', 'IMG');

        $controller = $this->kirimTeruskan($tujuan, $sumber);

        $row = $this->lastOutgoing($tujuan);
        $this->assertNull($row['quoted_wa_message_id'], 'REQ-009: kutipan tidak ikut terbawa.');
        $this->assertNull($row['quoted_sender_label']);
        $this->assertNull($row['quoted_snippet']);
        $this->assertNull($row['quoted_media_available']);
        $this->assertNull($row['quoted_source_message_id']);
        $this->assertNull($row['quoted_media_type']);
        $this->assertNull($controller->capturedQuoted, 'Payload Gateway tidak pernah membawa quoted (CON-001/AC-010).');
    }

    public function testSumberTerTeruskanLabelTetapTunggalTanpaKolomPenghitung(): void
    {
        // AC-007: meneruskan pesan yang sudah diteruskan tetap satu penanda.
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'media_local_filename' => 'f.jpg',
            'media_mime_type'      => 'image/jpeg',
            'media_filename'       => 'f.jpg',
            'is_forwarded'         => 1,
        ]);
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'f.jpg', 'IMG');

        $this->kirimTeruskan($tujuan, $sumber);

        $this->assertSame(1, (int) $this->lastOutgoing($tujuan)['is_forwarded'], 'Penanda tunggal, tidak berlapis.');

        $kolom = db_connect('inbox')->getFieldNames('messages');
        $this->assertNotContains('forward_count', $kolom, 'REQ-009: tidak ada kolom penghitung forward.');
        $this->assertNotContains('forwarded_from', $kolom);
    }

    // ------------------------------------------------------------------
    // CON-001/GUD-001/REQ-006: penolakan server
    // ------------------------------------------------------------------

    public function testForwardDanQuotedBersamaanDitolak400(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'media_local_filename' => 'a.jpg',
        ]);
        $kutipan = $this->seedMessage($tujuan, ['direction' => 'incoming', 'message_type' => 'text', 'text' => 'kutipan']);

        $controller = $this->kirimTeruskan($tujuan, $sumber, ['quoted_message_id' => $kutipan]);

        $this->assertSame(400, $this->statusCode($controller), 'CON-001: forward + quoted -> 400.');
        $this->assertFalse($controller->gatewaySendMediaCalled, 'Gateway tidak boleh dipanggil sama sekali.');
        $this->assertSame(0, $this->countOutgoing($tujuan));
    }

    public function testSumberTeksDiJalurMediaDitolak400(): void
    {
        // GUD-001: rute otomatis di UI bukan satu-satunya pengaman.
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'teks biasa',
        ]);

        $controller = $this->kirimTeruskan($tujuan, $sumber);

        $this->assertSame(400, $this->statusCode($controller));
        $this->assertFalse($controller->gatewaySendMediaCalled);
    }

    public function testSumberAudioDitolak400(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'    => 'incoming',
            'message_type' => 'audio',
        ]);

        $controller = $this->kirimTeruskan($tujuan, $sumber);
        $body       = $this->body($controller);

        $this->assertSame(400, $this->statusCode($controller), 'REQ-006: audio tidak pernah forwardable.');
        $this->assertStringContainsString('Audio/video', $body['message']);
        $this->assertFalse($controller->gatewaySendMediaCalled);
    }

    public function testSumberTidakAdaDitolak400(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);

        $controller = $this->kirimTeruskan($tujuan, 999999);

        $this->assertSame(400, $this->statusCode($controller));
        $this->assertSame(0, $this->countOutgoing($tujuan));
    }

    // ------------------------------------------------------------------
    // SEC-202: caption turunan sumber divalidasi ulang
    // ------------------------------------------------------------------

    public function testCaptionSumberMelebihiBatasDitolak400(): void
    {
        // SEC-202: jalur Teruskan melewati validasi caption browser, jadi
        // caption dari pesan sumber (>1024) WAJIB divalidasi ulang di server,
        // sebelum byte dibaca maupun Gateway dipanggil.
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'text'                 => str_repeat('a', 1025),
            'media_local_filename' => 'panjang.jpg',
            'media_metadata'       => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ]);
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'panjang.jpg', 'BYTE');

        $controller = $this->kirimTeruskan($tujuan, $sumber);
        $body       = $this->body($controller);

        $this->assertSame(400, $this->statusCode($controller));
        $this->assertStringContainsString('Caption terlalu panjang', $body['message']);
        $this->assertFalse($controller->gatewaySendMediaCalled, 'Gateway tidak boleh dipanggil.');
        $this->assertSame(0, $this->countOutgoing($tujuan), 'Tidak ada baris yang ditulis.');
    }

    // ------------------------------------------------------------------
    // SEC-201: byte live-fetch dibatasi sebelum base64_encode
    // ------------------------------------------------------------------

    public function testByteLiveFetchMelebihiBatasDitolak400(): void
    {
        // SEC-201: byte hasil live-fetch yang melebihi `maxMediaUploadMb`
        // harus ditolak sebelum di-base64-encode, bukan diteruskan ke memori
        // tak terbatas. Batas di-set kecil supaya test tidak mengalokasi 15MB.
        $this->prevMaxMediaUploadMb = $_ENV['inbox.maxMediaUploadMb'] ?? null;
        $_ENV['inbox.maxMediaUploadMb'] = '1';

        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'      => 'incoming',
            'message_type'   => 'image',
            'text'           => 'foto besar',
            'media_metadata' => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ]);

        $controller = $this->controller([
            'conversation_id'         => $tujuan,
            'forward_from_message_id' => $sumber,
        ]);
        $controller->gatewayMediaResponse = [
            'ok'     => true,
            'binary' => str_repeat('b', 1024 * 1024 + 1),
        ];

        $controller->kirimMedia();
        $body = $this->body($controller);

        $this->assertSame(400, $this->statusCode($controller));
        $this->assertStringContainsString('terlalu besar', $body['message'], 'CLN-603: pesan khusus lampiran terlalu besar, bukan gagal-ambil.');
        $this->assertSame(1, $controller->gatewayMediaDownloadCalls, 'Live-fetch tetap dicoba.');
        $this->assertSame(1024 * 1024, $controller->capturedDownloadMaxBytes, 'TASK-713: jalur Teruskan tetap memakai batas unggah keluar.');
        $this->assertFalse($controller->gatewaySendMediaCalled, 'Kirim ke Gateway tidak boleh terjadi.');
        $this->assertSame(0, $this->countOutgoing($tujuan), 'Tidak ada baris yang ditulis.');
    }

    public function testMediaMasukBesarTetapTampilSaatDalamBatasUnduh(): void
    {
        // REQ-701/PRN-701: batas unduh/tampilan (`maxMediaDownloadMb`)
        // TERPISAH dari batas unggah keluar (`maxMediaUploadMb`). Media
        // masuk yang lebih besar dari batas unggah kasir tetap boleh
        // ditampilkan lewat GET /inbox/media -- TIDAK boleh jadi 413 hanya
        // karena konstanta unggah dipakai ulang.
        $_ENV['inbox.maxMediaUploadMb']   = '1';
        $_ENV['inbox.maxMediaDownloadMb'] = '5';

        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'media_local_filename' => null,
            'media_metadata'       => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ]);

        $controller = $this->controller();
        // 2MB: di atas batas unggah (1MB), di bawah batas unduh (5MB).
        $binary = str_repeat('b', 2 * 1024 * 1024);
        $controller->gatewayMediaResponse = ['ok' => true, 'binary' => $binary];

        $response = $controller->media($sumber);

        $this->assertSame(200, $response->getStatusCode(), 'Media dalam batas unduh tetap tampil (bukan 413).');
        $this->assertSame($binary, (string) $response->getBody());
        $this->assertSame(5 * 1024 * 1024, $controller->capturedDownloadMaxBytes, 'Jalur tampil memakai batas UNDUH (maxMediaDownloadMb).');
    }

    public function testLiveFetchDibatalkanDiTransfer413Ditolak400(): void
    {
        // SEC-602: bila unduhan live-fetch dibatalkan karena melewati batas
        // SELAMA transfer, Gateway melaporkan 413; jalur Teruskan harus
        // menampilkan pesan "terlalu besar" (bukan "gagal ambil"), tanpa
        // baris tersimpan dan tanpa kirim ke Gateway.
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'      => 'incoming',
            'message_type'   => 'image',
            'text'           => 'foto besar',
            'media_metadata' => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ]);

        $controller = $this->controller([
            'conversation_id'         => $tujuan,
            'forward_from_message_id' => $sumber,
        ]);
        $controller->gatewayMediaResponse = [
            'ok'     => false,
            'status' => 413,
            'error'  => 'Lampiran dari WhatsApp melebihi batas ukuran.',
        ];

        $controller->kirimMedia();
        $body = $this->body($controller);

        $this->assertSame(400, $this->statusCode($controller));
        $this->assertStringContainsString('terlalu besar', $body['message']);
        $this->assertSame(1, $controller->gatewayMediaDownloadCalls, 'Live-fetch tetap dicoba.');
        $this->assertFalse($controller->gatewaySendMediaCalled, 'Kirim ke Gateway tidak boleh terjadi.');
        $this->assertSame(0, $this->countOutgoing($tujuan), 'Tidak ada baris yang ditulis.');
    }

    public function testKlasifikasiTerlaluBesarTetap413TanpaContentLength(): void
    {
        // COR-701: "terlalu besar" TIDAK boleh bergantung pada `Content-Length`
        // dari Gateway. Respons yang hanya membawa sinyal status 413 -- tanpa
        // header ukuran, tanpa pesan error -- tetap harus dipetakan ke pesan
        // khusus "terlalu besar", bukan pesan gagal-ambil generik.
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'      => 'incoming',
            'message_type'   => 'image',
            'text'           => 'foto besar',
            'media_metadata' => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ]);

        $controller = $this->controller([
            'conversation_id'         => $tujuan,
            'forward_from_message_id' => $sumber,
        ]);
        // Tanpa `error`, tanpa `content_length`: klasifikasi harus murni dari
        // status 413, bukan dari hint ukuran apa pun.
        $controller->gatewayMediaResponse = ['ok' => false, 'status' => 413];

        $controller->kirimMedia();
        $body = $this->body($controller);

        $this->assertSame(400, $this->statusCode($controller));
        $this->assertStringContainsString('terlalu besar', $body['message']);
        $this->assertFalse($controller->gatewaySendMediaCalled, 'Kirim ke Gateway tidak boleh terjadi.');
        $this->assertSame(0, $this->countOutgoing($tujuan), 'Tidak ada baris yang ditulis.');
    }

    // Guard statis `testUnduhanGatewayDibatasiSelamaTransferDiSumber` DIPINDAH
    // (TASK-723) ke test PERILAKU nyata: `InboxAkumulasiChunkTest` (unit murni
    // batas byte) + `InboxMediaDownloadTransferBoundTest` (cURL sungguhan vs
    // loopback server tanpa `Content-Length`). Substring sumber rapuh dan
    // tidak membuktikan transfer benar-benar dibatalkan.

    // ------------------------------------------------------------------
    // PRN-304: resolveForwardMediaSource() -- urutan disk -> live-fetch -> gone
    // ------------------------------------------------------------------

    public function testResolveForwardMediaSourcePakaiDiskTanpaLiveFetch(): void
    {
        $sumber = [
            'id'                   => 1,
            'message_type'         => 'image',
            'text'                 => 'caption',
            'media_local_filename' => 'ada.jpg',
            'media_mime_type'      => 'image/jpeg',
            'media_filename'       => 'ada.jpg',
            'media_size'           => 3,
            'media_metadata'       => null,
        ];
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'ada.jpg', 'ABC');

        $resolved = $this->panggilResolveForwardMediaSource($sumber);

        $this->assertNull($resolved['error']);
        $this->assertSame('image', $resolved['media']['type']);
        $this->assertSame('caption', $resolved['media']['caption']);
        $this->assertSame(base64_encode('ABC'), $resolved['media']['base64']);
        $this->assertSame(0, $this->spy->gatewayMediaDownloadCalls, 'Disk lokal: live-fetch tidak dipanggil.');
    }

    public function testResolveForwardMediaSourceLiveFetchSaatDiskKosong(): void
    {
        $sumber = [
            'id'                   => 2,
            'message_type'         => 'image',
            'text'                 => 'caption',
            'media_local_filename' => null,
            'media_mime_type'      => null,
            'media_filename'       => null,
            'media_size'           => null,
            'media_metadata'       => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ];

        $resolved = $this->panggilResolveForwardMediaSource($sumber, ['ok' => true, 'binary' => 'GATEWAY']);

        $this->assertNull($resolved['error']);
        $this->assertSame(base64_encode('GATEWAY'), $resolved['media']['base64']);
        $this->assertSame(1, $this->spy->gatewayMediaDownloadCalls, 'Disk kosong: live-fetch dipanggil.');
    }

    public function testResolveForwardMediaSourcePermanenGoneTidakHubungiGateway(): void
    {
        $sumber = [
            'id'                      => 3,
            'message_type'            => 'image',
            'text'                    => 'caption',
            'media_local_filename'    => null,
            'media_mime_type'         => null,
            'media_confirmed_gone_at' => date('Y-m-d H:i:s'),
            'media_metadata'          => json_encode(['direct_path' => '/gw', 'media_key_base64' => 'kk']),
        ];

        $resolved = $this->panggilResolveForwardMediaSource($sumber);

        $this->assertNotNull($resolved['error']);
        $this->assertNull($resolved['media']);
        $this->assertSame(0, $this->spy->gatewayMediaDownloadCalls, 'Media hilang permanen: jangan hubungi Gateway.');
    }

    /**
     * Panggil private `resolveForwardMediaSource()` langsung lewat reflection.
     *
     * @param array<string, mixed>      $sumber
     * @param array<string, mixed>|null $gatewayResponse
     *
     * @return array{error: ?string, media: ?array<string, mixed>}
     */
    private function panggilResolveForwardMediaSource(array $sumber, ?array $gatewayResponse = null): array
    {
        $this->spy = $this->controller();

        if ($gatewayResponse !== null) {
            $this->spy->gatewayMediaResponse = $gatewayResponse;
        }

        $method = (new \ReflectionClass($this->spy))->getMethod('resolveForwardMediaSource');
        $method->setAccessible(true);

        return $method->invoke($this->spy, new GatewayInboxConfig(), $sumber);
    }

    // ------------------------------------------------------------------
    // REQ-007: ownership tujuan + REQ-010: idempotensi
    // ------------------------------------------------------------------

    public function testTujuanMilikKasirLainDitolak403(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 9);
        $sumber = $this->seedMessage($this->seedConversation('628111111111@s.whatsapp.net', 7), [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'media_local_filename' => 'x.jpg',
        ]);

        $controller = $this->kirimTeruskan($tujuan, $sumber);

        $this->assertSame(403, $this->statusCode($controller), 'AC-005: ownership tujuan tetap berlaku.');
        $this->assertFalse($controller->gatewaySendMediaCalled);
    }

    public function testReplayOperationIdSamaTidakMenulisBarisKedua(): void
    {
        $tujuan = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sumber = $this->seedMessage($tujuan, [
            'direction'            => 'incoming',
            'message_type'         => 'image',
            'media_local_filename' => 'r.jpg',
        ]);
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'r.jpg', 'IMG');

        $this->kirimTeruskan($tujuan, $sumber, ['operation_id' => 'fwd-media-1']);

        $kedua = $this->kirimTeruskan($tujuan, $sumber, ['operation_id' => 'fwd-media-1']);

        $this->assertTrue($this->body($kedua)['replayed'], 'REQ-010: replay terdeteksi.');
        $this->assertSame(1, $this->countOutgoing($tujuan), 'Hanya satu baris tersimpan.');
    }

    // ------------------------------------------------------------------
    // TASK-010: regresi kirim media BIASA (tanpa Teruskan)
    // ------------------------------------------------------------------

    public function testKirimMediaBiasaSamaSekaliTidakMengirimForward(): void
    {
        // Payload Gateway tidak pernah membawa `forward` (maupun `quoted`)
        // bila `forward_from_message_id` tidak dikirim -- jalur lama apa adanya.
        $tujuan     = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $controller = $this->controllerMediaBiasa($tujuan);

        $controller->kirimMedia();

        $this->assertSame(200, $this->statusCode($controller));
        $this->assertNull($controller->capturedForward, 'Kirim media biasa tidak pernah membawa forward.');
        $this->assertNull($controller->capturedQuoted);
        $this->assertSame(0, (int) $this->lastOutgoing($tujuan)['is_forwarded'], 'Regresi: is_forwarded tetap 0.');
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $post */
    private function controller(array $post = []): InboxTeruskanMediaSpy
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setGlobal('post', $post);
        $controller = new InboxTeruskanMediaSpy();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return $controller;
    }

    /** Jalur kirim media BIASA: unggahan kasir nyata, tanpa `forward_from_message_id`. */
    private function controllerMediaBiasa(int $conversationId): InboxTeruskanMediaSpy
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setGlobal('post', ['conversation_id' => (string) $conversationId, 'caption' => '']);

        $collection    = new FileCollection();
        $filesProperty = new \ReflectionProperty($collection, 'files');
        $filesProperty->setAccessible(true);
        $filesProperty->setValue($collection, ['media' => $this->fakeUploadedMedia()]);

        $requestFiles = new \ReflectionProperty($request, 'files');
        $requestFiles->setAccessible(true);
        $requestFiles->setValue($request, $collection);

        $controller = new InboxTeruskanMediaSpy();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return $controller;
    }

    private function fakeUploadedMedia(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aulia-teruskan-media-');
        $this->assertIsString($path);

        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAwAB/wD/AH8AAAAASUVORK5CYII='));

        return new InboxTeruskanTestUploadedMedia($path, 'biasa.png', 'image/png', (int) filesize($path), UPLOAD_ERR_OK);
    }

    /**
     * Jalankan aksi Teruskan lampiran terhadap percakapan tujuan dan
     * kembalikan spynya (respons & payload Gateway bisa diperiksa lewat spy).
     *
     * @param array<string, mixed> $post
     */
    private function kirimTeruskan(int $tujuan, int $sumber, array $post = []): InboxTeruskanMediaSpy
    {
        $controller = $this->controller(array_merge([
            'conversation_id'         => $tujuan,
            'forward_from_message_id' => $sumber,
        ], $post));
        $controller->kirimMedia();

        return $controller;
    }

    private function body(Inbox $controller): array
    {
        return json_decode((string) $this->response($controller)->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function statusCode(Inbox $controller): int
    {
        return $this->response($controller)->getStatusCode();
    }

    private function response(Inbox $controller): \CodeIgniter\HTTP\ResponseInterface
    {
        $property = (new \ReflectionClass($controller))->getParentClass()->getProperty('response');
        $property->setAccessible(true);

        return $property->getValue($controller);
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
            'wa_message_id'     => 'SRC-' . bin2hex(random_bytes(6)),
            'direction'         => 'incoming',
            'message_type'      => 'text',
            'sender_jid'        => '628999888777@s.whatsapp.net',
            'text'              => null,
            'is_internal'       => 0,
            'message_timestamp' => date('Y-m-d H:i:s'),
            'send_status'       => 'received',
        ]);
        $id = (int) $db->insertID();

        $db->table('messages')->where('id', $id)->update($attributes);

        return $id;
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

final class InboxTeruskanMediaSpy extends Inbox
{
    public ?array $capturedQuoted = null;
    public ?bool $capturedForward = null;
    public string $capturedCaption = '';
    public string $capturedBase64 = '';
    public ?string $capturedType = null;
    public ?string $capturedMime = null;
    public ?string $capturedFileName = null;

    /** Bila true, ada pemanggilan /send-media ke Gateway. */
    public bool $gatewaySendMediaCalled = false;

    /** Berapa kali jalur live-fetch Gateway dipanggil. */
    public int $gatewayMediaDownloadCalls = 0;

    /** Batas byte terakhir yang dipasok ke unduhan Gateway (PRN-701). */
    public ?int $capturedDownloadMaxBytes = null;

    /** @var array<string, mixed> */
    public array $gatewayMediaResponse = ['ok' => true, 'binary' => 'GATEWAY-BYTES'];

    protected function callGatewaySendMedia(GatewayInboxConfig $config, InboxOutgoingRequest $request): array
    {
        $this->gatewaySendMediaCalled = true;
        $this->capturedForward        = $request->forward ? true : null;
        $this->capturedQuoted         = $request->quoted;
        $this->capturedCaption        = $request->caption;
        $this->capturedBase64         = (string) $request->mediaBase64;
        $this->capturedType           = $request->mediaType;
        $this->capturedMime           = $request->mimetype;
        $this->capturedFileName       = $request->fileName;

        return [
            'ok'                     => true,
            'wa_message_id'          => 'wa-fwd-media-' . bin2hex(random_bytes(4)),
            'media_ref'              => ['direct_path' => '/gw-out', 'media_key_base64' => 'kk-out'],
            'error_code'             => null,
            'state'                  => 'sent',
            'replayed'               => false,
            'quote_applied'          => true,
            'forward_marker_applied' => 'native',
        ];
    }

    public function callGatewayMediaDownload(GatewayInboxConfig $config, array $mediaRef, ?string $mimetype, int $timeoutSeconds = 30, ?int $maxBytes = null): array
    {
        $this->gatewayMediaDownloadCalls++;
        // Cermin resolusi default di produksi (PRN-701): panggilan tanpa
        // `$maxBytes` eksplisit memakai batas UNDUH, bukan batas unggah.
        $this->capturedDownloadMaxBytes = $maxBytes ?? $config->maxMediaDownloadMb * 1024 * 1024;

        return $this->gatewayMediaResponse;
    }
}

/**
 * Test-only upload: isValid() tidak lagi menuntut unggahan HTTP nyata
 * (is_uploaded_file() selalu false di CLI) -- pola yang sama dengan
 * InboxTestUploadedMedia di InboxOutgoingIdempotencyTest.php.
 */
final class InboxTeruskanTestUploadedMedia extends UploadedFile
{
    public function isValid(): bool
    {
        return true;
    }
}

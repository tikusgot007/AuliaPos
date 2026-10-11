<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * TODO-Q2 (poin 1, jalur sukses): kirim pesan lewat Gateway dev ASLI
 * (inbox.gatewayBaseUrl di .env, mis. http://127.0.0.1:13000) sampai ke
 * WhatsApp sungguhan.
 *
 * MANUAL, dilewati secara default. Pesan WhatsApp BENAR-BENAR terkirim, jadi
 * tujuan wajib nomor milik sendiri, bukan pelanggan. Jalankan dengan:
 *
 *   $env:INBOX_REAL_GATEWAY_TO = '628xxxxxxxxxx'
 *   vendor/bin/phpunit --configuration phpunit.feature.xml tests/feature/InboxKirimGatewayAsliTest.php
 *
 * Efek samping yang diketahui: Gateway melaporkan pesan keluar ini kembali ke
 * CI4 dev (database `aulia_inboxdb`, bukan DB uji), jadi satu pesan uji akan
 * muncul di Inbox dev. Sisi POS di test ini sendiri tetap memakai DB uji.
 *
 * @internal
 */
final class InboxKirimGatewayAsliTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private $inbox;
    private string $tujuan = '';

    protected function setUp(): void
    {
        parent::setUp();

        $tujuan = (string) getenv('INBOX_REAL_GATEWAY_TO');
        if ($tujuan === '') {
            $this->markTestSkipped('Test manual: set INBOX_REAL_GATEWAY_TO=<nomor sendiri> untuk mengirim pesan WhatsApp sungguhan.');
        }
        if (! ctype_digit($tujuan) || strlen($tujuan) < 10) {
            $this->fail('INBOX_REAL_GATEWAY_TO harus berupa nomor WhatsApp berformat 62xxxxxxxxxx.');
        }
        $this->tujuan = $tujuan;

        if ((string) env('inbox.gatewayBaseUrl', '') === '' || (string) env('inbox.gatewayToken', '') === '') {
            $this->markTestSkipped('inbox.gatewayBaseUrl / inbox.gatewayToken belum diisi di .env.');
        }

        $this->inbox = db_connect('inbox');

        $now = date('Y-m-d H:i:s');
        $this->inbox->table('gateway_status')->where('id', 1)->delete();
        $this->inbox->table('gateway_status')->insert([
            'id' => 1, 'status' => 'connected', 'last_heartbeat_at' => $now, 'updated_at' => $now,
        ]);

        $forge = \Config\Database::forge();
        $forge->dropTable('users', true);
        $forge->addField([
            'id'        => ['type' => 'INTEGER', 'auto_increment' => true],
            'username'  => ['type' => 'TEXT'],
            'nama'      => ['type' => 'TEXT', 'null' => true],
            'inisial'   => ['type' => 'TEXT', 'null' => true],
            'role'      => ['type' => 'TEXT', 'default' => 'kasir'],
            'is_active' => ['type' => 'INTEGER', 'default' => 1],
            'priority'  => ['type' => 'INTEGER', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);
        db_connect()->table('users')->insert([
            'id' => 21, 'username' => 'user21', 'nama' => 'User 21', 'inisial' => 'U21',
            'role' => 'kasir', 'is_active' => 1, 'priority' => null,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->tujuan !== '') {
            \Config\Database::forge()->dropTable('users', true);
        }
        parent::tearDown();
    }

    public function testKirimTeksSampaiKeWhatsappSungguhan(): void
    {
        $chatId = $this->tujuan . '@s.whatsapp.net';
        $this->inbox->table('conversations')->where('chat_id', $chatId)->delete();

        $now = date('Y-m-d H:i:s');
        $this->inbox->table('conversations')->insert([
            'chat_id' => $chatId, 'jid_type' => 'pn', 'status' => 'open', 'assigned_to' => null,
            'last_message_direction' => 'incoming', 'last_message_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $conv = (int) $this->inbox->insertID();

        $teks = '[TEST OTOMATIS AULIA] Uji kirim POS -> Gateway dev ' . date('Y-m-d H:i:s');
        $res  = $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/kirim', [
                'conversation_id' => $conv,
                'text'            => $teks,
                'operation_id'    => 'op-real-' . bin2hex(random_bytes(8)),
            ]);

        $json = json_decode(trim(strip_tags($res->getBody())), true) ?: [];
        $this->assertSame('success', $json['status'] ?? null, 'Respons Gateway asli: ' . json_encode($json));
        $res->assertStatus(200);

        $waMessageId = (string) ($json['message']['wa_message_id'] ?? '');
        $this->assertNotSame('', $waMessageId);
        $this->assertStringStartsNotWith('local-', $waMessageId, 'Gateway asli harus mengembalikan wa_message_id dari WhatsApp.');

        $row = $this->inbox->table('messages')->where('conversation_id', $conv)->get()->getRowArray();
        $this->assertSame($waMessageId, $row['wa_message_id']);
        $this->assertSame('sent', $row['send_status']);
        $this->assertSame($teks, $row['text']);
    }
}

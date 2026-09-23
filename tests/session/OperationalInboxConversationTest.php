<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * M3 TASK-011 -- conversation API filters and payload contract.
 *
 * Covers:
 * - status filter matches computed queue_status;
 * - q filter matches contact_name / whatsapp_name / phone / manual_phone /
 *   chat_id after compute (Fase 1d, AC-013);
 * - empty q behaves as no filter;
 * - message thread exposes is_internal as a boolean.
 *
 * @internal
 */
final class OperationalInboxConversationTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\\Support';
    protected $migrate = true;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversation_identities')->emptyTable();
        $db->table('conversations')->emptyTable();
    }

    private function sesi(): array
    {
        return [
            'isLoggedIn' => true,
            'role' => 'kasir',
            'id_user' => 7,
            'nama' => 'Test Kasir',
            'last_activity' => time(),
        ];
    }

    private function seedConversation(array $override = []): int
    {
        $db = db_connect('inbox');
        $now = '2026-09-22 14:00:00';

        $db->table('conversations')->insert(array_merge([
            'chat_id' => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type' => 'pn',
            'contact_name' => null,
            'phone' => null,
            'status' => 'open',
            'assigned_to' => 7,
            'last_seen_by_assignee_at' => null,
            'snoozed_until' => null,
            'last_message_at' => $now,
            'last_message_direction' => 'incoming',
            'created_at' => $now,
            'updated_at' => $now,
        ], $override));

        return (int) $db->insertID();
    }

    public function testStatusFilterMemakaiQueueStatusComputed(): void
    {
        $belum = $this->seedConversation([
            'assigned_to' => null,
            'last_message_direction' => 'incoming',
            'last_seen_by_assignee_at' => null,
        ]);
        $selesai = $this->seedConversation([
            'status' => 'closed',
            'contact_name' => 'Closed Customer',
        ]);

        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations?status=selesai');

        $res->assertOK();
        $data = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('success', $data['status']);
        $this->assertCount(1, $data['conversations']);
        $this->assertSame($selesai, (int) $data['conversations'][0]['id']);
        $this->assertSame('selesai', $data['conversations'][0]['queue_status']);
        $this->assertArrayHasKey('sla_color', $data['conversations'][0]);
        $this->assertNull($data['conversations'][0]['sla_color']);
        $this->assertNotSame($belum, (int) $data['conversations'][0]['id']);
    }

    public function testApiPayloadMemuatKelimaQueueStatusTanpaDuplikasi(): void
    {
        $ids = [
            'belum_diambil' => $this->seedConversation([
                'assigned_to' => null,
                'last_message_direction' => 'incoming',
                'last_seen_by_assignee_at' => null,
            ]),
            'open' => $this->seedConversation([
                'assigned_to' => 7,
                'last_message_direction' => 'incoming',
                'last_seen_by_assignee_at' => null,
            ]),
            'menunggu' => $this->seedConversation([
                'assigned_to' => 7,
                'last_message_direction' => 'outgoing',
                'last_seen_by_assignee_at' => null,
            ]),
            'ditunda' => $this->seedConversation([
                'assigned_to' => 7,
                'last_message_direction' => 'incoming',
                'last_seen_by_assignee_at' => null,
                'snoozed_until' => '2099-01-01 00:00:00',
            ]),
            'selesai' => $this->seedConversation([
                'status' => 'closed',
                'assigned_to' => 7,
                'last_message_direction' => 'outgoing',
            ]),
        ];

        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations');

        $res->assertOK();
        $data = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('success', $data['status']);

        $queueById = [];
        foreach ($data['conversations'] as $conversation) {
            $id = (int) $conversation['id'];
            if (in_array($id, $ids, true)) {
                $queueById[$id] = $conversation['queue_status'];
            }
        }

        foreach ($ids as $expectedStatus => $id) {
            $this->assertArrayHasKey($id, $queueById);
            $this->assertSame($expectedStatus, $queueById[$id]);
        }

        $this->assertCount(5, array_unique(array_values($queueById)));
    }

    public function testApiStatusFilterDapatMenemukanConversationLamaDiLuarLatest500(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $this->seedConversation([
                'last_message_at' => '2099-01-01 10:00:00',
                'updated_at' => '2099-01-01 10:00:00',
            ]);
        }

        $oldConversationId = $this->seedConversation([
            'status' => 'closed',
            'contact_name' => 'Conversation Lama',
            'last_message_at' => '2020-01-01 10:00:00',
            'updated_at' => '2020-01-01 10:00:00',
        ]);

        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations?status=selesai');

        $res->assertOK();
        $data = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('success', $data['status']);
        $this->assertCount(1, $data['conversations']);
        $this->assertSame($oldConversationId, (int) $data['conversations'][0]['id']);
        $this->assertSame('selesai', $data['conversations'][0]['queue_status']);
    }

    public function testQFilterCocokContactNameDanPhone(): void
    {
        $byName = $this->seedConversation([
            'contact_name' => 'Budi Surabaya',
            'phone' => '628123450001',
        ]);
        $byPhone = $this->seedConversation([
            'contact_name' => 'Customer Lain',
            'phone' => '628123459999',
        ]);

        $resName = $this->withSession($this->sesi())
            ->get('inbox/api/conversations?q=Surabaya');
        $nameData = json_decode($resName->getJSON(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('success', $nameData['status']);
        $this->assertCount(1, $nameData['conversations']);
        $this->assertSame($byName, (int) $nameData['conversations'][0]['id']);

        $resPhone = $this->withSession($this->sesi())
            ->get('inbox/api/conversations?q=9999');
        $phoneData = json_decode($resPhone->getJSON(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('success', $phoneData['status']);
        $this->assertCount(1, $phoneData['conversations']);
        $this->assertSame($byPhone, (int) $phoneData['conversations'][0]['id']);
    }

    public function testQKosongTidakMemfilter(): void
    {
        $first = $this->seedConversation(['contact_name' => 'Alpha']);
        $second = $this->seedConversation(['contact_name' => 'Beta']);

        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations?q=');
        $data = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('success', $data['status']);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $data['conversations']);

        $this->assertContains($first, $ids);
        $this->assertContains($second, $ids);
    }

    public function testThreadPayloadMembawaIsInternalSebagaiBoolean(): void
    {
        $conversationId = $this->seedConversation();

        $db = db_connect('inbox');
        $now = '2026-09-22 14:00:00';
        $db->table('messages')->insert([
            'conversation_id' => $conversationId,
            'wa_message_id' => 'thread-normal-' . bin2hex(random_bytes(4)),
            'direction' => 'incoming',
            'message_type' => 'text',
            'sender_jid' => '628123450000@s.whatsapp.net',
            'text' => 'Pesan customer',
            'message_timestamp' => $now,
            'send_status' => 'received',
            'is_internal' => false,
            'created_at' => $now,
        ]);
        $db->table('messages')->insert([
            'conversation_id' => $conversationId,
            'wa_message_id' => 'thread-internal-' . bin2hex(random_bytes(4)),
            'direction' => 'outgoing',
            'message_type' => 'text',
            'sender_jid' => null,
            'text' => 'Catatan staff',
            'message_timestamp' => $now,
            'sent_by_user_id' => 7,
            'send_status' => 'sent',
            'is_internal' => true,
            'created_at' => $now,
        ]);

        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations/' . $conversationId . '/messages');

        $res->assertOK();
        $data = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('success', $data['status']);
        $this->assertCount(2, $data['messages']);
        $this->assertFalse($data['messages'][0]['is_internal']);
        $this->assertTrue($data['messages'][1]['is_internal']);
        $this->assertArrayHasKey('queue_status', $data['conversation']);
    }

    /**
     * @return list<int>
     */
    private function idsDari(string $query): array
    {
        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations' . $query);

        $res->assertOK();
        $data = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('success', $data['status']);

        return array_map(static fn (array $row): int => (int) $row['id'], $data['conversations']);
    }

    /**
     * CL-003: status dan q berlaku bersamaan (AND).
     */
    public function testStatusDanQBerlakuBersamaan(): void
    {
        $budiSelesai = $this->seedConversation([
            'status' => 'closed',
            'contact_name' => 'Budi Selesai',
        ]);
        $budiOpen = $this->seedConversation([
            'assigned_to' => 7,
            'contact_name' => 'Budi Open',
        ]);
        $lainSelesai = $this->seedConversation([
            'status' => 'closed',
            'contact_name' => 'Andi Selesai',
        ]);

        $ids = $this->idsDari('?status=selesai&q=Budi');

        $this->assertSame([$budiSelesai], $ids);
        $this->assertNotContains($budiOpen, $ids);
        $this->assertNotContains($lainSelesai, $ids);
    }

    /**
     * CL-005: tidak ada hasil tetap 200 dengan array kosong, bukan 404.
     */
    public function testTidakAdaHasilTetap200DenganArrayKosong(): void
    {
        $this->seedConversation(['contact_name' => 'Budi']);

        $this->assertSame([], $this->idsDari('?q=TidakAdaYangCocok'));
        $this->assertSame([], $this->idsDari('?status=ditunda'));
        $this->assertSame([], $this->idsDari('?status=selesai&q=Budi'));
    }

    /**
     * CL-007: q yang hanya berisi spasi dianggap kosong, status tetap berlaku.
     */
    public function testQHanyaSpasiDianggapTidakAdaPencarian(): void
    {
        $selesai = $this->seedConversation([
            'status' => 'closed',
            'contact_name' => 'Alpha',
        ]);
        $open = $this->seedConversation(['contact_name' => 'Beta']);

        $semua = $this->idsDari('?q=' . rawurlencode('   '));
        $this->assertContains($selesai, $semua);
        $this->assertContains($open, $semua);

        $this->assertSame([$selesai], $this->idsDari('?status=selesai&q=' . rawurlencode('   ')));
    }

    /**
     * CL-008: % dan _ dicari sebagai teks biasa, bukan wildcard.
     */
    public function testPersenDanUnderscoreBukanWildcard(): void
    {
        $diskon = $this->seedConversation(['contact_name' => 'Promo 50% Off']);
        $underscore = $this->seedConversation(['contact_name' => 'toko_budi']);
        $this->seedConversation(['contact_name' => 'Promo 500 Off']);
        $this->seedConversation(['contact_name' => 'toko budi']);

        // Kalau % jadi wildcard, "50%" akan ikut cocok ke "500".
        $this->assertSame([$diskon], $this->idsDari('?q=' . rawurlencode('50%')));

        // Kalau _ jadi wildcard (1 karakter apa saja), "toko budi" ikut cocok.
        $this->assertSame([$underscore], $this->idsDari('?q=' . rawurlencode('toko_')));
    }

    /**
     * CL-001: pencarian q menemukan conversation lama di luar 500 terbaru.
     */
    public function testQDapatMenemukanConversationLamaDiLuarLatest500(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $this->seedConversation([
                'contact_name' => 'Customer Baru',
                'last_message_at' => '2099-01-01 10:00:00',
                'updated_at' => '2099-01-01 10:00:00',
            ]);
        }

        $oldConversationId = $this->seedConversation([
            'contact_name' => 'Pelanggan Jadul',
            'last_message_at' => '2020-01-01 10:00:00',
            'updated_at' => '2020-01-01 10:00:00',
        ]);

        $this->assertSame([$oldConversationId], $this->idsDari('?q=Jadul'));
    }

    private function assertBadRequest(string $query): void
    {
        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations' . $query);

        $res->assertStatus(400);
        $data = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('error', $data['status']);
    }

    /**
     * Seed $jumlah conversation dengan last_message_at berbeda (terbaru
     * dulu) supaya urutan halaman deterministik.
     *
     * @return list<int> id urut last_message_at DESC
     */
    private function seedBerurutan(int $jumlah): array
    {
        $ids = [];
        for ($i = 0; $i < $jumlah; $i++) {
            $waktu = date('Y-m-d H:i:s', strtotime('2026-09-22 14:00:00') - ($i * 60));
            $ids[] = $this->seedConversation([
                'last_message_at' => $waktu,
                'updated_at' => $waktu,
            ]);
        }

        return $ids;
    }

    /**
     * CL-002: status di luar 5 status Queue View ditolak 400.
     */
    public function testStatusTidakValidDitolak400(): void
    {
        $this->seedConversation();

        $this->assertBadRequest('?status=semua');
        $this->assertBadRequest('?status=perlu_dibalas');
        $this->assertBadRequest('?status=SELESAI');
    }

    /**
     * CL-009: q lebih dari 255 karakter setelah trim ditolak 400;
     * tepat 255 karakter masih boleh.
     */
    public function testQLebihDari255KarakterDitolak400(): void
    {
        $this->seedConversation(['contact_name' => 'Budi']);

        $this->assertBadRequest('?q=' . str_repeat('a', 256));
        $this->assertSame([], $this->idsDari('?q=' . str_repeat('a', 255)));
        $this->assertSame([], $this->idsDari('?q=' . rawurlencode('  ' . str_repeat('a', 255) . '  ')));
    }

    /**
     * CL-010/011: 50 conversation per halaman, page mulai 1, default 1.
     */
    public function testPaginationLimaPuluhPerHalaman(): void
    {
        $semua = $this->seedBerurutan(60);

        $this->assertSame(array_slice($semua, 0, 50), $this->idsDari(''));
        $this->assertSame(array_slice($semua, 0, 50), $this->idsDari('?page=1'));
        $this->assertSame(array_slice($semua, 50, 10), $this->idsDari('?page=2'));
    }

    /**
     * CL-010: pagination berlaku setelah filter, bukan memotong dataset
     * yang dicari.
     */
    public function testPaginationBerlakuSetelahFilter(): void
    {
        $this->seedBerurutan(55);
        $lama = $this->seedConversation([
            'status' => 'closed',
            'last_message_at' => '2020-01-01 10:00:00',
            'updated_at' => '2020-01-01 10:00:00',
        ]);

        $this->assertSame([$lama], $this->idsDari('?status=selesai&page=1'));
    }

    /**
     * CL-012: page 0, negatif, atau bukan bilangan bulat ditolak 400.
     */
    public function testPageTidakValidDitolak400(): void
    {
        $this->seedConversation();

        foreach (['0', '-1', 'abc', '1.5', '', '01x', ' 1'] as $page) {
            $this->assertBadRequest('?page=' . rawurlencode($page));
        }
    }

    /**
     * CL-013: page valid di luar data terakhir tetap 200 dengan [].
     */
    public function testPageMelewatiDataTerakhirMengembalikanArrayKosong(): void
    {
        $this->seedBerurutan(3);

        $this->assertSame([], $this->idsDari('?page=2'));
        $this->assertSame([], $this->idsDari('?page=999'));
    }

    /**
     * Spec 4.4: pencarian q tidak membedakan huruf besar/kecil.
     */
    public function testQTidakMembedakanHurufBesarKecil(): void
    {
        $budi = $this->seedConversation(['contact_name' => 'Budi Surabaya']);
        $this->seedConversation(['contact_name' => 'Andi']);

        $this->assertSame([$budi], $this->idsDari('?q=budi'));
        $this->assertSame([$budi], $this->idsDari('?q=SURABAYA'));
    }

    /**
     * Fase 1d seeds use a fixed, digit-free chat_id, because chat_id is
     * now searched too and the random default could match a number query.
     */
    private function seedIdentitas(string $chatId, array $override = []): int
    {
        return $this->seedConversation(array_merge(['chat_id' => $chatId], $override));
    }

    /**
     * AC-013 (a): no contact_name, list shows whatsapp_name -> found.
     */
    public function testQCocokWhatsappNameSaatContactNameKosong(): void
    {
        $budi = $this->seedIdentitas('ac-a-budi@s.whatsapp.net', [
            'contact_name' => null,
            'whatsapp_name' => 'Budi Cetak',
        ]);
        $this->seedIdentitas('ac-a-lain@s.whatsapp.net', ['whatsapp_name' => 'Andi']);

        $this->assertSame([$budi], $this->idsDari('?q=' . rawurlencode('budi cetak')));
    }

    /**
     * AC-013 (b): each of the four name/number columns matches on its own.
     */
    public function testQCocokMasingMasingKolomNamaDanNomor(): void
    {
        $byContact = $this->seedIdentitas('ac-b-satu@s.whatsapp.net', ['contact_name' => 'Kontak Alfa']);
        $byWhatsapp = $this->seedIdentitas('ac-b-dua@s.whatsapp.net', ['whatsapp_name' => 'Profil Bravo']);
        $byPhone = $this->seedIdentitas('ac-b-tiga@s.whatsapp.net', ['phone' => '620001112223']);
        $byManual = $this->seedIdentitas('ac-b-empat@s.whatsapp.net', ['manual_phone' => '0813-4445556']);

        $this->assertSame([$byContact], $this->idsDari('?q=alfa'));
        $this->assertSame([$byWhatsapp], $this->idsDari('?q=bravo'));
        $this->assertSame([$byPhone], $this->idsDari('?q=1112223'));
        $this->assertSame([$byManual], $this->idsDari('?q=4445556'));
    }

    /**
     * AC-013 (c): no name and no phone, list shows chat_id -> found by it.
     */
    public function testQCocokChatIdSaatTanpaNamaDanNomor(): void
    {
        $lid = $this->seedIdentitas('88776655443322@lid', [
            'jid_type' => 'lid',
            'contact_name' => null,
            'whatsapp_name' => null,
            'phone' => null,
        ]);
        $this->seedIdentitas('ac-c-lain@s.whatsapp.net', ['contact_name' => 'Andi']);

        $this->assertSame([$lid], $this->idsDari('?q=7766554'));
    }

    /**
     * AC-013 (d): whole dataset, case-insensitive, AND with status.
     */
    public function testQWhatsappNameLamaDenganStatus(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->seedIdentitas('ac-d-baru-' . chr(97 + intdiv($i, 26)) . chr(97 + $i % 26) . '@s.whatsapp.net', [
                'contact_name' => 'Customer Baru',
                'last_message_at' => '2099-01-01 10:00:00',
                'updated_at' => '2099-01-01 10:00:00',
            ]);
        }

        $lama = $this->seedIdentitas('ac-d-lama@s.whatsapp.net', [
            'status' => 'closed',
            'contact_name' => null,
            'whatsapp_name' => 'Budi Cetak',
            'last_message_at' => '2020-01-01 10:00:00',
            'updated_at' => '2020-01-01 10:00:00',
        ]);

        $this->assertSame([$lama], $this->idsDari('?status=selesai&q=' . rawurlencode('BUDI CETAK')));
        $this->assertNotContains($lama, $this->idsDari('?status=open&q=' . rawurlencode('budi cetak')));
    }

    /**
     * AC-013 (e): whatsapp_name is searched even when contact_name is shown.
     */
    public function testQCocokWhatsappNameWalauContactNameBerbeda(): void
    {
        $jamet = $this->seedIdentitas('ac-e-jamet@s.whatsapp.net', [
            'contact_name' => 'Jamet',
            'whatsapp_name' => 'Budi Cetak',
        ]);

        $this->assertSame([$jamet], $this->idsDari('?q=' . rawurlencode('budi cetak')));
    }

    /**
     * AC-013 (f): q is matched per column, never against joined columns.
     */
    public function testQTidakCocokGabunganKolom(): void
    {
        $this->seedIdentitas('ac-f-budi@s.whatsapp.net', [
            'contact_name' => 'Budi',
            'phone' => '62812',
        ]);

        $this->assertSame([], $this->idsDari('?q=' . rawurlencode('budi 62812')));
    }

    /**
     * AC-013 (g): NULL whatsapp_name/manual_phone raise no error.
     */
    public function testQKolomNullTidakError(): void
    {
        $andi = $this->seedIdentitas('ac-g-andi@s.whatsapp.net', [
            'contact_name' => 'Andi',
            'whatsapp_name' => null,
            'phone' => null,
            'manual_phone' => null,
        ]);

        $this->assertSame([$andi], $this->idsDari('?q=andi'));
        $this->assertSame([], $this->idsDari('?q=zzz'));
    }
}

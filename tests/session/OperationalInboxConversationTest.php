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
        $byName = $this->seedIdentitas('q-filter-nama@s.whatsapp.net', [
            'contact_name' => 'Budi Surabaya',
            'phone' => '628123450001',
        ]);
        $byPhone = $this->seedIdentitas('q-filter-nomor@s.whatsapp.net', [
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
     * Call GET /inbox/api/conversations and return the conversation list as
     * sent by the server (the 200 payload is validated here).
     *
     * @return list<array<string, mixed>>
     */
    private function payloadDari(string $query): array
    {
        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations' . $query);

        $res->assertOK();
        $data = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('success', $data['status']);

        return $data['conversations'];
    }

    /**
     * @return list<int>
     */
    private function idsDari(string $query): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $this->payloadDari($query));
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
     * Seed with a fixed, digit-free chat_id for q tests: chat_id is searched
     * too (Fase 1d), so the random default could match a number query.
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

    /**
     * The single result row of $conversationId inside the search result, so a
     * test can read fields other than `id` (Fase 1e).
     *
     * @return array<string, mixed>
     */
    private function barisPencarian(string $query, int $conversationId): array
    {
        $baris = array_values(array_filter(
            $this->payloadDari($query),
            static fn (array $row): bool => (int) $row['id'] === $conversationId
        ));

        $this->assertCount(1, $baris, 'Conversation harus muncul tepat satu kali di hasil pencarian.');

        return $baris[0];
    }

    /**
     * Seed one `messages` row: the seam used by the Fase 1e message-text
     * search. `text` defaults to NULL, like a media message without caption.
     */
    private function seedMessage(int $conversationId, array $override = []): int
    {
        $db = db_connect('inbox');
        $now = '2026-09-22 14:00:00';

        $db->table('messages')->insert(array_merge([
            'conversation_id' => $conversationId,
            'wa_message_id' => 'fase1e-' . bin2hex(random_bytes(6)),
            'direction' => 'incoming',
            'message_type' => 'text',
            'sender_jid' => '628123450000@s.whatsapp.net',
            'text' => null,
            'message_timestamp' => $now,
            'send_status' => 'received',
            'is_internal' => false,
            'created_at' => $now,
        ], $override));

        return (int) $db->insertID();
    }

    private function conversation(int $id): array
    {
        return db_connect('inbox')
            ->table('conversations')
            ->getWhere(['id' => $id])
            ->getRowArray();
    }

    private function jumlahPesan(): int
    {
        return db_connect('inbox')->table('messages')->countAllResults();
    }

    /**
     * AC-014 (a): a message body match alone finds the conversation and fills
     * `match_snippet` with the keyword, `is_internal = false` and the message
     * timestamp in the same format as the other time columns.
     */
    public function testQCocokIsiPesanMembawaMatchSnippet(): void
    {
        $jamet = $this->seedIdentitas('ac-014a-jamet@s.whatsapp.net', ['contact_name' => 'Jamet']);
        $this->seedMessage($jamet, ['text' => 'pesan atas nama Saerah, 2 lusin kaos']);
        $this->seedIdentitas('ac-014a-lain@s.whatsapp.net', ['contact_name' => 'Andi']);

        $this->assertSame([$jamet], $this->idsDari('?q=saerah'));

        $snippet = $this->barisPencarian('?q=saerah', $jamet)['match_snippet'];

        $this->assertSame('pesan atas nama Saerah, 2 lusin kaos', $snippet['text']);
        $this->assertFalse($snippet['is_internal']);
        $this->assertSame('2026-09-22 14:00:00', $snippet['message_timestamp']);
    }

    /**
     * AC-014 (b): customer message, staff reply and Internal Note are all
     * searched, and only the Internal Note is flagged.
     */
    public function testQCocokPesanPelangganStaffDanInternalNote(): void
    {
        $masuk = $this->seedIdentitas('ac-014b-masuk@s.whatsapp.net', [
            'contact_name' => 'Pelanggan Masuk',
            'last_message_at' => '2026-09-22 14:00:00',
        ]);
        $this->seedMessage($masuk, ['direction' => 'incoming', 'text' => 'kata kunci dari pelanggan']);

        $keluar = $this->seedIdentitas('ac-014b-keluar@s.whatsapp.net', [
            'contact_name' => 'Pelanggan Keluar',
            'last_message_at' => '2026-09-22 13:00:00',
        ]);
        $this->seedMessage($keluar, [
            'direction' => 'outgoing',
            'send_status' => 'sent',
            'sent_by_user_id' => 7,
            'text' => 'kata kunci dari staff',
        ]);

        $internal = $this->seedIdentitas('ac-014b-internal@s.whatsapp.net', [
            'contact_name' => 'Pelanggan Internal',
            'last_message_at' => '2026-09-22 12:00:00',
        ]);
        $this->seedMessage($internal, [
            'direction' => 'outgoing',
            'send_status' => 'sent',
            'sent_by_user_id' => 7,
            'is_internal' => true,
            'text' => 'kata kunci catatan internal',
        ]);

        $this->assertSame([$masuk, $keluar, $internal], $this->idsDari('?q=' . rawurlencode('kata kunci')));

        $this->assertFalse($this->barisPencarian('?q=' . rawurlencode('kata kunci'), $masuk)['match_snippet']['is_internal']);
        $this->assertFalse($this->barisPencarian('?q=' . rawurlencode('kata kunci'), $keluar)['match_snippet']['is_internal']);
        $this->assertTrue($this->barisPencarian('?q=' . rawurlencode('kata kunci'), $internal)['match_snippet']['is_internal']);
    }

    /**
     * AC-014 (c): a closed conversation outside the newest 50 is still found by
     * an OLD matching message, and `status` keeps applying with `q` (AND).
     */
    public function testQCocokIsiPesanLamaDiLuarHalamanPertama(): void
    {
        $this->seedBerurutan(55);

        $lama = $this->seedIdentitas('ac-014c-lama@s.whatsapp.net', [
            'status' => 'closed',
            'contact_name' => 'Pelanggan Jadul',
            'last_message_at' => '2020-01-01 10:00:00',
            'updated_at' => '2020-01-01 10:00:00',
        ]);
        $this->seedMessage($lama, [
            'text' => 'pesanan lama dengan kata kunci khusus',
            'message_timestamp' => '2020-01-01 09:00:00',
            'created_at' => '2020-01-01 09:00:00',
        ]);
        $this->seedMessage($lama, [
            'text' => 'pesan terakhir tanpa kata itu',
            'message_timestamp' => '2020-01-01 10:00:00',
            'created_at' => '2020-01-01 10:00:00',
        ]);

        $this->assertSame([$lama], $this->idsDari('?status=selesai&q=' . rawurlencode('kata kunci khusus')));
        $this->assertSame([], $this->idsDari('?status=open&q=' . rawurlencode('kata kunci khusus')));
    }

    /**
     * AC-014 (d) + CL-017: only the NEWEST matching message is shown, a
     * conversation appears once, and equal timestamps fall back to the larger
     * `id`. A newer message that does not match must not steal the snippet.
     */
    public function testMatchSnippetDariPesanCocokTerbaruDanTieBreakIdTerbesar(): void
    {
        $palingBaru = $this->seedIdentitas('ac-014d-baru@s.whatsapp.net', ['contact_name' => 'Pelanggan Baru']);
        $this->seedMessage($palingBaru, ['text' => 'cocok pesan lama', 'message_timestamp' => '2026-09-20 08:00:00']);
        $this->seedMessage($palingBaru, ['text' => 'cocok pesan tengah', 'message_timestamp' => '2026-09-21 08:00:00']);
        $this->seedMessage($palingBaru, ['text' => 'cocok pesan terbaru', 'message_timestamp' => '2026-09-22 08:00:00']);
        $this->seedMessage($palingBaru, ['text' => 'pesan paling akhir tanpa kata itu', 'message_timestamp' => '2026-09-23 08:00:00']);

        $snippet = $this->barisPencarian('?q=cocok', $palingBaru)['match_snippet'];
        $this->assertSame('cocok pesan terbaru', $snippet['text']);
        $this->assertSame('2026-09-22 08:00:00', $snippet['message_timestamp']);

        $tie = $this->seedIdentitas('ac-014d-tie@s.whatsapp.net', ['contact_name' => 'Pelanggan Tie']);
        $this->seedMessage($tie, ['text' => 'cocok tie id kecil', 'message_timestamp' => '2026-09-22 09:00:00']);
        $this->seedMessage($tie, ['text' => 'cocok tie id besar', 'message_timestamp' => '2026-09-22 09:00:00']);

        $this->assertSame('cocok tie id besar', $this->barisPencarian('?q=cocok', $tie)['match_snippet']['text']);
    }

    /**
     * AC-014 (e) / CL-018: an identity match wins, so `match_snippet` stays
     * null even when the messages also hold the keyword; without `q` the key
     * is present and null on every conversation.
     */
    public function testMatchSnippetNullSaatCocokLewatIdentitasDanTanpaQ(): void
    {
        $saerah = $this->seedIdentitas('ac-014e-saerah@s.whatsapp.net', ['contact_name' => 'Saerah Cetak']);
        $this->seedMessage($saerah, ['text' => 'pesan ini juga memuat saerah']);

        $this->assertNull($this->barisPencarian('?q=saerah', $saerah)['match_snippet']);

        $tanpaQ = $this->payloadDari('');

        $this->assertCount(1, $tanpaQ);
        $this->assertArrayHasKey('match_snippet', $tanpaQ[0]);
        $this->assertNull($tanpaQ[0]['match_snippet']);
    }

    /**
     * AC-014 (f): a soft-deleted matching message is invisible, and a NULL
     * `text` (media without caption) raises no error.
     */
    public function testPesanSoftDeleteDanTeksNullTidakCocok(): void
    {
        $hapus = $this->seedIdentitas('ac-014f-hapus@s.whatsapp.net', ['contact_name' => 'Pelanggan Hapus']);
        $this->seedMessage($hapus, [
            'text' => 'kata kunci yang sudah dihapus',
            'deleted_at' => '2026-09-22 15:00:00',
        ]);

        $media = $this->seedIdentitas('ac-014f-media@s.whatsapp.net', ['contact_name' => 'Pelanggan Media']);
        $this->seedMessage($media, ['message_type' => 'image', 'text' => null]);

        $cocok = $this->seedIdentitas('ac-014f-cocok@s.whatsapp.net', ['contact_name' => 'Pelanggan Cocok']);
        $this->seedMessage($cocok, ['text' => 'isi pesan yang dicari']);

        $this->assertSame([], $this->idsDari('?q=' . rawurlencode('kata kunci yang sudah dihapus')));
        $this->assertSame([], $this->idsDari('?q=zzz'));
        $this->assertSame([$cocok], $this->idsDari('?q=' . rawurlencode('isi pesan yang dicari')));
    }

    /**
     * AC-014 (h): CL-008 also holds inside message text, so `%` and `_` are
     * literal characters, not wildcards.
     */
    public function testPersenDanUnderscoreLiteralDiIsiPesan(): void
    {
        $diskon = $this->seedIdentitas('ac-014h-diskon@s.whatsapp.net', ['contact_name' => 'Pelanggan Diskon']);
        $this->seedMessage($diskon, ['text' => 'ada diskon 50% bulan ini']);

        $lain = $this->seedIdentitas('ac-014h-lain@s.whatsapp.net', ['contact_name' => 'Pelanggan Lain']);
        $this->seedMessage($lain, ['text' => 'harga 500 ribu saja']);

        $garisBawah = $this->seedIdentitas('ac-014h-garis@s.whatsapp.net', ['contact_name' => 'Pelanggan Garis']);
        $this->seedMessage($garisBawah, ['text' => 'kirim ke toko_budi dulu']);

        $spasi = $this->seedIdentitas('ac-014h-spasi@s.whatsapp.net', ['contact_name' => 'Pelanggan Spasi']);
        $this->seedMessage($spasi, ['text' => 'kirim ke toko budi dulu']);

        // Kalau % jadi wildcard, "50%" akan ikut cocok ke pesan "500".
        $this->assertSame([$diskon], $this->idsDari('?q=' . rawurlencode('50%')));

        // Kalau _ jadi wildcard (1 karakter apa saja), pesan "toko budi" ikut cocok.
        $this->assertSame([$garisBawah], $this->idsDari('?q=' . rawurlencode('toko_')));
    }

    /**
     * AC-014 (i) / CON-004: the search only reads -- no status, owner, snooze,
     * last-message or "seen" column changes, and no new `messages` row.
     */
    public function testPencarianIsiPesanHanyaMembaca(): void
    {
        $id = $this->seedIdentitas('ac-014i@s.whatsapp.net', [
            'contact_name' => 'Pelanggan Baca',
            'snoozed_until' => '2099-01-01 00:00:00',
            'last_seen_by_assignee_at' => '2026-09-22 13:00:00',
        ]);
        $this->seedMessage($id, ['text' => 'kata kunci untuk dibaca saja']);

        $sebelum = $this->conversation($id);
        $jumlahPesanSebelum = $this->jumlahPesan();

        $this->assertSame([$id], $this->idsDari('?q=' . rawurlencode('kata kunci untuk dibaca')));

        $sesudah = $this->conversation($id);

        foreach (['assigned_to', 'status', 'snoozed_until', 'last_message_at', 'last_message_direction', 'last_seen_by_assignee_at'] as $kolom) {
            $this->assertSame($sebelum[$kolom], $sesudah[$kolom], 'Kolom ' . $kolom . ' tidak boleh berubah karena pencarian.');
        }

        $this->assertSame($jumlahPesanSebelum, $this->jumlahPesan());
    }

    /**
     * AC-014 (g) at API level: the snippet is never longer than 120 characters
     * plus the two ellipses, keeps the keyword, loses new lines and is marked
     * as cut on both sides.
     */
    public function testMatchSnippetApiTidakMelebihi122Karakter(): void
    {
        $elipsis = "\u{2026}";
        $id = $this->seedIdentitas('ac-014g@s.whatsapp.net', ['contact_name' => 'Pelanggan Panjang']);
        $this->seedMessage($id, [
            'text' => str_repeat('x', 200) . "\n" . 'Saerah' . "\n" . str_repeat('y', 200),
        ]);

        $snippet = $this->barisPencarian('?q=saerah', $id)['match_snippet'];

        $this->assertLessThanOrEqual(122, mb_strlen($snippet['text']));
        $this->assertStringContainsString('Saerah', $snippet['text']);
        $this->assertStringNotContainsString("\n", $snippet['text']);
        $this->assertStringStartsWith($elipsis, $snippet['text']);
        $this->assertStringEndsWith($elipsis, $snippet['text']);
    }

    /**
     * REQ-016 (c): without `q` the endpoint must not touch `messages` at all,
     * and the single aggregate query runs only when a keyword is sent.
     */
    public function testTanpaQTidakMenyentuhTabelPesan(): void
    {
        $id = $this->seedIdentitas('ac-016c@s.whatsapp.net', ['contact_name' => 'Pelanggan Tanpa Q']);
        $this->seedMessage($id, ['text' => 'isi pesan apa saja']);

        $this->assertSame([$id], $this->idsDari(''));

        $db = db_connect('inbox');
        $this->assertStringNotContainsString('messages', (string) $db->getLastQuery());

        $this->assertSame([$id], $this->idsDari('?q=' . rawurlencode('isi pesan apa saja')));
        $this->assertStringContainsString('messages', (string) $db->getLastQuery());
    }
}

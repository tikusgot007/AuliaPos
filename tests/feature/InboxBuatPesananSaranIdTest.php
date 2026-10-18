<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * `saran_id` hand-off of "Buat Pesanan" (AC-5 / AC-10 / AC-12 of
 * docs/requirements/2026-10-18-isi-keranjang-dari-saran-mara.md).
 *
 * The endpoint only ever appends `saran_id` to redirect_url when the suggestion
 * belongs to THAT conversation, is active and has product lines. Kasir
 * re-checks everything again (KeranjangDariSaran), so this is a convenience
 * filter, not the security boundary.
 *
 * @internal
 */
final class InboxBuatPesananSaranIdTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
        $this->inbox->table('mara_suggestions')->emptyTable();

        $forge = \Config\Database::forge();
        $forge->dropTable('pelanggan', true);
        $forge->dropTable('users', true);
        $forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true],
            'nama'       => ['type' => 'TEXT'],
            'no_hp'      => ['type' => 'TEXT', 'null' => true],
            'alamat'     => ['type' => 'TEXT', 'null' => true],
            'diskon'     => ['type' => 'REAL', 'default' => 0],
            'created_at' => ['type' => 'TEXT', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pelanggan', true);
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
            'id' => 21, 'username' => 'user21', 'nama' => 'User 21', 'inisial' => 'U21', 'role' => 'kasir', 'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->inbox->table('mara_suggestions')->emptyTable();
        $forge = \Config\Database::forge();
        $forge->dropTable('pelanggan', true);
        $forge->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    /** Conversation whose pelanggan already follows the `NAMA 4DIGIT` format (phase 1 finishes at once). */
    private function seedPercakapanSiap(string $digit = '7890'): array
    {
        $nomor = '62812345' . $digit;
        db_connect()->table('pelanggan')->insert(['nama' => 'BUDI ' . $digit, 'no_hp' => '0812345' . $digit, 'diskon' => 0]);
        $pelangganId = (int) db_connect()->insertID();

        $now = date('Y-m-d H:i:s');
        $this->inbox->table('conversations')->insert([
            'chat_id' => $nomor . '@s.whatsapp.net', 'jid_type' => 'pn', 'phone' => $nomor,
            'status' => 'open', 'contact_name' => 'Budi', 'pelanggan_id' => $pelangganId,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return [(int) $this->inbox->insertID(), $pelangganId];
    }

    private function seedSaran(int $conversationId, ?array $cartItems, array $override = []): int
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('mara_suggestions')->insert(array_merge([
            'conversation_id' => $conversationId, 'type' => 'balasan', 'priority' => 'normal',
            'text' => 'saran', 'action' => 'buka_kasir',
            'cart_items' => $cartItems === null ? null : json_encode($cartItems),
            'status' => 'active', 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_at' => $now, 'updated_at' => $now,
        ], $override));

        return (int) $this->inbox->insertID();
    }

    private function kirimBuatPesanan(int $conversationId, array $body = [])
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'X-CSRF-TOKEN' => csrf_hash()])
            ->post('/inbox/percakapan/' . $conversationId . '/buat-pesanan', $body);
    }

    private function json($response): array
    {
        $decoded = json_decode($response->response()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function saranIdDiRedirect(array $json): ?string
    {
        parse_str((string) parse_url($json['redirect_url'] ?? '', PHP_URL_QUERY), $q);

        return $q['saran_id'] ?? null;
    }

    /** AC-12: a valid suggestion id travels with the redirect. */
    public function testSaranIdIkutRedirect(): void
    {
        [$cid] = $this->seedPercakapanSiap();
        $saranId = $this->seedSaran($cid, [['produk_id' => 68, 'jumlah' => 4]]);

        $json = $this->json($this->kirimBuatPesanan($cid, ['saran_id' => $saranId]));

        $this->assertSame('success', $json['status']);
        $this->assertSame((string) $saranId, $this->saranIdDiRedirect($json));
    }

    /** No saran_id sent -> redirect exactly as before. */
    public function testTanpaSaranIdRedirectTidakBerubah(): void
    {
        [$cid] = $this->seedPercakapanSiap();
        $this->seedSaran($cid, [['produk_id' => 68, 'jumlah' => 4]]);

        $json = $this->json($this->kirimBuatPesanan($cid));

        $this->assertSame('success', $json['status']);
        $this->assertNull($this->saranIdDiRedirect($json));
    }

    /** AC-10: a suggestion of ANOTHER conversation is never forwarded. */
    public function testSaranMilikPercakapanLainTidakDiteruskan(): void
    {
        [$cid] = $this->seedPercakapanSiap('7890');
        [$cidLain] = $this->seedPercakapanSiap('1111');
        $saranLain = $this->seedSaran($cidLain, [['produk_id' => 68, 'jumlah' => 4]]);

        $json = $this->json($this->kirimBuatPesanan($cid, ['saran_id' => $saranLain]));

        $this->assertSame('success', $json['status']);
        $this->assertNull($this->saranIdDiRedirect($json));
    }

    /** AC-10: expired / superseded suggestions are not forwarded. */
    public function testSaranKedaluwarsaDanDigantiTidakDiteruskan(): void
    {
        [$cid] = $this->seedPercakapanSiap();
        $kedaluwarsa = $this->seedSaran($cid, [['produk_id' => 68, 'jumlah' => 1]], ['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        $diganti     = $this->seedSaran($cid, [['produk_id' => 68, 'jumlah' => 1]], ['status' => 'superseded']);

        $this->assertNull($this->saranIdDiRedirect($this->json($this->kirimBuatPesanan($cid, ['saran_id' => $kedaluwarsa]))));
        $this->assertNull($this->saranIdDiRedirect($this->json($this->kirimBuatPesanan($cid, ['saran_id' => $diganti]))));
    }

    /** A suggestion without product lines carries nothing (cart stays empty as today). */
    public function testSaranTanpaCartItemsTidakDiteruskan(): void
    {
        [$cid] = $this->seedPercakapanSiap();
        $saranId = $this->seedSaran($cid, null);

        $json = $this->json($this->kirimBuatPesanan($cid, ['saran_id' => $saranId]));

        $this->assertNull($this->saranIdDiRedirect($json));
    }

    /** Garbage saran_id never breaks the order flow. */
    public function testSaranIdSampahDiabaikan(): void
    {
        [$cid] = $this->seedPercakapanSiap();

        foreach (['abc', '-5', '0', '99999999999'] as $sampah) {
            $json = $this->json($this->kirimBuatPesanan($cid, ['saran_id' => $sampah]));
            $this->assertSame('success', $json['status'], 'saran_id=' . $sampah);
            $this->assertNull($this->saranIdDiRedirect($json));
        }
    }
}

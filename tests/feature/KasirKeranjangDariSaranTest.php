<?php

use App\Libraries\KeranjangDariSaran;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * Kasir cart pre-fill from an M.A.R.A suggestion: AC-5..AC-7, AC-10, AC-11 of
 * docs/requirements/2026-10-18-isi-keranjang-dari-saran-mara.md.
 *
 * `produk` lives in group `default` (SQLite under ENVIRONMENT=testing),
 * `mara_suggestions` / `conversations` in group `inbox`.
 *
 * @internal
 */
final class KasirKeranjangDariSaranTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
        $this->inbox->table('mara_suggestions')->emptyTable();

        $forge = \Config\Database::forge();
        $forge->dropTable('produk', true);
        $forge->addField([
            'id'          => ['type' => 'INTEGER', 'auto_increment' => true],
            'nama'        => ['type' => 'TEXT'],
            'kategori_id' => ['type' => 'INTEGER'],
            'satuan'      => ['type' => 'TEXT', 'null' => true],
            'harga_jual'  => ['type' => 'REAL'],
            'harga_beli'  => ['type' => 'REAL', 'null' => true],
            'is_active'   => ['type' => 'INTEGER', 'default' => 1],
            'is_locked'   => ['type' => 'INTEGER', 'default' => 0],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('produk', true);

        $db = db_connect();
        $produk = [
            [68, 'Dompet', 16, 'lembar', 1100, 300, 1, 0],
            [62, '3x4', 16, 'lembar', 800, 200, 1, 0],
            [70, 'Mati', 16, 'lembar', 900, 200, 0, 0],
            [1, 'Banner', 4, 'm', 0, 0, 1, 1],
            [80, 'Fotokopi', 2, 'lembar', 300, 100, 1, 0],
        ];
        foreach ($produk as [$id, $nama, $kat, $satuan, $jual, $beli, $aktif, $kunci]) {
            $db->table('produk')->insert([
                'id' => $id, 'nama' => $nama, 'kategori_id' => $kat, 'satuan' => $satuan,
                'harga_jual' => $jual, 'harga_beli' => $beli, 'is_active' => $aktif, 'is_locked' => $kunci,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->inbox->table('mara_suggestions')->emptyTable();
        \Config\Database::forge()->dropTable('produk', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    /** Conversation linked to a pelanggan, plus an active suggestion with cart_items. */
    private function seedSaran(int $pelangganId, ?array $cartItems, array $override = []): array
    {
        $cid = $this->seedConversation('62812' . random_int(10000000, 99999999) . '@s.whatsapp.net');
        $this->inbox->table('conversations')->where('id', $cid)->update(['pelanggan_id' => $pelangganId]);

        $now = date('Y-m-d H:i:s');
        $this->inbox->table('mara_suggestions')->insert(array_merge([
            'conversation_id' => $cid,
            'type'            => 'balasan',
            'priority'        => 'normal',
            'text'            => 'saran uji',
            'action'          => 'buka_kasir',
            'cart_items'      => $cartItems === null ? null : json_encode($cartItems),
            'status'          => 'active',
            'expires_at'      => date('Y-m-d H:i:s', time() + 3600),
            'created_at'      => $now,
            'updated_at'      => $now,
        ], $override));

        return [$cid, (int) $this->inbox->insertID()];
    }

    /** AC-5: name, unit, category and price come from `produk`; subtotal = price x qty. */
    public function testItemMasukDenganHargaDariProduk(): void
    {
        [, $saranId] = $this->seedSaran(7, [
            ['produk_id' => 68, 'jumlah' => 4],
            ['produk_id' => 62, 'jumlah' => 10],
        ]);

        $hasil = (new KeranjangDariSaran())->bangun($saranId, 7);

        $this->assertSame(0, $hasil['jumlah_gagal']);
        $this->assertCount(2, $hasil['items']);

        $dompet = $hasil['items'][0];
        $this->assertSame(68, $dompet['produk_id']);
        $this->assertSame('Dompet', $dompet['nama']);
        $this->assertSame('lembar', $dompet['satuan']);
        $this->assertSame(16, $dompet['kategori_id']);
        $this->assertSame(1100, $dompet['harga']);
        $this->assertSame(4, $dompet['jumlah']);
        $this->assertSame(4400, $dompet['subtotal']);
        $this->assertTrue($dompet['is_cetak'], 'kategori Foto (16) = is_cetak');
        $this->assertFalse($dompet['is_custom']);
        $this->assertFalse($dompet['is_banner']);
        $this->assertFalse($dompet['is_manual']);

        $this->assertSame(8000, $hasil['items'][1]['subtotal']);
    }

    /** AC-6: valid lines still go in; failures are counted. */
    public function testSebagianProdukTidakAktifTetapMasukDenganJumlahGagal(): void
    {
        [, $saranId] = $this->seedSaran(7, [
            ['produk_id' => 68, 'jumlah' => 2],
            ['produk_id' => 70, 'jumlah' => 1],   // is_active = 0
            ['produk_id' => 9999, 'jumlah' => 1], // tidak ada
        ]);

        $hasil = (new KeranjangDariSaran())->bangun($saranId, 7);

        $this->assertCount(1, $hasil['items']);
        $this->assertSame(68, $hasil['items'][0]['produk_id']);
        $this->assertSame(2, $hasil['jumlah_gagal']);
    }

    /** AC-11: price-0 products (Banner, manual, custom) are never auto-filled. */
    public function testProdukHargaNolDitolak(): void
    {
        [, $saranId] = $this->seedSaran(7, [
            ['produk_id' => 1, 'jumlah' => 1],
            ['produk_id' => 80, 'jumlah' => 3],
        ]);

        $hasil = (new KeranjangDariSaran())->bangun($saranId, 7);

        $this->assertCount(1, $hasil['items']);
        $this->assertSame(80, $hasil['items'][0]['produk_id']);
        $this->assertSame(1, $hasil['jumlah_gagal']);
    }

    /** AC-7: nothing usable -> empty cart, failure count carried. */
    public function testSemuaGagalKeranjangKosongDenganPeringatan(): void
    {
        [, $saranId] = $this->seedSaran(7, [
            ['produk_id' => 70, 'jumlah' => 1],
            ['produk_id' => 1, 'jumlah' => 1],
        ]);

        $hasil = (new KeranjangDariSaran())->bangun($saranId, 7);

        $this->assertSame([], $hasil['items']);
        $this->assertSame(2, $hasil['jumlah_gagal']);
    }

    /** AC-7: suggestion without cart_items -> empty, no warning. */
    public function testSaranTanpaCartItemsKosongTanpaPeringatan(): void
    {
        [, $saranId] = $this->seedSaran(7, null);

        $hasil = (new KeranjangDariSaran())->bangun($saranId, 7);

        $this->assertSame([], $hasil['items']);
        $this->assertSame(0, $hasil['jumlah_gagal']);
    }

    /** AC-10: a suggestion of a conversation linked to ANOTHER pelanggan is refused. */
    public function testSaranMilikPercakapanLainDitolak(): void
    {
        [, $saranId] = $this->seedSaran(7, [['produk_id' => 68, 'jumlah' => 1]]);

        $hasil = (new KeranjangDariSaran())->bangun($saranId, 999);

        $this->assertSame([], $hasil['items']);
        $this->assertSame(0, $hasil['jumlah_gagal']);
    }

    /** AC-10: expired or superseded suggestions are ignored. */
    public function testSaranKedaluwarsaDiabaikan(): void
    {
        [, $kedaluwarsa] = $this->seedSaran(7, [['produk_id' => 68, 'jumlah' => 1]], [
            'expires_at' => date('Y-m-d H:i:s', time() - 60),
        ]);
        [, $diganti] = $this->seedSaran(7, [['produk_id' => 68, 'jumlah' => 1]], [
            'status' => 'superseded',
        ]);

        $lib = new KeranjangDariSaran();

        $this->assertSame([], $lib->bangun($kedaluwarsa, 7)['items']);
        $this->assertSame([], $lib->bangun($diganti, 7)['items']);
    }

    /** AC-11: unknown id / garbage in the stored JSON never throws and never trusts M.A.R.A. */
    public function testProdukIdPalsuTidakDipercaya(): void
    {
        [, $saranId] = $this->seedSaran(7, [['produk_id' => 'x', 'jumlah' => 1], ['harga' => 1]]);

        $hasil = (new KeranjangDariSaran())->bangun($saranId, 7);

        $this->assertSame([], $hasil['items']);
    }

    /** Cart price never comes from the stored suggestion even if the JSON was tampered with. */
    public function testHargaDiJsonSaranDiabaikan(): void
    {
        [, $saranId] = $this->seedSaran(7, [['produk_id' => 68, 'jumlah' => 2, 'harga' => 1, 'nama' => 'Palsu']]);

        $hasil = (new KeranjangDariSaran())->bangun($saranId, 7);

        $this->assertSame(1100, $hasil['items'][0]['harga']);
        $this->assertSame('Dompet', $hasil['items'][0]['nama']);
    }

    /** Missing inbox table / DB failure must not break the Kasir page. */
    public function testIdNolTidakMelempar(): void
    {
        $hasil = (new KeranjangDariSaran())->bangun(0, 7);

        $this->assertSame(['items' => [], 'jumlah_gagal' => 0], $hasil);
    }
}

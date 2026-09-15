<?php

require_once __DIR__ . '/../_support/Fakes/ShiftLeaderClockOverride.php';

use App\Models\TransaksiModel;
use App\Services\FakeClock;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Phase 2 — kapabilitas "Selesai dari Kasir" (workflow kasir/index.php).
 *
 * Menegaskan pembagian KONTEKS:
 *  - /api/kasir/selesaikan-transaksi : kasir boleh menyelesaikan
 *    transaksi kasir_pos BUATANNYA SENDIRI, HANYA jika sudah lunas.
 *  - /api/ubah-status (Detail/Daftar) : kasir tetap DITOLAK, tak berubah.
 *
 * Semua enforcement diuji lewat HTTP (bukan sekadar tombol UI).
 *
 * @internal
 */
final class KasirSelesaikanTransaksiTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const SELESAIKAN_KASIR = 'api/kasir/selesaikan-transaksi';
    private const UBAH_STATUS      = 'api/ubah-status';

    private function sesi(string $role, int $idUser): array
    {
        return [
            'isLoggedIn'    => true,
            'role'          => $role,
            'id_user'       => $idUser,
            'nama'          => 'Test ' . $role,
            'last_activity' => time(),
        ];
    }

    private function seedTransaksi(array $override = []): int
    {
        $db = db_connect();
        $db->table('transaksi')->insert(array_merge([
            'kode_invoice'      => 'INV-T-' . random_int(1000, 9999),
            'tanggal'           => date('Y-m-d H:i:s'),
            'kasir_id'          => 7,
            'grand_total'       => 100000,
            'total_dibayar'     => 0,
            'status_pembayaran' => 'belum_bayar',
            'status'            => 'proses',
            'sumber'            => 'kasir_pos',
        ], $override));

        return (int) $db->insertID();
    }

    private function bayarLunas(int $id, int $grandTotal = 100000): void
    {
        (new TransaksiModel())->tambahPembayaran($id, [
            'jumlah'   => $grandTotal,
            'metode'   => 'tunai',
            'tanggal'  => date('Y-m-d H:i:s'),
            'kasir_id' => 7,
        ]);
    }

    private function statusTransaksi(int $id): string
    {
        return (string) db_connect()->table('transaksi')->getWhere(['id' => $id])->getRow('status');
    }

    private function seedUser(int $id, string $role, int $isActive, ?int $priority): void
    {
        db_connect()->table('users')->insert([
            'id'        => $id,
            'username'  => 'user' . $id,
            'role'      => $role,
            'is_active' => $isActive,
            'priority'  => $priority,
        ]);
    }

    private function seedJadwal(int $karyawanId, string $tanggal, string $shift): void
    {
        db_connect()->table('jadwal')->insert([
            'karyawan_id' => $karyawanId,
            'tanggal'     => $tanggal,
            'shift'       => $shift,
        ]);
    }

    /**
     * Tanggal/jam TETAP (bukan wall-clock sandbox), dipakai bersama
     * FakeClock::$override supaya Effective Shift Leader deterministik
     * lewat jalur HTTP asli -- lihat tests/_support/Fakes/
     * ShiftLeaderClockOverride.php untuk penjelasan tekniknya. '09:00'
     * berada di dalam jendela P (08:00-15:00).
     */
    private const HARI_TETAP = '2026-09-14';
    private const JAM_TETAP  = '09:00';

    protected function tearDown(): void
    {
        FakeClock::reset(); // jangan bocor ke test lain
        parent::tearDown();
    }

    // ---- POC: otorisasi Shift Leader di workflow umum (Tahap 3, Bagian H/J.E) ----
    //
    // 4 test di bawah HARUS benar-benar melewati HTTP -> Api::ubahStatus()
    // -> Authority::isCurrentShiftLeader() -> TransaksiModel::ubahStatus(),
    // deterministik tanpa bergantung jam wall-clock sandbox. "Sekarang"
    // TETAP dihitung server-side oleh App\Services\EffectiveShiftLeaderService
    // (endpoint tidak menerima override waktu dari request/client sama
    // sekali -- FakeClock hanya menggantikan date() PHP untuk kode di
    // namespace App\Services selama test ini berjalan, lihat
    // ShiftLeaderClockOverride.php).

    public function testShiftLeaderBisaSetSelesaiDiWorkflowUmum(): void
    {
        FakeClock::$override = self::HARI_TETAP . ' ' . self::JAM_TETAP . ':00';

        $this->seedUser(50, 'kasir', 1, 10);
        $this->seedJadwal(50, self::HARI_TETAP, 'P');

        $id = $this->seedTransaksi(['kasir_id' => 99]); // bukan transaksi milik user 50
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('kasir', 50))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'selesai']);

        $res->assertJSONFragment(['status' => 'success']);
        $this->assertSame('selesai', $this->statusTransaksi($id));
    }

    public function testKasirBukanLeaderTidakBisaWalauPunyaPriorityDanJadwal(): void
    {
        FakeClock::$override = self::HARI_TETAP . ' ' . self::JAM_TETAP . ':00';

        $this->seedUser(50, 'kasir', 1, 10); // priority tertinggi -> Leader
        $this->seedUser(51, 'kasir', 1, 5);  // punya priority & jadwal juga, tapi BUKAN Leader
        $this->seedJadwal(50, self::HARI_TETAP, 'P');
        $this->seedJadwal(51, self::HARI_TETAP, 'P');

        $id = $this->seedTransaksi(['kasir_id' => 99]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('kasir', 51))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'selesai']);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    public function testShiftLeaderTidakMengubahUsersRole(): void
    {
        FakeClock::$override = self::HARI_TETAP . ' ' . self::JAM_TETAP . ':00';

        $this->seedUser(50, 'kasir', 1, 10);
        $this->seedJadwal(50, self::HARI_TETAP, 'P');

        $id = $this->seedTransaksi(['kasir_id' => 99]);
        $this->bayarLunas($id);

        $this->withSession($this->sesi('kasir', 50))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'selesai']);

        $role = db_connect()->table('users')->getWhere(['id' => 50])->getRow('role');
        $this->assertSame('kasir', $role); // TIDAK PERNAH jadi 'shift_leader'
    }

    public function testShiftLeaderTetapDitolakJikaBelumLunas(): void
    {
        FakeClock::$override = self::HARI_TETAP . ' ' . self::JAM_TETAP . ':00';

        $this->seedUser(50, 'kasir', 1, 10);
        $this->seedJadwal(50, self::HARI_TETAP, 'P');

        $id = $this->seedTransaksi(['kasir_id' => 99]); // belum dibayar sama sekali

        $res = $this->withSession($this->sesi('kasir', 50))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'selesai']);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    public function testTidakAdaLeaderKasirBiasaTidakDapatFallback(): void
    {
        // Tidak ada user manapun yang di-seed sebagai kandidat -> tidak ada Leader.
        $id = $this->seedTransaksi(['kasir_id' => 99]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'selesai']);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    public function testShiftLeaderYangSudahLewatJamKehilanganAuthorityRequestBerikutnya(): void
    {
        $this->seedUser(50, 'kasir', 1, 10);
        $this->seedJadwal(50, '2026-09-14', 'P'); // 08:00-15:00

        $id = $this->seedTransaksi(['kasir_id' => 99]);
        $this->bayarLunas($id);

        $isLeaderSaatMasihBekerja = \App\Services\Authority::isCurrentShiftLeader(50, '2026-09-14', '09:00');
        $isLeaderSetelahLewat     = \App\Services\Authority::isCurrentShiftLeader(50, '2026-09-14', '15:01');

        $this->assertTrue($isLeaderSaatMasihBekerja);
        $this->assertFalse($isLeaderSetelahLewat);

        // "Request berikutnya" setelah jam shift lewat harus ditolak model,
        // walau id/priority/jadwal orangnya sama persis -- tidak ada state
        // yang nempel (tidak ada current_leader yang disimpan/di-cache).
        $this->expectException(\Exception::class);
        (new TransaksiModel())->ubahStatus($id, 'selesai', false, false, $isLeaderSetelahLewat);
    }

    public function testPerubahanPriorityMengubahAuthoritySesuaiRankingBaru(): void
    {
        $this->seedUser(50, 'kasir', 1, 5);
        $this->seedUser(51, 'kasir', 1, 10);
        $this->seedJadwal(50, '2026-09-14', 'P');
        $this->seedJadwal(51, '2026-09-14', 'P');

        $this->assertFalse(\App\Services\Authority::isCurrentShiftLeader(50, '2026-09-14', '09:00'));
        $this->assertTrue(\App\Services\Authority::isCurrentShiftLeader(51, '2026-09-14', '09:00'));

        // Priority 50 dinaikkan melewati 51 -> authority berpindah seketika
        // (tidak ada cache yang perlu di-invalidate).
        db_connect()->table('users')->where('id', 50)->update(['priority' => 20]);

        $this->assertTrue(\App\Services\Authority::isCurrentShiftLeader(50, '2026-09-14', '09:00'));
        $this->assertFalse(\App\Services\Authority::isCurrentShiftLeader(51, '2026-09-14', '09:00'));
    }

    // ---- A. kasir + kasir/index + LUNAS -> ALLOWED --------------------

    public function testKasirMenyelesaikanTransaksiLunasBuatannyaSendiri(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertOK();
        $res->assertJSONFragment(['status' => 'success']);
        $this->assertSame('selesai', $this->statusTransaksi($id));
    }

    // ---- B & C. kasir + DP / belum bayar -> REJECTED -----------------

    public function testKasirTidakBisaMenyelesaikanTransaksiDp(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7]);
        (new TransaksiModel())->tambahPembayaran($id, [
            'jumlah' => 40000, 'metode' => 'tunai',
            'tanggal' => date('Y-m-d H:i:s'), 'kasir_id' => 7,
        ]);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    public function testKasirTidakBisaMenyelesaikanTransaksiBelumBayar(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7]);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    // ---- Gate kepemilikan & sumber ----------------------------------

    public function testKasirTidakBisaMenyelesaikanTransaksiKasirLain(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 99]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    public function testTransaksiNonKasirPosDitolak(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7, 'sumber' => 'aplikasi_cetak_foto']);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    // ---- E. admin lewat endpoint kasir tetap boleh (bypass ownership) --

    public function testAdminBolehMenyelesaikanTransaksiKasirPosLunasSiapaPun(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 99]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('admin', 1))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertJSONFragment(['status' => 'success']);
        $this->assertSame('selesai', $this->statusTransaksi($id));
    }

    // ---- D. jalur umum /api/ubah-status TIDAK berubah untuk kasir -----

    public function testKasirTetapDitolakLewatUbahStatusUmumWalauLunas(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'selesai']);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    public function testAdminBolehLewatUbahStatusUmumKetikaLunas(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('admin', 1))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'selesai']);

        $res->assertJSONFragment(['status' => 'success']);
        $this->assertSame('selesai', $this->statusTransaksi($id));
    }

    // ---- Tahap 5: batalkan transaksi SELESAI (sebelumnya admin-only) ----

    /** Buat transaksi lunas & langsung tandai SELESAI (lewat model, admin), kembalikan id-nya. */
    private function seedTransaksiSelesai(int $kasirId = 99): int
    {
        $id = $this->seedTransaksi(['kasir_id' => $kasirId]);
        $this->bayarLunas($id);
        (new TransaksiModel())->ubahStatus($id, 'selesai', true);

        return $id;
    }

    public function testAdminBisaBatalkanTransaksiSelesai(): void
    {
        $id = $this->seedTransaksiSelesai();

        $res = $this->withSession($this->sesi('admin', 1))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'batal']);

        $res->assertJSONFragment(['status' => 'success']);
        $this->assertSame('batal', $this->statusTransaksi($id));
    }

    public function testShiftLeaderBisaBatalkanTransaksiSelesai(): void
    {
        FakeClock::$override = self::HARI_TETAP . ' ' . self::JAM_TETAP . ':00';

        $this->seedUser(50, 'kasir', 1, 10);
        $this->seedJadwal(50, self::HARI_TETAP, 'P');

        $id = $this->seedTransaksiSelesai();

        $res = $this->withSession($this->sesi('kasir', 50))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'batal']);

        $res->assertJSONFragment(['status' => 'success']);
        $this->assertSame('batal', $this->statusTransaksi($id));
    }

    public function testKasirBukanLeaderTidakBisaBatalkanTransaksiSelesai(): void
    {
        FakeClock::$override = self::HARI_TETAP . ' ' . self::JAM_TETAP . ':00';

        $this->seedUser(50, 'kasir', 1, 10); // Leader hari ini, TAPI bukan yang login di bawah
        $this->seedJadwal(50, self::HARI_TETAP, 'P');

        $id = $this->seedTransaksiSelesai();

        $res = $this->withSession($this->sesi('kasir', 7)) // kasir biasa, bukan Leader
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'batal']);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('selesai', $this->statusTransaksi($id));
    }

    // ---- Tahap 5.1: PROSES->BATAL diperketat, sama seperti SELESAI->BATAL ----
    // (SEBELUM Tahap 5.1 ini terbuka untuk semua role -- keputusan produk
    // diperketat supaya otoritas pembatalan konsisten: admin atau Shift
    // Leader saja, baik dari PROSES maupun SELESAI.)

    public function testAdminBisaBatalkanTransaksiProses(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7]); // status proses, belum dibayar

        $res = $this->withSession($this->sesi('admin', 1))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'batal']);

        $res->assertJSONFragment(['status' => 'success']);
        $this->assertSame('batal', $this->statusTransaksi($id));
    }

    public function testShiftLeaderBisaBatalkanTransaksiProses(): void
    {
        FakeClock::$override = self::HARI_TETAP . ' ' . self::JAM_TETAP . ':00';

        $this->seedUser(50, 'kasir', 1, 10);
        $this->seedJadwal(50, self::HARI_TETAP, 'P');

        $id = $this->seedTransaksi(['kasir_id' => 7]);

        $res = $this->withSession($this->sesi('kasir', 50))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'batal']);

        $res->assertJSONFragment(['status' => 'success']);
        $this->assertSame('batal', $this->statusTransaksi($id));
    }

    public function testKasirBiasaBukanLeaderTidakBisaBatalkanTransaksiProses(): void
    {
        // Regresi Tahap 5.1: PROSES->BATAL TIDAK LAGI terbuka untuk
        // semua role -- kasir biasa (jelas bukan Shift Leader, tidak
        // ada jadwal/priority sama sekali) sekarang ditolak, sama
        // seperti SELESAI->BATAL.
        $id = $this->seedTransaksi(['kasir_id' => 7]);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'batal']);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }
}

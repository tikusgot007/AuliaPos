<?php

namespace App\Models;

use CodeIgniter\Model;

class ClosingKasModel extends Model
{
    protected $table         = 'closing_kas';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'tanggal',
        'saldo_sistem',
        'saldo_fisik',
        'selisih',
        'updated_at',
        'updated_by',
    ];

    /**
     * Ambil semua closing dalam satu bulan (YYYY-MM), diindeks per
     * tanggal (Y-m-d) supaya gampang dicocokkan dengan daftar tanggal
     * di view.
     */
    public function getByBulan(string $bulan): array
    {
        $awal = $bulan . '-01';
        $akhir = date('Y-m-t', strtotime($awal));

        return $this->getByRentang($awal, $akhir);
    }

    /**
     * Ambil semua closing dalam rentang tanggal (YYYY-MM-DD s.d.
     * YYYY-MM-DD, inklusif), diindeks per tanggal (Y-m-d). Dipakai
     * Laporan Bulanan untuk kolom "Closing Kas".
     */
    public function getByRentang(string $tanggalAwal, string $tanggalAkhir): array
    {
        $rows = $this->where('tanggal >=', $tanggalAwal . ' 00:00:00')
            ->where('tanggal <=', $tanggalAkhir . ' 23:59:59')
            ->orderBy('tanggal', 'ASC')
            ->findAll();

        $byTanggal = [];
        foreach ($rows as $row) {
            $byTanggal[date('Y-m-d', strtotime($row['tanggal']))] = $row;
        }

        return $byTanggal;
    }

    public function getByTanggal(string $tanggal)
    {
        return $this->where('tanggal', $tanggal . ' 23:59:59')->first();
    }

    /**
     * Validasi tanggal closing: format valid, bukan hari ini, bukan masa
     * depan. Pure (tidak menyentuh DB) supaya bisa diuji tanpa bootstrap
     * penuh -- lihat tests/unit/ClosingKasValidasiTanggalTest.php.
     * Dipakai controller di kedua endpoint (detail & simpan) supaya
     * aturannya sama, bukan cuma dicek di JS.
     */
    public static function validasiTanggal(?string $tanggal, ?string $hariIni = null): ?string
    {
        $hariIni ??= date('Y-m-d');

        if (empty($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            return 'Tanggal tidak valid.';
        }

        $d = \DateTime::createFromFormat('Y-m-d', $tanggal);
        if (!$d || $d->format('Y-m-d') !== $tanggal) {
            return 'Tanggal tidak valid.';
        }

        if ($tanggal >= $hariIni) {
            return 'Closing kas hanya untuk tanggal yang sudah lewat.';
        }

        return null;
    }

    /**
     * Simpan (insert atau update) snapshot closing untuk satu tanggal.
     * Satu tanggal = satu record; kalau sudah ada, ditimpa.
     */
    public function simpanClosing(string $tanggal, float $saldoSistem, float $saldoFisik, int $userId): int|bool
    {
        $data = [
            'tanggal'      => $tanggal . ' 23:59:59',
            'saldo_sistem' => $saldoSistem,
            'saldo_fisik'  => $saldoFisik,
            'selisih'      => $saldoFisik - $saldoSistem,
            'updated_at'   => date('Y-m-d H:i:s'),
            'updated_by'   => $userId,
        ];

        $existing = $this->getByTanggal($tanggal);

        if ($existing) {
            return $this->updateDenganAudit($existing, $data, $tanggal, $userId);
        }

        $insertError = null;

        try {
            $result = $this->insert($data);
        } catch (\Throwable $e) {
            // Bisa jadi race unique index `tanggal` (proses lain menyisipkan
            // baris yang sama lebih dulu) -- diputuskan di bawah, bukan
            // di-retry buta oleh pemanggil.
            $result = false;
            $insertError = $e;
        }

        if ($result !== false) {
            return $result;
        }

        // Insert gagal: bila baris tanggal ini sekarang ADA (proses lain
        // menang race), timpa sebagai update; bila tidak ada, ini error asli
        // sehingga exception diteruskan apa adanya.
        $existing = $this->getByTanggal($tanggal);

        if ($existing) {
            return $this->updateDenganAudit($existing, $data, $tanggal, $userId);
        }

        if ($insertError !== null) {
            throw $insertError;
        }

        return false;
    }

    /**
     * Update an existing closing row and append a revision row
     * (`closing_kas_audit`) in the SAME transaction (TODO-BL28): either both
     * the change and its history are stored, or neither is. Mirrors
     * CashExpenseModel::updatePengeluaran() (TODO-BL10).
     *
     * @param array<string,mixed> $existing The row before the change.
     * @param array<string,mixed> $data     The update payload.
     */
    private function updateDenganAudit(array $existing, array $data, string $tanggal, int $userId): int
    {
        $id = (int) $existing['id'];

        $db = \Config\Database::connect($this->DBGroup);
        $db->transStart();

        try {
            // Validation failure returns false without a DB-level error, so
            // transStatus() alone would not catch it -- abort before any audit
            // row is written for a change that never happened.
            if ($this->update($id, $data) === false) {
                throw new \Exception('Gagal memperbarui closing kas.');
            }

            $sesudah = $this->getByTanggal($tanggal) ?? array_merge($existing, $data);

            if (!(new ClosingKasAuditModel())->catatUpdate($id, $existing, $sesudah, $userId)) {
                throw new \Exception('Gagal mencatat riwayat closing kas.');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        $db->transComplete();

        if (!$db->transStatus()) {
            throw new \Exception('Gagal menyimpan closing kas.');
        }

        return $id;
    }
}

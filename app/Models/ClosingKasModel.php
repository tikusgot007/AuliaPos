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
        $existing = $this->getByTanggal($tanggal);

        $data = [
            'tanggal'      => $tanggal . ' 23:59:59',
            'saldo_sistem' => $saldoSistem,
            'saldo_fisik'  => $saldoFisik,
            'selisih'      => $saldoFisik - $saldoSistem,
            'updated_at'   => date('Y-m-d H:i:s'),
            'updated_by'   => $userId,
        ];

        if ($existing) {
            $this->update($existing['id'], $data);
            return $existing['id'];
        }

        return $this->insert($data);
    }
}

<?php

use App\Services\CashBalanceService;

if (!function_exists('getSaldoKasHariIni')) {
    /** @return array{saldo: float, pemasukan: float, pengeluaran: float, kas_awal: float, penjualan: float, pembayaran_piutang: float, pengeluaran_operasional: float, refund: float} */
    function getSaldoKasHariIni(?string $waktuTarget = null): array
    {
        return (new CashBalanceService())->getBalance($waktuTarget);
    }
}

if (!function_exists('getSaldoKasHistoris')) {
    /**
     * Saldo kas historis pada cutoff $at: data live + penjualan tunai yang
     * sudah dipindahkan ke arsip (TODO-BL02). Dipakai jalur Closing Kas untuk
     * tanggal yang BELUM punya snapshot, supaya bulan yang sudah diarsip tidak
     * dihitung 0. Dashboard/opname hari ini tetap memakai
     * getSaldoKasHariIni() (live saja).
     *
     * @return array{saldo: float, pemasukan: float, pengeluaran: float, kas_awal: float, penjualan: float, pembayaran_piutang: float, pengeluaran_operasional: float, refund: float}
     */
    function getSaldoKasHistoris(?string $at = null, ?\App\Services\TransaksiArchiveService $arsip = null): array
    {
        $at ??= date('Y-m-d H:i:s');

        $saldo = getSaldoKasHariIni($at);

        $date = date('Y-m-d', strtotime($at));
        $arsip ??= new \App\Services\TransaksiArchiveService();
        $penjualanArsip = $arsip->getPenjualanTunaiMentah($date, $at);

        if ($penjualanArsip > 0) {
            $saldo['penjualan'] += $penjualanArsip;
            $saldo['pemasukan'] += $penjualanArsip;
            $saldo['saldo'] += $penjualanArsip;
        }

        return $saldo;
    }
}

if (!function_exists('getKasAwalHari')) {
    function getKasAwalHari(?string $tanggal = null): float
    {
        return (new CashBalanceService())->getOpeningCash($tanggal);
    }
}

if (!function_exists('catatKasAwalHari')) {
    function catatKasAwalHari(float $nominal, int $userId, ?string $catatan = null): bool
    {
        return (new CashBalanceService())->saveOpeningCash($nominal, $userId, $catatan);
    }
}

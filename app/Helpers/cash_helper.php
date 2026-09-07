<?php

use App\Services\CashBalanceService;

if (!function_exists('getSaldoKasHariIni')) {
    /** @return array{saldo: float, pemasukan: float, pengeluaran: float, kas_awal: float, penjualan: float, pembayaran_piutang: float, pengeluaran_operasional: float, refund: float} */
    function getSaldoKasHariIni(?string $waktuTarget = null): array
    {
        return (new CashBalanceService())->getBalance($waktuTarget);
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

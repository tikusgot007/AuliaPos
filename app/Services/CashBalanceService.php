<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/** Calculates the cash balance from the date-based cash tables. */
final class CashBalanceService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{saldo: float, pemasukan: float, pengeluaran: float, kas_awal: float, penjualan: float, pembayaran_piutang: float, pengeluaran_operasional: float, refund: float} */
    public function getBalance(?string $at = null): array
    {
        $at ??= date('Y-m-d H:i:s');
        $date = date('Y-m-d', strtotime($at));
        $kasAwal = $this->sumExpenses(['kas_awal_hari'], $date, $at);
        $penjualan = $this->getCashSales($date, $at);
        $pengeluaran = $this->sumExpensesExcept(['kas_awal_hari', 'refund_penjualan'], $date, $at);
        $refund = $this->sumExpenses(['refund_penjualan'], $date, $at);
        $pemasukan = $kasAwal + $penjualan;
        $totalPengeluaran = $pengeluaran + $refund;

        return [
            'saldo' => $pemasukan - $totalPengeluaran,
            'pemasukan' => $pemasukan,
            'pengeluaran' => $totalPengeluaran,
            'kas_awal' => $kasAwal,
            'penjualan' => $penjualan,
            'pembayaran_piutang' => 0.0,
            'pengeluaran_operasional' => $pengeluaran,
            'refund' => $refund,
        ];
    }

    public function getOpeningCash(?string $date = null): float
    {
        $date ??= date('Y-m-d');
        return $this->sumExpenses(['kas_awal_hari'], $date, $date . ' 23:59:59');
    }

    public function saveOpeningCash(float $amount, int $userId, ?string $note = null): bool
    {
        $date = date('Y-m-d');
        $existing = $this->db->table('cash_expense')->select('id')
            ->where('kategori', 'kas_awal_hari')
            ->where('tanggal >=', $date . ' 00:00:00')->where('tanggal <=', $date . ' 23:59:59')
            ->get()->getRowArray();
        $now = date('Y-m-d H:i:s');

        if ($existing !== null) {
            return $this->db->table('cash_expense')->where('id', $existing['id'])->update([
                'nominal' => $amount,
                'keterangan' => $note ?: 'Update kas awal hari',
                'updated_at' => $now,
            ]);
        }

        return $this->db->table('cash_expense')->insert([
            'tanggal' => $now,
            'kategori' => 'kas_awal_hari',
            'nominal' => $amount,
            'keterangan' => $note ?: 'Kas awal hari ' . date('d/m/Y'),
            'penerima' => 'System',
            'user_id' => $userId,
            'created_at' => $now,
        ]);
    }

    /** @param list<string> $categories */
    private function sumExpenses(array $categories, string $date, string $at): float
    {
        $row = $this->db->table('cash_expense')->select('COALESCE(SUM(nominal), 0) AS total', false)
            ->whereIn('kategori', $categories)->where('tanggal >=', $date . ' 00:00:00')->where('tanggal <=', $at)
            ->get()->getRowArray();
        return (float) ($row['total'] ?? 0);
    }

    /** @param list<string> $excludedCategories */
    private function sumExpensesExcept(array $excludedCategories, string $date, string $at): float
    {
        $row = $this->db->table('cash_expense')->select('COALESCE(SUM(nominal), 0) AS total', false)
            ->whereNotIn('kategori', $excludedCategories)->where('tanggal >=', $date . ' 00:00:00')->where('tanggal <=', $at)
            ->get()->getRowArray();
        return (float) ($row['total'] ?? 0);
    }

    private function getCashSales(string $date, string $at): float
    {
        $row = $this->db->table('pembayaran p')->select('COALESCE(SUM(p.jumlah), 0) AS total', false)
            ->join('transaksi t', 't.id = p.transaksi_id')->where('p.metode', 'tunai')->where('p.status', 'aktif')->where('t.status !=', 'batal')
            ->where('p.tanggal >=', $date . ' 00:00:00')->where('p.tanggal <=', $at)->get()->getRowArray();
        return (float) ($row['total'] ?? 0);
    }
}

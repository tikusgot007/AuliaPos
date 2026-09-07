<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class RepairTotalDibayar extends BaseCommand
{
    protected $group = 'AULIA';
    protected $name = 'aulia:repair-total-dibayar';
    protected $description = 'Memeriksa dan memperbaiki cache transaksi.total_dibayar dari tabel pembayaran.';
    protected $usage = 'aulia:repair-total-dibayar [--fix]';
    protected $arguments = [];
    protected $options = [
        '--fix' => 'Perbaiki data yang tidak konsisten. Tanpa opsi ini hanya melakukan pemeriksaan.',
    ];

    public function run(array $params)
    {
        $db = Database::connect();
        $fix = array_key_exists('fix', $params) || CLI::getOption('fix') !== null;

        $rows = $db->table('transaksi t')
            ->select('t.id, t.total_dibayar AS cached_total, COALESCE(SUM(p.jumlah), 0) AS actual_total, t.grand_total')
            ->join('pembayaran p', 'p.transaksi_id = t.id', 'left')
            ->groupBy('t.id')
            ->having('(ABS(COALESCE(t.total_dibayar, 0) - COALESCE(SUM(p.jumlah), 0)) > 0.0001)', null, false)
            ->orderBy('t.id', 'ASC')
            ->get()
            ->getResultArray();

        $count = count($rows);

        if ($count === 0) {
            CLI::write('OK: tidak ditemukan total_dibayar yang tidak konsisten.', 'green');
            return EXIT_SUCCESS;
        }

        CLI::write("Ditemukan {$count} transaksi tidak konsisten:", 'yellow');

        foreach ($rows as $row) {
            CLI::write(sprintf(
                '#%d | cache=%s | aktual=%s | grand_total=%s',
                (int) $row['id'],
                number_format((float) $row['cached_total'], 2, '.', ''),
                number_format((float) $row['actual_total'], 2, '.', ''),
                number_format((float) $row['grand_total'], 2, '.', '')
            ));
        }

        if (!$fix) {
            CLI::write('Mode pemeriksaan saja. Gunakan --fix untuk memperbaiki.', 'yellow');
            return EXIT_SUCCESS;
        }

        $db->transStart();

        foreach ($rows as $row) {
            $actual = (float) $row['actual_total'];
            $grandTotal = (float) $row['grand_total'];
            $status = $actual >= $grandTotal
                ? 'lunas'
                : ($actual > 0 ? 'dp' : 'belum_bayar');

            $db->table('transaksi')
                ->where('id', (int) $row['id'])
                ->update([
                    'total_dibayar' => $actual,
                    'status_pembayaran' => $status,
                ]);
        }

        $db->transComplete();

        if (!$db->transStatus()) {
            CLI::error('Gagal memperbaiki data. Tidak ada perubahan yang boleh dianggap selesai.');
            return EXIT_ERROR;
        }

        CLI::write("Selesai: {$count} transaksi diperbaiki.", 'green');
        return EXIT_SUCCESS;
    }
}

<?php

namespace App\Commands;

use App\Models\TransaksiModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Auto-SELESAI transaksi LUNAS yang masih PROSES setelah N hari (default 7,
 * dihitung dari tanggal transaksi). Hanya menyentuh status=proses +
 * status_pembayaran=lunas + belum digabung; belum lunas/mangkrak/batal tidak
 * disentuh. Dirancang dijalankan harian lewat Scheduled Task.
 */
class AutoSelesai extends BaseCommand
{
    protected $group       = 'AULIA';
    protected $name        = 'aulia:auto-selesai';
    protected $description = 'Ubah transaksi LUNAS berstatus PROSES yang berumur >= N hari menjadi SELESAI.';
    protected $usage       = 'aulia:auto-selesai [--hari=7] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--hari'    => 'Umur minimal transaksi dalam hari (default 7).',
        '--dry-run' => 'Hanya tampilkan jumlah kandidat, tanpa mengubah data.',
    ];

    public function run(array $params)
    {
        $hari   = (int) ($params['hari'] ?? CLI::getOption('hari') ?? 7);
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;

        if ($hari < 1) {
            CLI::error('--hari harus >= 1.');
            return EXIT_ERROR;
        }

        $hasil = (new TransaksiModel())->autoSelesaikanLunasLama($hari, $dryRun);

        CLI::write("Kandidat (lunas, proses, >= {$hari} hari): {$hasil['kandidat']}");

        if ($dryRun) {
            CLI::write('Mode dry-run: tidak ada data diubah.', 'yellow');
            return EXIT_SUCCESS;
        }

        CLI::write("Diubah ke SELESAI: {$hasil['diubah']}", 'green');

        foreach ($hasil['dilewati'] as $id => $alasan) {
            CLI::write("Dilewati #{$id}: {$alasan}", 'yellow');
        }

        log_message('info', 'aulia:auto-selesai hari={hari} kandidat={k} diubah={d}', [
            'hari' => $hari, 'k' => $hasil['kandidat'], 'd' => $hasil['diubah'],
        ]);

        return EXIT_SUCCESS;
    }
}

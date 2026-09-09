<?php

namespace App\Models;

use CodeIgniter\Model;

class ProdukModel extends Model
{
    protected $table            = 'produk';
    protected $primaryKey       = 'id';
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'barcode',
        'nama',
        'kategori_id',
        'satuan',
        'harga_jual',
        'harga_beli',
        'is_active',
        'is_locked'
    ];

    /**
     * Field yang boleh diubah lewat mode edit spreadsheet (inline edit).
     * Satu-satunya sumber kebenaran: dipakai model updateInline() dan
     * Produk::updateInline().
     */
    public const INLINE_EDITABLE_FIELDS = [
        'barcode',
        'nama',
        'kategori_id',
        'satuan',
        'harga_jual',
        'harga_beli',
    ];

    // Fungsi untuk mengambil produk yang aktif saja (default untuk kasir)
    public function getProdukAktif()
    {
        return $this->where('is_active', 1)->orderBy('nama', 'ASC')->findAll();
    }
    // Ambil semua produk aktif sekali untuk halaman kasir.
    // Filter kategori dan pencarian dilakukan di browser agar pergantian filter instan.
    public function getProdukAktifWithPopularity()
    {
        return $this->select('produk.id, produk.barcode, produk.nama, produk.kategori_id, produk.satuan, produk.harga_jual, produk.harga_beli, produk.is_active, COUNT(detail_transaksi.id) as popularity')
            ->join('detail_transaksi', 'detail_transaksi.produk_id = produk.id', 'left')
            ->where('produk.is_active', 1)
            ->groupBy('produk.id')
            ->orderBy('popularity', 'DESC')
            ->orderBy('produk.nama', 'ASC')
            ->findAll();
    }

    /**
     * GET DATA UNTUK DATATABLES (SERVER-SIDE)
     * Dengan join ke tabel kategori
     */
    public function getDataTablesProduk($draw, $start, $length, $search, $order, $columns)
    {
        $db = \Config\Database::connect();
        $builder = $db->table('produk')
            ->select('produk.id, produk.barcode, produk.nama, produk.kategori_id, produk.satuan, produk.harga_jual, produk.harga_beli, produk.is_active, kategori.nama as kategori_nama')
            ->join('kategori', 'kategori.id = produk.kategori_id', 'left')
            ->where('produk.is_active', 1);

        // ==========================================
        // 1. TOTAL RECORDS (tanpa filter)
        // ==========================================
        $totalRecords = $builder->countAllResults(false);

        // ==========================================
        // 2. SEARCH (filter)
        // ==========================================
        if (!empty($search['value'])) {
            $keyword = $search['value'];
            $builder->groupStart()
                ->like('produk.nama', $keyword)
                ->orLike('produk.barcode', $keyword)
                ->orLike('kategori.nama', $keyword)
                ->orLike('produk.satuan', $keyword)
                ->groupEnd();
        }

        $totalFiltered = $builder->countAllResults(false);

        // ==========================================
        // 3. ORDER BY
        // ==========================================
        if (!empty($order)) {
            $colIdx = $order[0]['column'];
            $colName = $columns[$colIdx]['data'];
            $dir = $order[0]['dir'];
            $builder->orderBy($colName, $dir);
        } else {
            $builder->orderBy('produk.id', 'DESC');
        }

        // ==========================================
        // 4. LIMIT & OFFSET
        // ==========================================
        $data = $builder->limit($length, $start)->get()->getResultArray();

        return [
            'draw' => intval($draw),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ];
    }
    /**
     * UPDATE PRODUK DARI MODE EDIT SPREADSHEET
     *
     * Field yang boleh diedit: lihat self::INLINE_EDITABLE_FIELDS.
     */
    public function updateInline($id, $field, $value)
    {
        // Pastikan field memang boleh diedit
        if (!in_array($field, self::INLINE_EDITABLE_FIELDS, true)) {
            throw new \InvalidArgumentException(
                'Field produk tidak boleh diubah.'
            );
        }

        // Pastikan produk ada dan masih aktif
        $produk = $this->find($id);

        if (!$produk || (int) $produk['is_active'] !== 1) {
            throw new \RuntimeException(
                'Produk tidak ditemukan atau sudah tidak aktif.'
            );
        }

        // Barcode kosong disimpan sebagai NULL
        if ($field === 'barcode' && trim((string) $value) === '') {
            $value = null;
        }

        // Update hanya field yang sedang diedit
        return $this->update($id, [
            $field => $value
        ]);
    }

    // ================================================================
    // MAINTENANCE MASTER BARANG
    // ================================================================
    //
    // ID produk dengan ketergantungan khusus di source code:
    //   1 -> logic Banner
    //   2 -> logic Manual
    //   4 -> logic Custom/Cetak
    // Tidak boleh dihapus atau dipakai ulang untuk barang lain.
    public const ID_KHUSUS = [1, 2, 4];

    /**
     * Query audit: gabungan data produk + statistik pemakaiannya di
     * detail_transaksi. Dipakai untuk export CSV maintenance.
     *
     * jumlah_detail dihitung dari SEMUA baris detail_transaksi
     * (termasuk milik transaksi yang sudah batal) karena produk_id
     * di baris tersebut tetap ada relasinya; produk yang pernah
     * dipakai tidak boleh dihapus terlepas dari status transaksinya.
     */
    public function getAuditData(): array
    {
        $db = \Config\Database::connect();

        return $db->table('produk')
            ->select('
                produk.id,
                produk.nama,
                produk.kategori_id,
                kategori.nama AS kategori_nama,
                produk.satuan,
                produk.harga_jual,
                produk.harga_beli,
                produk.is_active,
                produk.is_locked,
                COUNT(detail_transaksi.id) AS jumlah_detail,
                COALESCE(SUM(detail_transaksi.jumlah), 0) AS total_qty,
                MIN(transaksi.tanggal) AS tanggal_pertama,
                MAX(transaksi.tanggal) AS tanggal_terakhir
            ')
            ->join('kategori', 'kategori.id = produk.kategori_id', 'left')
            ->join('detail_transaksi', 'detail_transaksi.produk_id = produk.id', 'left')
            ->join('transaksi', 'transaksi.id = detail_transaksi.transaksi_id', 'left')
            ->groupBy('produk.id')
            ->orderBy('produk.id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Backup seluruh isi tabel produk ke file .sql (statement INSERT),
     * dipanggil otomatis sebelum eksekusi import maintenance.
     *
     * Sengaja tidak bergantung pada binary `mysqldump` supaya portable
     * di environment mana pun (termasuk XAMPP Windows tanpa mysqldump
     * di PATH) — cukup pakai koneksi database yang sudah ada.
     *
     * @return string Path lengkap file backup yang dibuat.
     */
    public function backupTable(): string
    {
        $rows = $this->asArray()->orderBy('id', 'ASC')->findAll();

        $dir = WRITEPATH . 'backups';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Gagal membuat folder backup: ' . $dir);
        }

        $filename = 'produk_backup_' . date('Ymd_His') . '.sql';
        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

        $db = \Config\Database::connect();

        $lines = [
            '-- Backup otomatis tabel produk (Maintenance Master Barang)',
            '-- Dibuat: ' . date('Y-m-d H:i:s'),
            '-- Jumlah baris: ' . count($rows),
            '',
            'SET FOREIGN_KEY_CHECKS=0;',
            '',
        ];

        foreach ($rows as $row) {
            $columns = array_keys($row);
            $values = array_map(function ($value) use ($db) {
                return $value === null ? 'NULL' : $db->escape($value);
            }, array_values($row));

            $lines[] = 'INSERT INTO `produk` (`' . implode('`, `', $columns) . '`) VALUES ('
                . implode(', ', $values) . ');';
        }

        $lines[] = '';
        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException('Gagal menulis file backup produk.');
        }

        return $path;
    }

    /**
     * Cek apakah sebuah produk boleh dihapus, divalidasi ULANG terhadap
     * kondisi database saat ini — bukan percaya nilai dari CSV yang
     * mungkin sudah tidak sinkron dengan kondisi terbaru.
     *
     * Syarat aman untuk DELETE:
     *   - bukan ID khusus (1, 2, 4)
     *   - is_locked = 0
     *   - tidak pernah dipakai di detail_transaksi (jumlah_detail = 0)
     *
     * @return array{0: bool, 1: ?string} [boleh, alasan-jika-tidak]
     */
    public function bolehDihapus(int $id): array
    {
        if (in_array($id, self::ID_KHUSUS, true)) {
            return [false, 'ID ' . $id . ' adalah ID khusus (Banner/Manual/Custom), tidak boleh dihapus.'];
        }

        $produk = $this->find($id);

        if (!$produk) {
            return [false, 'Produk tidak ditemukan (mungkin sudah dihapus baris lain).'];
        }

        if ((int) $produk['is_locked'] === 1) {
            return [false, 'Produk terkunci (is_locked = 1), tidak boleh dihapus.'];
        }

        $db = \Config\Database::connect();
        $jumlahDetail = $db->table('detail_transaksi')
            ->where('produk_id', $id)
            ->countAllResults();

        if ($jumlahDetail > 0) {
            return [false, 'Produk pernah dipakai di ' . $jumlahDetail . ' baris transaksi, tidak boleh dihapus.'];
        }

        return [true, null];
    }
}

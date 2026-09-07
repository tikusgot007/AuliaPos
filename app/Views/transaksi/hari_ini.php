<?php

/**
 * ============================================================
 * VIEW: TRANSAKSI / ITEM TERJUAL HARI INI
 * ============================================================
 *
 * Data yang diharapkan dari Controller:
 *
 * $transaksi = [
 *     [
 *         'detail_id'        => 1,
 *         'transaksi_id'     => 100,
 *         'produk_id'        => 10,
 *         'nama_produk'      => 'Produk A',
 *         'jumlah'           => 2,
 *         'harga_satuan'     => 5000,
 *         'subtotal'         => 10000,
 *
 *         'tanggal'          => '2026-08-30 10:15:23',
 *         'kode_invoice'     => 'INV-20260830-001',
 *         'no_order'         => 'ORD-001',
 *         'pelanggan_id'     => 5,
 *         'pelanggan_nama'   => 'Budi',
 *         'kasir_id'         => 2,
 *         'kasir_nama'       => 'Asep',
 *         'grand_total'      => 30000,
 *         'total_dibayar'    => 30000,
 *         'status'           => 'selesai',
 *     ],
 *     ...
 * ];
 */


/*
|--------------------------------------------------------------------------
| GROUP DATA BERDASARKAN TRANSAKSI
|--------------------------------------------------------------------------
|
| Satu transaksi dapat memiliki banyak item.
| Kita kelompokkan berdasarkan transaksi_id.
|
*/

$groupedTransaksi = [];

if (!empty($transaksi)) {

    foreach ($transaksi as $row) {

        $transaksiId =
            $row['transaksi_id']
            ?? $row['id_transaksi']
            ?? null;

        if (!$transaksiId) {
            continue;
        }

        $groupedTransaksi[$transaksiId][] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$totalTransaksi = count($groupedTransaksi);

$totalItem = 0;
$totalPenjualan = 0;

foreach ($groupedTransaksi as $items) {

    $firstItem = $items[0];

    $isBatal =
        strtolower(
            trim(
                $firstItem['status'] ?? ''
            )
        ) === 'batal';

    foreach ($items as $item) {

        $jumlah =
            (float) (
                $item['jumlah']
                ?? $item['qty']
                ?? 0
            );

        $subtotal =
            (float) (
                $item['subtotal']
                ?? 0
            );

        if (!$isBatal) {

            $totalItem +=
                $jumlah;

            $totalPenjualan +=
                $subtotal;
        }
    }
}


/*
|--------------------------------------------------------------------------
| TANGGAL LABEL
|--------------------------------------------------------------------------
*/

$namaHari = [
    'Sunday'    => 'Minggu',
    'Monday'    => 'Senin',
    'Tuesday'   => 'Selasa',
    'Wednesday' => 'Rabu',
    'Thursday'  => 'Kamis',
    'Friday'    => 'Jumat',
    'Saturday'  => 'Sabtu',
];

$namaBulan = [
    1  => 'Januari',
    2  => 'Februari',
    3  => 'Maret',
    4  => 'April',
    5  => 'Mei',
    6  => 'Juni',
    7  => 'Juli',
    8  => 'Agustus',
    9  => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember',
];

$hari =
    $namaHari[date('l')]
    ?? date('l');

$bulan =
    $namaBulan[(int) date('n')]
    ?? date('F');

$tanggalLabel =
    $hari
    . ', '
    . date('d')
    . ' '
    . $bulan
    . ' '
    . date('Y');

?>

<style>
    /* ==========================================================
       SUMMARY CARD
       ========================================================== */

    .summary-card {
        height: 100%;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 10px;
    }

    .summary-label {
        font-size: 13px;
        color: #6c757d;
        margin-bottom: 4px;
    }

    .summary-value {
        font-size: 22px;
        font-weight: 700;
        line-height: 1.2;
    }


    /* ==========================================================
       TABLE
       ========================================================== */

    .table-item-terjual {
        margin-bottom: 0;
        border-collapse: separate;
        border-spacing: 0;
    }

    .table-item-terjual thead th {
        background: #f8f9fa;
        white-space: nowrap;
        vertical-align: middle;
        font-size: 13px;
        font-weight: 600;
    }

    .table-item-terjual tbody td {
        vertical-align: middle;
        font-size: 14px;
    }


    /* ==========================================================
   GROUP TRANSAKSI
   ========================================================== */

    /*
 * Awal transaksi
 */
    .table-item-terjual tbody tr.transaction-start td {
        border-top: 2px solid #d0d7de;
    }


    /*
 * Item lanjutan
 */
    .table-item-terjual tbody tr.transaction-detail td {
        border-top: 0;
        border-bottom: 0;
    }


    /*
 * Akhir transaksi
 */
    .table-item-terjual tbody tr.transaction-end td {
        border-bottom: 2px solid #d0d7de;
    }


    /* ==========================================================
   WARNA BLOK TRANSAKSI
   ========================================================== */

    .table-item-terjual tbody tr.transaction-group-odd td {
        background-color: #ffffff;
    }

    .table-item-terjual tbody tr.transaction-group-even td {
        background-color: #f4f7fb;
    }


    /*
 * Kolom transaksi
 */
    .table-item-terjual tbody tr.transaction-group-odd td.transaction-cell {
        background-color: #f8fbff;
    }

    .table-item-terjual tbody tr.transaction-group-even td.transaction-cell {
        background-color: #edf3f9;
    }

    /* ==========================================================
       TRANSACTION CONTENT
       ========================================================== */

    .transaction-time {
        font-weight: 600;
        white-space: nowrap;
    }

    .invoice-link {
        font-weight: 600;
        text-decoration: none;
    }

    .invoice-link:hover {
        text-decoration: underline;
    }

    .pelanggan-cell {
        min-width: 150px;
    }

    .pelanggan-name {
        font-weight: 500;
    }

    .produk-cell {
        min-width: 220px;
    }


    /* ==========================================================
       HOVER
       ========================================================== */

    .table-item-terjual tbody tr.transaction-group-odd:hover td,
    .table-item-terjual tbody tr.transaction-group-even:hover td {
        background-color: #eef5ff;
    }


    /*
     * Pastikan rowspan pada kolom transaksi ikut berubah
     * saat hover.
     */
    .table-item-terjual tbody tr.transaction-group-odd:hover td.transaction-cell,
    .table-item-terjual tbody tr.transaction-group-even:hover td.transaction-cell {
        background-color: #eef5ff;
    }


    /* ==========================================================
       TRANSAKSI BATAL
       ========================================================== */

    .table-item-terjual tbody tr.transaction-batal td {
        color: #6c757d;
    }

    .table-item-terjual tbody tr.transaction-batal .produk-cell {
        text-decoration: line-through;
    }


    /*
     * Transaksi batal tetap mendapat warna kelompok,
     * tetapi teksnya tetap abu-abu.
     */
    .table-item-terjual tbody tr.transaction-batal.transaction-group-odd td {
        background-color: #ffffff;
    }

    .table-item-terjual tbody tr.transaction-batal.transaction-group-even td {
        background-color: #f7f9fc;
    }

    .table-item-terjual tbody tr.transaction-batal.transaction-group-odd td.transaction-cell {
        background-color: #f8fbff;
    }

    .table-item-terjual tbody tr.transaction-batal.transaction-group-even td.transaction-cell {
        background-color: #eef3f9;
    }


    /* ==========================================================
       PAGINATION
       ========================================================== */

    .pagination-wrapper {
        padding-top: 15px;
        margin-top: 0;
        border-top: 1px solid #dee2e6;
    }

    .pagination-info {
        font-size: 13px;
        color: #6c757d;
    }


    /* ==========================================================
       MOBILE
       ========================================================== */

    @media (max-width: 768px) {

        .summary-value {
            font-size: 18px;
        }

        .table-item-terjual {
            min-width: 1100px;
        }
    }
</style>


<!-- ============================================================
     CARD
     ============================================================ -->

<div class="card">

    <!-- ========================================================
         HEADER
         ======================================================== -->

    <div class="card-header bg-primary text-white">

        <div
            class="d-flex flex-wrap justify-content-between align-items-center gap-2">

            <div>

                <h5 class="mb-1">

                    <i class="fas fa-shopping-cart"></i>

                    Item Terjual Hari Ini

                </h5>

                <div class="small opacity-75">

                    <?= esc($tanggalLabel) ?>

                </div>

            </div>


            <div class="d-flex gap-2">

                <button
                    type="button"
                    class="btn btn-light btn-sm"
                    onclick="window.location.reload();">

                    <i class="fas fa-sync-alt"></i>

                    Refresh

                </button>


                <a
                    href="<?= base_url('/transaksi') ?>"
                    class="btn btn-outline-light btn-sm">

                    <i class="fas fa-list"></i>

                    Daftar Transaksi

                </a>

            </div>

        </div>

    </div>


    <!-- ========================================================
         BODY
         ======================================================== -->

    <div class="card-body">


        <!-- ====================================================
             SUMMARY
             ==================================================== -->

        <div class="row g-3 mb-4">

            <!-- TOTAL TRANSAKSI -->
            <div class="col-md-4">

                <div class="summary-card p-3">

                    <div class="summary-label">

                        Total Transaksi

                    </div>

                    <div class="summary-value">

                        <?= number_format(
                            $totalTransaksi,
                            0,
                            ',',
                            '.'
                        ) ?>

                    </div>

                    <div class="small text-muted mt-1">

                        transaksi hari ini

                    </div>

                </div>

            </div>


            <!-- TOTAL ITEM -->
            <div class="col-md-4">

                <div class="summary-card p-3">

                    <div class="summary-label">

                        Total Item Terjual

                    </div>

                    <div class="summary-value">

                        <?= number_format(
                            $totalItem,
                            0,
                            ',',
                            '.'
                        ) ?>

                    </div>

                    <div class="small text-muted mt-1">

                        jumlah item terjual

                    </div>

                </div>

            </div>


            <!-- TOTAL PENJUALAN -->
            <div class="col-md-4">

                <div class="summary-card p-3">

                    <div class="summary-label">

                        Total Penjualan

                    </div>

                    <div class="summary-value text-success">

                        Rp

                        <?= number_format(
                            $totalPenjualan,
                            0,
                            ',',
                            '.'
                        ) ?>

                    </div>

                    <div class="small text-muted mt-1">

                        tidak termasuk transaksi batal

                    </div>

                </div>

            </div>

        </div>


        <!-- ====================================================
             SEARCH
             ==================================================== -->

        <div class="row mb-3">

            <div class="col-md-6">

                <label
                    for="searchItem"
                    class="form-label">

                    Cari transaksi / item

                </label>

                <div class="input-group">

                    <span class="input-group-text">

                        <i class="fas fa-search"></i>

                    </span>

                    <input
                        type="text"
                        id="searchItem"
                        class="form-control"
                        autocomplete="off"
                        placeholder="Invoice, pelanggan, kasir, atau produk...">

                </div>

            </div>

        </div>


        <!-- ====================================================
             TABLE
             ==================================================== -->

        <div class="table-responsive">

            <table
                id="tableItemTerjual"
                class="table table-bordered table-hover table-item-terjual">

                <thead>

                    <tr>

                        <th width="90">
                            Waktu
                        </th>

                        <th width="170">
                            No. Transaksi
                        </th>

                        <th width="150">
                            Pelanggan
                        </th>

                        <th width="120">
                            Kasir
                        </th>

                        <th class="produk-cell">
                            Produk
                        </th>

                        <th width="70" class="text-center">
                            Qty
                        </th>

                        <th width="120" class="text-end">
                            Harga
                        </th>

                        <th width="135" class="text-end">
                            Subtotal
                        </th>

                    </tr>

                </thead>


                <tbody id="tableItemTerjualBody">

                    <?php if (!empty($groupedTransaksi)): ?>

                        <?php
                        /*
                        |--------------------------------------------------------------------------
                        | NOMOR GROUP TRANSAKSI
                        |--------------------------------------------------------------------------
                        |
                        | Digunakan untuk memberi warna bergantian
                        | antar transaksi.
                        |
                        */
                        $transactionGroupIndex = 0;
                        ?>


                        <?php foreach (
                            $groupedTransaksi
                            as $transaksiId => $items
                        ): ?>

                            <?php

                            /*
                            |--------------------------------------------------------------------------
                            | NOMOR GROUP
                            |--------------------------------------------------------------------------
                            */

                            $transactionGroupIndex++;

                            $isEvenGroup =
                                ($transactionGroupIndex % 2 === 0);


                            /*
                            |--------------------------------------------------------------------------
                            | DATA TRANSAKSI
                            |--------------------------------------------------------------------------
                            */

                            $firstItem =
                                $items[0];

                            $jumlahBaris =
                                count($items);


                            $tanggal =
                                $firstItem['tanggal']
                                ?? null;


                            $kodeInvoice =
                                $firstItem['kode_invoice']
                                ?? '-';


                            $pelangganNama =
                                trim(
                                    $firstItem['pelanggan_nama']
                                        ?? ''
                                );


                            if (
                                $pelangganNama === ''
                            ) {

                                $pelangganNama =
                                    'Umum';
                            }


                            $kasirNama =
                                trim(
                                    $firstItem['kasir_nama']
                                        ?? ''
                                );


                            if (
                                $kasirNama === ''
                            ) {

                                $kasirNama =
                                    '-';
                            }


                            $status =
                                strtolower(
                                    trim(
                                        $firstItem['status']
                                            ?? ''
                                    )
                                );


                            $isBatal =
                                ($status === 'batal');


                            /*
                            |--------------------------------------------------------------------------
                            | SEARCH INDEX PER TRANSAKSI
                            |--------------------------------------------------------------------------
                            |
                            | Pencarian dilakukan per GROUP,
                            | bukan per item.
                            |
                            */

                            $searchParts = [

                                $kodeInvoice,

                                $pelangganNama,

                                $kasirNama,

                                $firstItem['no_order']
                                    ?? '',

                            ];


                            foreach (
                                $items
                                as $searchItem
                            ) {

                                $searchParts[] =
                                    $searchItem['nama_produk']
                                    ?? '';
                            }


                            $searchText =
                                strtolower(
                                    trim(
                                        implode(
                                            ' ',
                                            $searchParts
                                        )
                                    )
                                );

                            ?>


                            <?php foreach (
                                $items
                                as $index => $item
                            ): ?>

                                <?php

                                /*
                                |--------------------------------------------------------------------------
                                | DATA ITEM
                                |--------------------------------------------------------------------------
                                */

                                $namaProduk =
                                    $item['nama_produk']
                                    ?? $item['produk_nama']
                                    ?? '-';


                                $jumlah =
                                    (float) (
                                        $item['jumlah']
                                        ?? $item['qty']
                                        ?? 0
                                    );


                                $harga =
                                    (float) (
                                        $item['harga_satuan']
                                        ?? $item['harga']
                                        ?? 0
                                    );


                                $subtotal =
                                    (float) (
                                        $item['subtotal']
                                        ?? (
                                            $jumlah *
                                            $harga
                                        )
                                    );


                                $isFirst =
                                    ($index === 0);


                                /*
                                |--------------------------------------------------------------------------
                                | CSS CLASS
                                |--------------------------------------------------------------------------
                                */

                                $rowClasses = [];


                                /*
                                | Awal / lanjutan transaksi
                                */
                                if ($isFirst) {

                                    $rowClasses[] =
                                        'transaction-start';
                                } else {

                                    $rowClasses[] =
                                        'transaction-detail';
                                }


                                /*
                                |--------------------------------------------------------------------------
                                | WARNA BLOK TRANSAKSI
                                |--------------------------------------------------------------------------
                                */

                                if ($isEvenGroup) {

                                    $rowClasses[] =
                                        'transaction-group-even';
                                } else {

                                    $rowClasses[] =
                                        'transaction-group-odd';
                                }


                                /*
                                |--------------------------------------------------------------------------
                                | STATUS BATAL
                                |--------------------------------------------------------------------------
                                */

                                if ($isBatal) {

                                    $rowClasses[] =
                                        'transaction-batal';
                                }

                                ?>


                                <tr
                                    class="<?= esc(
                                                implode(
                                                    ' ',
                                                    $rowClasses
                                                )
                                            ) ?>"
                                    data-search="<?= esc(
                                                        $searchText
                                                    ) ?>">


                                    <?php if ($isFirst): ?>


                                        <!-- =========================================
                                             WAKTU
                                             ========================================= -->

                                        <td
                                            rowspan="<?= $jumlahBaris ?>"
                                            class="transaction-cell">

                                            <?php if ($tanggal): ?>

                                                <div class="transaction-time">

                                                    <?= esc(
                                                        date(
                                                            'H:i:s',
                                                            strtotime(
                                                                $tanggal
                                                            )
                                                        )
                                                    ) ?>

                                                </div>

                                            <?php else: ?>

                                                -

                                            <?php endif; ?>

                                        </td>


                                        <!-- =========================================
                                             NO TRANSAKSI
                                             ========================================= -->

                                        <td
                                            rowspan="<?= $jumlahBaris ?>"
                                            class="transaction-cell">

                                            <a
                                                href="<?= base_url(
                                                            '/transaksi/detail/'
                                                                . $transaksiId
                                                        ) ?>"
                                                class="invoice-link">

                                                <?= esc(
                                                    $kodeInvoice
                                                ) ?>

                                            </a>


                                            <?php if ($isBatal): ?>

                                                <div class="mt-1">

                                                    <span
                                                        class="badge bg-secondary">

                                                        BATAL

                                                    </span>

                                                </div>

                                            <?php endif; ?>


                                        </td>


                                        <!-- =========================================
                                             PELANGGAN
                                             ========================================= -->

                                        <td
                                            rowspan="<?= $jumlahBaris ?>"
                                            class="transaction-cell pelanggan-cell">

                                            <div class="pelanggan-name">

                                                <?= esc(
                                                    $pelangganNama
                                                ) ?>

                                            </div>

                                        </td>


                                        <!-- =========================================
                                             KASIR
                                             ========================================= -->

                                        <td
                                            rowspan="<?= $jumlahBaris ?>"
                                            class="transaction-cell">

                                            <?= esc(
                                                $kasirNama
                                            ) ?>

                                        </td>


                                    <?php endif; ?>


                                    <!-- =============================================
                                         PRODUK
                                         ============================================= -->

                                    <td class="produk-cell">

                                        <?= esc(
                                            $namaProduk
                                        ) ?>

                                    </td>


                                    <!-- =============================================
                                         QTY
                                         ============================================= -->

                                    <td class="text-center">

                                        <?=
                                        rtrim(
                                            rtrim(
                                                number_format(
                                                    $jumlah,
                                                    2,
                                                    ',',
                                                    '.'
                                                ),
                                                '0'
                                            ),
                                            ','
                                        )
                                        ?>

                                    </td>


                                    <!-- =============================================
                                         HARGA
                                         ============================================= -->

                                    <td class="text-end">

                                        Rp

                                        <?= number_format(
                                            $harga,
                                            0,
                                            ',',
                                            '.'
                                        ) ?>

                                    </td>


                                    <!-- =============================================
                                         SUBTOTAL
                                         ============================================= -->

                                    <td class="text-end">

                                        Rp

                                        <?= number_format(
                                            $subtotal,
                                            0,
                                            ',',
                                            '.'
                                        ) ?>

                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        <?php endforeach; ?>


                    <?php else: ?>


                        <tr>

                            <td
                                colspan="8"
                                class="text-center py-5 text-muted">

                                <i
                                    class="fas fa-shopping-basket fa-2x mb-3 d-block">
                                </i>

                                Belum ada transaksi hari ini.

                            </td>

                        </tr>


                    <?php endif; ?>


                </tbody>

            </table>

        </div>


        <!-- ====================================================
             PAGINATION
             ==================================================== -->

        <div
            class="pagination-wrapper
                   d-flex
                   flex-wrap
                   justify-content-between
                   align-items-center
                   gap-2">

            <div
                id="paginationInfo"
                class="pagination-info">

                Menampilkan data...

            </div>


            <nav aria-label="Pagination">

                <ul
                    id="pagination"
                    class="pagination pagination-sm mb-0">

                </ul>

            </nav>

        </div>


    </div>

</div>


<?= $this->section('scripts') ?>


<script>
    $(document).ready(function() {

        /*
        |--------------------------------------------------------------------------
        | ELEMENT
        |--------------------------------------------------------------------------
        */

        const $table =
            $('#tableItemTerjual');

        const $tbody =
            $('#tableItemTerjualBody');


        /*
        |--------------------------------------------------------------------------
        | SIMPAN ROW ASLI
        |--------------------------------------------------------------------------
        |
        | Kita mengambil row dari HTML yang sudah dibuat oleh PHP.
        |
        */

        const originalRows =
            $tbody
            .find('tr')
            .toArray();


        /*
        |--------------------------------------------------------------------------
        | BENTUK GROUP TRANSAKSI
        |--------------------------------------------------------------------------
        |
        | transaction-start = transaksi baru.
        |
        | Semua row setelahnya sampai transaction-start berikutnya
        | menjadi bagian dari transaksi tersebut.
        |
        */

        let groups = [];

        let currentGroup = null;


        originalRows.forEach(function(row) {

            /*
             * Row kosong / "tidak ada transaksi"
             */
            if (
                !$(row).hasClass(
                    'transaction-start'
                )
            ) {

                if (currentGroup) {

                    currentGroup.rows.push(row);

                }

                return;
            }


            /*
             * Simpan group sebelumnya
             */
            if (currentGroup) {

                groups.push(
                    currentGroup
                );

            }


            /*
             * Buat group baru
             */
            currentGroup = {

                rows: [row],

                searchText: (
                        $(row).attr(
                            'data-search'
                        ) || ''
                    )
                    .toString()
                    .toLowerCase()

            };

        });


        /*
        |--------------------------------------------------------------------------
        | GROUP TERAKHIR
        |--------------------------------------------------------------------------
        */

        if (currentGroup) {

            groups.push(
                currentGroup
            );

        }


        /*
        |--------------------------------------------------------------------------
        | PAGINATION SETTING
        |--------------------------------------------------------------------------
        */

        const groupsPerPage = 10;

        let currentPage = 1;

        let filteredGroups = [...groups];


        /*
        |--------------------------------------------------------------------------
        | RENDER PAGE
        |--------------------------------------------------------------------------
        */

        function renderPage() {

            /*
             * Bersihkan tbody
             */
            $tbody.empty();


            /*
             * Tidak ada hasil
             */
            if (
                filteredGroups.length === 0
            ) {

                $tbody.append(`

                    <tr>

                        <td
                            colspan="8"
                            class="text-center py-5 text-muted">

                            <i
                                class="fas fa-search fa-2x mb-3 d-block">
                            </i>

                            Data tidak ditemukan.

                        </td>

                    </tr>

                `);


                renderPagination();

                return;
            }


            /*
             * Index group
             */
            const startIndex =
                (
                    currentPage - 1
                ) *
                groupsPerPage;


            const endIndex =
                startIndex +
                groupsPerPage;


            const pageGroups =
                filteredGroups.slice(
                    startIndex,
                    endIndex
                );


            /*
             * Render tiap group
             */
            pageGroups.forEach(
                function(group) {

                    group.rows.forEach(
                        function(row) {

                            $tbody.append(
                                row
                            );

                        }
                    );

                }
            );


            renderPagination();

        }


        /*
        |--------------------------------------------------------------------------
        | RENDER PAGINATION
        |--------------------------------------------------------------------------
        */

        function renderPagination() {

            const totalGroups =
                filteredGroups.length;


            const totalPages =
                Math.ceil(
                    totalGroups /
                    groupsPerPage
                );


            /*
             * INFO
             */
            if (
                totalGroups === 0
            ) {

                $('#paginationInfo').text(
                    'Tidak ada transaksi'
                );

            } else {

                const startGroup =
                    (
                        currentPage - 1
                    ) *
                    groupsPerPage +
                    1;


                const endGroup =
                    Math.min(
                        currentPage *
                        groupsPerPage,
                        totalGroups
                    );


                $('#paginationInfo').text(

                    'Menampilkan transaksi ' +
                    startGroup +
                    ' - ' +
                    endGroup +
                    ' dari ' +
                    totalGroups +
                    ' transaksi'

                );

            }


            /*
             * Bersihkan pagination
             */
            const $pagination =
                $('#pagination');

            $pagination.empty();


            /*
             * Tidak perlu pagination
             */
            if (
                totalPages <= 1
            ) {

                return;

            }


            /*
             * PREVIOUS
             */

            const prevDisabled =
                currentPage === 1 ?
                'disabled' :
                '';


            $pagination.append(`

                <li class="page-item ${prevDisabled}">

                    <a
                        href="#"
                        class="page-link"
                        data-page="${currentPage - 1}">

                        &laquo;

                    </a>

                </li>

            `);


            /*
             * Range halaman
             */

            let startPage =
                Math.max(
                    1,
                    currentPage - 2
                );


            let endPage =
                Math.min(
                    totalPages,
                    currentPage + 2
                );


            /*
             * Halaman pertama
             */

            if (
                startPage > 1
            ) {

                $pagination.append(`

                    <li class="page-item">

                        <a
                            href="#"
                            class="page-link"
                            data-page="1">

                            1

                        </a>

                    </li>

                `);


                if (
                    startPage > 2
                ) {

                    $pagination.append(`

                        <li
                            class="page-item disabled">

                            <span class="page-link">

                                ...

                            </span>

                        </li>

                    `);

                }

            }


            /*
             * Nomor halaman
             */

            for (
                let page = startPage; page <= endPage; page++
            ) {

                const active =
                    page === currentPage ?
                    'active' :
                    '';


                $pagination.append(`

                    <li class="page-item ${active}">

                        <a
                            href="#"
                            class="page-link"
                            data-page="${page}">

                            ${page}

                        </a>

                    </li>

                `);

            }


            /*
             * Halaman terakhir
             */

            if (
                endPage < totalPages
            ) {

                if (
                    endPage <
                    totalPages - 1
                ) {

                    $pagination.append(`

                        <li
                            class="page-item disabled">

                            <span class="page-link">

                                ...

                            </span>

                        </li>

                    `);

                }


                $pagination.append(`

                    <li class="page-item">

                        <a
                            href="#"
                            class="page-link"
                            data-page="${totalPages}">

                            ${totalPages}

                        </a>

                    </li>

                `);

            }


            /*
             * NEXT
             */

            const nextDisabled =
                currentPage === totalPages ?
                'disabled' :
                '';


            $pagination.append(`

                <li
                    class="page-item ${nextDisabled}">

                    <a
                        href="#"
                        class="page-link"
                        data-page="${currentPage + 1}">

                        &raquo;

                    </a>

                </li>

            `);

        }


        /*
        |--------------------------------------------------------------------------
        | CLICK PAGINATION
        |--------------------------------------------------------------------------
        */

        $(document).on(
            'click',
            '#pagination .page-link',
            function(e) {

                e.preventDefault();


                const $item =
                    $(this).closest(
                        '.page-item'
                    );


                /*
                 * Disabled
                 */
                if (
                    $item.hasClass(
                        'disabled'
                    )
                ) {

                    return;

                }


                const page =
                    parseInt(
                        $(this).data(
                            'page'
                        ),
                        10
                    );


                if (
                    isNaN(page) ||
                    page < 1
                ) {

                    return;

                }


                currentPage =
                    page;


                renderPage();


                /*
                 * Scroll ke tabel
                 */
                if (
                    $table.length
                ) {

                    $('html, body').animate({

                            scrollTop: $table.offset().top -
                                100

                        },
                        200
                    );

                }

            }
        );


        /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        |
        | Search dilakukan terhadap GROUP.
        | Jadi kalau satu produk cocok dengan pencarian,
        | seluruh transaksi ikut ditampilkan.
        |
        */

        $('#searchItem').on(
            'input',
            function() {

                const keyword =
                    $.trim(
                        $(this).val()
                    ).toLowerCase();


                /*
                 * Kosongkan filter
                 */
                if (!keyword) {

                    filteredGroups = [...groups];

                } else {

                    filteredGroups =
                        groups.filter(
                            function(group) {

                                return group
                                    .searchText
                                    .indexOf(
                                        keyword
                                    ) !== -1;

                            }
                        );

                }


                /*
                 * Kembali ke halaman pertama
                 */
                currentPage =
                    1;


                renderPage();

            }
        );


        /*
        |--------------------------------------------------------------------------
        | INITIAL RENDER
        |--------------------------------------------------------------------------
        */

        renderPage();

    });
</script>


<?= $this->endSection() ?>
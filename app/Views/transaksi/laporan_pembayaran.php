<div class="container-fluid py-4">

    <!-- =========================================================
         HEADER
         ========================================================= -->

    <div class="card mb-3">

        <div class="card-header">

            <h5 class="mb-1">
                <i class="fas fa-money-check-alt"></i>
                Laporan Pembayaran
            </h5>

            <small class="text-muted">
                Daftar pembayaran yang benar-benar diterima
            </small>

        </div>

        <div class="card-body">

            <form
                method="get"
                class="row g-3 align-items-end">

                <!-- DATE RANGE -->

                <div class="col-md-5">

                    <label
                        for="filterPembayaran"
                        class="form-label fw-semibold">

                        Rentang Tanggal Pembayaran

                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="fas fa-calendar-alt"></i>
                        </span>

                        <input
                            type="text"
                            id="filterPembayaran"
                            class="form-control"
                            placeholder="Pilih rentang tanggal"
                            readonly
                            autocomplete="off">

                    </div>

                    <input
                        type="hidden"
                        name="tanggal_mulai"
                        id="tanggal_mulai"
                        value="<?= esc($tanggal_mulai) ?>">

                    <input
                        type="hidden"
                        name="tanggal_sampai"
                        id="tanggal_sampai"
                        value="<?= esc($tanggal_sampai) ?>">

                </div>


                <!-- METODE -->

                <div class="col-md-2">

                    <label
                        for="metode"
                        class="form-label fw-semibold">

                        Metode

                    </label>

                    <select
                        id="metode"
                        name="metode"
                        class="form-select">

                        <option value="">
                            Semua
                        </option>

                        <option
                            value="tunai"
                            <?= $metode === 'tunai'
                                ? 'selected'
                                : '' ?>>

                            Tunai

                        </option>

                        <option
                            value="qris"
                            <?= $metode === 'qris'
                                ? 'selected'
                                : '' ?>>

                            QRIS

                        </option>

                        <option
                            value="transfer"
                            <?= $metode === 'transfer'
                                ? 'selected'
                                : '' ?>>

                            Transfer

                        </option>

                    </select>

                </div>


                <!-- PENCARIAN -->

                <div class="col-md-3">

                    <label
                        for="keyword"
                        class="form-label fw-semibold">

                        Cari

                    </label>

                    <input
                        type="text"
                        id="keyword"
                        name="keyword"
                        value="<?= esc($keyword) ?>"
                        class="form-control"
                        placeholder="Invoice / pelanggan / kasir / keterangan">

                </div>


                <!-- BUTTON -->

                <div class="col-md-2">

                    <button
                        type="submit"
                        class="btn btn-primary w-100">

                        <i class="fas fa-search"></i>
                        Tampilkan

                    </button>

                </div>


                <div class="col-12">

                    <a
                        href="<?= current_url() ?>"
                        class="btn btn-outline-secondary btn-sm">

                        <i class="fas fa-undo"></i>
                        Reset

                    </a>

                </div>

            </form>

        </div>

    </div>


    <!-- =========================================================
         RINGKASAN
         ========================================================= -->

    <div class="row g-2 mb-3">

        <div class="col-md-3">

            <div class="card border-success h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Total Pembayaran
                    </div>

                    <div
                        class="fs-4 fw-bold text-success">

                        Rp
                        <?= number_format(
                            $totalPembayaran,
                            0,
                            ',',
                            '.'
                        ) ?>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card border-secondary h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Tunai
                    </div>

                    <div
                        class="fs-5 fw-bold">

                        Rp
                        <?= number_format(
                            $totalTunai,
                            0,
                            ',',
                            '.'
                        ) ?>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card border-info h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        QRIS
                    </div>

                    <div
                        class="fs-5 fw-bold text-info">

                        Rp
                        <?= number_format(
                            $totalQris,
                            0,
                            ',',
                            '.'
                        ) ?>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card border-primary h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Transfer
                    </div>

                    <div
                        class="fs-5 fw-bold text-primary">

                        Rp
                        <?= number_format(
                            $totalTransfer,
                            0,
                            ',',
                            '.'
                        ) ?>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         TABEL
         ========================================================= -->

    <div class="card">

        <div class="card-header">

            <div
                class="d-flex
                       justify-content-between
                       align-items-center">

                <div>

                    <strong>
                        Daftar Pembayaran
                    </strong>

                    <div class="small text-muted">

                        <?= esc($tanggal_mulai) ?>
                        s/d
                        <?= esc($tanggal_sampai) ?>

                    </div>

                </div>

                <span class="badge bg-primary">

                    <?= number_format(
                        $jumlahBaris,
                        0,
                        ',',
                        '.'
                    ) ?>

                    pembayaran

                </span>

            </div>

        </div>


        <div class="card-body p-0">

            <div class="table-responsive">

                <table
                    id="tableLaporanPembayaran"
                    class="table
                           table-bordered
                           table-hover
                           table-sm
                           mb-0">

                    <thead class="table-light">

                        <tr>

                            <th>
                                Tanggal Pembayaran
                            </th>

                            <th>
                                Invoice
                            </th>

                            <th>
                                Pelanggan
                            </th>

                            <th>
                                Kasir
                            </th>

                            <th>
                                Metode
                            </th>

                            <th
                                class="text-end">

                                Jumlah

                            </th>

                            <th
                                class="text-end">

                                Uang Diterima

                            </th>

                            <th
                                class="text-end">

                                Kembalian

                            </th>

                            <th>
                                Keterangan
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (!empty($rows)): ?>

                            <?php foreach ($rows as $row): ?>

                                <?php
                                $metodeRow = strtolower(
                                    trim(
                                        (string) (
                                            $row['metode'] ?? ''
                                        )
                                    )
                                );

                                $badgeMetode = 'bg-secondary';

                                if ($metodeRow === 'tunai') {
                                    $badgeMetode = 'bg-success';
                                } elseif ($metodeRow === 'qris') {
                                    $badgeMetode = 'bg-info text-dark';
                                } elseif ($metodeRow === 'transfer') {
                                    $badgeMetode = 'bg-primary';
                                }
                                ?>

                                <tr>

                                    <td>
                                        <?= date(
                                            'd-m-Y H:i',
                                            strtotime(
                                                $row['tanggal_pembayaran']
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= esc(
                                                $row['kode_invoice'] ?? '-'
                                            ) ?>
                                        </strong>

                                        <div class="small text-muted">
                                            ID Transaksi:
                                            <?= esc(
                                                $row['transaksi_id'] ?? '-'
                                            ) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= esc(
                                            $row['nama_pelanggan'] ?? '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= esc(
                                            $row['nama_kasir'] ?? '-'
                                        ) ?>

                                        <?php if (
                                            !empty($row['inisial_kasir'])
                                        ): ?>

                                            <div class="small text-muted">
                                                <?= esc(
                                                    $row['inisial_kasir']
                                                ) ?>
                                            </div>

                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span class="badge <?= $badgeMetode ?>">
                                            <?= esc(
                                                strtoupper(
                                                    $row['metode'] ?? '-'
                                                )
                                            ) ?>
                                        </span>
                                    </td>

                                    <td class="text-end fw-bold">
                                        Rp
                                        <?= number_format(
                                            (float) (
                                                $row['jumlah'] ?? 0
                                            ),
                                            0,
                                            ',',
                                            '.'
                                        ) ?>
                                    </td>

                                    <td class="text-end">

                                        <?php
                                        $uangDiterima =
                                            $row['uang_diterima'] ?? null;
                                        ?>

                                        <?php if (
                                            $uangDiterima !== null &&
                                            $uangDiterima !== ''
                                        ): ?>

                                            Rp
                                            <?= number_format(
                                                (float) $uangDiterima,
                                                0,
                                                ',',
                                                '.'
                                            ) ?>

                                        <?php else: ?>

                                            <span class="text-muted">-</span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="text-end">

                                        <?php
                                        $kembalian =
                                            $row['kembalian'] ?? null;
                                        ?>

                                        <?php if (
                                            $kembalian !== null &&
                                            $kembalian !== ''
                                        ): ?>

                                            Rp
                                            <?= number_format(
                                                (float) $kembalian,
                                                0,
                                                ',',
                                                '.'
                                            ) ?>

                                        <?php else: ?>

                                            <span class="text-muted">-</span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?php if (
                                            trim(
                                                (string) (
                                                    $row['keterangan'] ?? ''
                                                )
                                            ) !== ''
                                        ): ?>

                                            <?= esc(
                                                $row['keterangan']
                                            ) ?>

                                        <?php else: ?>

                                            <span class="text-muted">-</span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>


                    <?php if (
                        !empty($rows)
                    ): ?>

                        <tfoot
                            class="table-light">

                            <tr>

                                <th colspan="5">

                                    TOTAL

                                </th>

                                <th
                                    class="text-end">

                                    Rp
                                    <?= number_format(
                                        $totalPembayaran,
                                        0,
                                        ',',
                                        '.'
                                    ) ?>

                                </th>

                                <th colspan="3"></th>

                            </tr>

                        </tfoot>

                    <?php endif; ?>

                </table>
                <?php if (empty($rows)): ?>

                    <div class="text-center text-muted py-5">

                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>

                        Tidak ada pembayaran
                        pada periode/filter tersebut.

                    </div>

                <?php endif; ?>
            </div>

        </div>

    </div>


    <!-- =========================================================
         DATE RANGE PICKER
         ========================================================= -->
    <script src="https://cdn.jsdelivr.net/npm/moment/min/moment.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    <script>
        $(document).ready(function() {

            const $input =
                $('#filterPembayaran');

            const $mulai =
                $('#tanggal_mulai');

            const $sampai =
                $('#tanggal_sampai');


            function setRangeText(
                start,
                end
            ) {

                $input.val(
                    start.format('DD/MM/YYYY') +
                    ' - ' +
                    end.format('DD/MM/YYYY')
                );
            }


            $input.daterangepicker({

                startDate: moment(
                    $mulai.val(),
                    'YYYY-MM-DD'
                ),

                endDate: moment(
                    $sampai.val(),
                    'YYYY-MM-DD'
                ),

                locale: {

                    format: 'DD/MM/YYYY',

                    applyLabel: 'Terapkan',

                    cancelLabel: 'Batal',

                    customRangeLabel: 'Custom',

                    daysOfWeek: [
                        'Mg',
                        'Sn',
                        'Sl',
                        'Rb',
                        'Km',
                        'Jm',
                        'Sb'
                    ],

                    monthNames: [
                        'Januari',
                        'Februari',
                        'Maret',
                        'April',
                        'Mei',
                        'Juni',
                        'Juli',
                        'Agustus',
                        'September',
                        'Oktober',
                        'November',
                        'Desember'
                    ]
                },

                showDropdowns: true,

                autoApply: true,

                opens: 'left',

                ranges: {

                    'Hari Ini': [
                        moment(),
                        moment()
                    ],

                    '7 Hari Terakhir': [
                        moment().subtract(
                            6,
                            'days'
                        ),
                        moment()
                    ],

                    '30 Hari Terakhir': [
                        moment().subtract(
                            29,
                            'days'
                        ),
                        moment()
                    ],

                    'Bulan Ini': [
                        moment().startOf(
                            'month'
                        ),
                        moment().endOf(
                            'month'
                        )
                    ],

                    'Bulan Lalu': [
                        moment()
                        .subtract(
                            1,
                            'month'
                        )
                        .startOf(
                            'month'
                        ),

                        moment()
                        .subtract(
                            1,
                            'month'
                        )
                        .endOf(
                            'month'
                        )
                    ]
                }

            });


            setRangeText(
                $input.data(
                    'daterangepicker'
                ).startDate,

                $input.data(
                    'daterangepicker'
                ).endDate
            );


            $input.on(
                'apply.daterangepicker',
                function(
                    ev,
                    picker
                ) {

                    $mulai.val(
                        picker.startDate.format(
                            'YYYY-MM-DD'
                        )
                    );

                    $sampai.val(
                        picker.endDate.format(
                            'YYYY-MM-DD'
                        )
                    );

                    setRangeText(
                        picker.startDate,
                        picker.endDate
                    );
                }
            );


            // =====================================================
            // DATATABLES
            // =====================================================

            if (
                $.fn.DataTable &&
                !$.fn.dataTable.isDataTable(
                    '#tableLaporanPembayaran'
                )
            ) {

                $('#tableLaporanPembayaran')
                    .DataTable({

                        responsive: true,

                        pageLength: 25,

                        order: [
                            [0, 'dsc']
                        ],

                        dom: 'Bfrtip',

                        buttons: [

                            {
                                extend: 'copyHtml5',

                                text: '<i class="fas fa-copy"></i> Copy',

                                className: 'btn btn-secondary btn-sm',

                                exportOptions: {
                                    columns: ':visible'
                                }
                            },

                            {
                                extend: 'excelHtml5',

                                text: '<i class="fas fa-file-excel"></i> Excel',

                                className: 'btn btn-success btn-sm',

                                title: 'Laporan Pembayaran',

                                exportOptions: {

                                    columns: ':visible',

                                    format: {

                                        body: function(
                                            data
                                        ) {

                                            if (
                                                typeof data !==
                                                'string'
                                            ) {
                                                return data;
                                            }

                                            let value =
                                                $('<div>')
                                                .html(data)
                                                .text()
                                                .trim();

                                            // Hapus Rp
                                            value =
                                                value.replace(
                                                    /^Rp\s*/i,
                                                    ''
                                                );

                                            // Angka Indonesia
                                            if (
                                                value !== '' &&
                                                /^[0-9.,-]+$/.test(
                                                    value
                                                )
                                            ) {

                                                value =
                                                    value.replace(
                                                        /\./g,
                                                        ''
                                                    );

                                                value =
                                                    value.replace(
                                                        /,/g,
                                                        '.'
                                                    );

                                                if (
                                                    !isNaN(
                                                        value
                                                    )
                                                ) {

                                                    return Number(
                                                        value
                                                    );
                                                }
                                            }

                                            return value;
                                        }
                                    }
                                }
                            },

                            {
                                extend: 'csvHtml5',

                                text: '<i class="fas fa-file-csv"></i> CSV',

                                className: 'btn btn-info btn-sm',

                                fieldSeparator: ';',

                                title: 'Laporan_Pembayaran',

                                exportOptions: {

                                    columns: ':visible',

                                    format: {

                                        body: function(
                                            data
                                        ) {

                                            if (
                                                typeof data !==
                                                'string'
                                            ) {
                                                return data;
                                            }

                                            let value =
                                                $('<div>')
                                                .html(data)
                                                .text()
                                                .trim();

                                            value =
                                                value.replace(
                                                    /^Rp\s*/i,
                                                    ''
                                                );

                                            if (
                                                /^[0-9.,-]+$/.test(
                                                    value
                                                )
                                            ) {

                                                value =
                                                    value.replace(
                                                        /\./g,
                                                        ''
                                                    );

                                                value =
                                                    value.replace(
                                                        /,/g,
                                                        '.'
                                                    );
                                            }

                                            return value;
                                        }
                                    }
                                }
                            },

                            {
                                extend: 'print',

                                text: '<i class="fas fa-print"></i> Print',

                                className: 'btn btn-primary btn-sm',

                                exportOptions: {
                                    columns: ':visible'
                                }
                            }

                        ],

                        language: {

                            search: 'Cari:',

                            lengthMenu: 'Tampilkan _MENU_ data per halaman',

                            zeroRecords: 'Data tidak ditemukan',

                            info: 'Menampilkan _START_ - _END_ dari _TOTAL_ data',

                            infoEmpty: 'Tidak ada data',

                            infoFiltered: '(difilter dari _MAX_ total data)',

                            paginate: {

                                first: 'Pertama',

                                last: 'Terakhir',

                                next: '→',

                                previous: '←'
                            }
                        }

                    });

            }

        });
    </script>

</div>
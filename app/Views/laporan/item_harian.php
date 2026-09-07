<div class="container-fluid py-4">

    <!-- =========================================================
         HEADER
         ========================================================= -->

    <div class="card mb-3">

        <div class="card-header">

            <h5 class="mb-1">
                <i class="fas fa-boxes"></i>
                Item Harian
            </h5>

            <small class="text-muted">
                Daftar item yang memperoleh bagian pembayaran
            </small>

        </div>


        <div class="card-body">

            <form
                method="get"
                class="row g-3 align-items-end">

                <!-- DATE RANGE -->

                <div class="col-md-5">

                    <label
                        class="form-label fw-semibold">

                        Rentang Tanggal

                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="fas fa-calendar-alt"></i>
                        </span>

                        <input
                            type="text"
                            id="filterItemHarian"
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


                <!-- KATEGORI -->

                <div class="col-md-3">

                    <label
                        class="form-label fw-semibold">

                        Kategori

                    </label>

                    <select
                        name="kategori_id"
                        class="form-select">

                        <option value="">
                            Semua Kategori
                        </option>

                        <?php foreach (
                            $kategoriRows
                            as $kategori
                        ): ?>

                            <option
                                value="<?= esc(
                                            $kategori['id']
                                        ) ?>"
                                <?= (
                                    (string)
                                    $kategori['id']
                                    ===
                                    (string)
                                    $kategoriId
                                )
                                    ? 'selected'
                                    : '' ?>>

                                <?= esc(
                                    $kategori['nama']
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- METODE -->

                <div class="col-md-2">

                    <label
                        class="form-label fw-semibold">

                        Metode

                    </label>

                    <select
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


                <!-- CARI -->

                <div class="col-md-2">

                    <label
                        class="form-label fw-semibold">

                        Cari Item

                    </label>

                    <input
                        type="text"
                        name="keyword"
                        value="<?= esc($keyword) ?>"
                        class="form-control"
                        placeholder="Nama produk...">

                </div>


                <!-- BUTTON -->

                <div class="col-12">

                    <button
                        type="submit"
                        class="btn btn-primary">

                        <i class="fas fa-search"></i>
                        Tampilkan

                    </button>


                    <a
                        href="<?= current_url() ?>"
                        class="btn btn-outline-secondary">

                        Reset

                    </a>

                </div>

            </form>

        </div>

    </div>


    <!-- =========================================================
         SUMMARY
         ========================================================= -->

    <div class="row g-2 mb-3">

        <div class="col-md-4">

            <div class="card border-primary h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Jumlah Baris
                    </div>

                    <div class="fs-4 fw-bold text-primary">

                        <?= number_format(
                            $jumlahBaris,
                            0,
                            ',',
                            '.'
                        ) ?>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card border-secondary h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Subtotal Item
                    </div>

                    <div class="fs-4 fw-bold">

                        Rp
                        <?= number_format(
                            $totalSubtotal,
                            0,
                            ',',
                            '.'
                        ) ?>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card border-success h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Nilai Terjual / Teralokasi
                    </div>

                    <div class="fs-4 fw-bold text-success">

                        Rp
                        <?= number_format(
                            $totalTeralokasi,
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
         TABLE
         ========================================================= -->

    <div class="card">

        <div class="card-header">

            <strong>
                Daftar Item Terjual
            </strong>

            <div class="small text-muted">
                Data berasal dari database VIEW
                <code>v_pembayaran_item_harian</code>.
            </div>

        </div>


        <div class="card-body p-0">

            <div class="table-responsive">

                <table id="tableItemHarian"
                    class="table table-bordered table-hover table-sm mb-0">

                    <thead class="table-light">

                        <tr>

                            <th>
                                Tanggal
                            </th>

                            <th>
                                Kategori
                            </th>
                            <th>
                                Invoice
                            </th>

                            <th>
                                Item
                            </th>

                            <th
                                class="text-end">

                                Qty

                            </th>

                            <th
                                class="text-end">

                                Subtotal Item

                            </th>

                            <th
                                class="text-end">

                                Pembayaran

                            </th>

                            <th
                                class="text-end">

                                Nilai Terjual

                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                            empty($rows)
                        ): ?>

                            <tr>

                                <td
                                    colspan="8"
                                    class="text-center
                                       text-muted
                                       py-5">

                                    <i
                                        class="fas fa-inbox fa-2x mb-2 d-block">
                                    </i>

                                    Tidak ada data pada
                                    periode/filter tersebut.

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach (
                                $rows
                                as $row
                            ): ?>

                                <tr>

                                    <td>

                                        <?= date(
                                            'd-m-Y',
                                            strtotime(
                                                $row['tanggal_pembayaran']
                                            )
                                        ) ?>

                                    </td>

                                    <td>
                                        <?= esc(
                                            $row['kode_invoice'] ?? '-'
                                        ) ?>
                                    </td>
                                    <td>

                                        <?= esc(
                                            $row['kategori_nama']
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= esc(
                                            $row['nama_produk']
                                        ) ?>

                                    </td>


                                    <td class="text-end">

                                        <?= rtrim(
                                            rtrim(
                                                number_format(
                                                    $row['jumlah_item'],
                                                    2,
                                                    ',',
                                                    '.'
                                                ),
                                                '0'
                                            ),
                                            ','
                                        ) ?>

                                    </td>


                                    <td class="text-end">

                                        Rp
                                        <?= number_format(
                                            $row['subtotal_item'],
                                            0,
                                            ',',
                                            '.'
                                        ) ?>

                                    </td>


                                    <td class="text-end">

                                        Rp
                                        <?= number_format(
                                            $row['total_pembayaran'],
                                            0,
                                            ',',
                                            '.'
                                        ) ?>

                                    </td>


                                    <td
                                        class="text-end
                                           fw-bold">

                                        Rp
                                        <?= number_format(
                                            $row['total_teralokasi'],
                                            0,
                                            ',',
                                            '.'
                                        ) ?>

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

                                <th
                                    colspan="5">

                                    TOTAL

                                </th>

                                <th
                                    class="text-end">

                                    Rp
                                    <?= number_format(
                                        $totalSubtotal,
                                        0,
                                        ',',
                                        '.'
                                    ) ?>

                                </th>

                                <th
                                    class="text-end">

                                    -

                                </th>

                                <th
                                    class="text-end">

                                    Rp
                                    <?= number_format(
                                        $totalTeralokasi,
                                        0,
                                        ',',
                                        '.'
                                    ) ?>

                                </th>

                            </tr>

                        </tfoot>

                    <?php endif; ?>

                </table>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     DATE RANGE PICKER
     ========================================================= -->

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">

<script
    src="https://cdn.jsdelivr.net/npm/moment/min/moment.min.js">
</script>

<script
    src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js">
</script>
<script>
    $(document).ready(function() {

        const table = $('#tableItemHarian').DataTable({

            responsive: true,

            pageLength: 50,

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

                    title: 'Item Harian ' +
                        '<?= esc($tanggal_mulai) ?>' +
                        ' s/d ' +
                        '<?= esc($tanggal_sampai) ?>',

                    exportOptions: {
                        columns: ':visible',

                        format: {
                            body: function(data, row, column, node) {

                                if (typeof data !== 'string') {
                                    return data;
                                }

                                // Hapus HTML jika ada
                                let value = $('<div>')
                                    .html(data)
                                    .text()
                                    .trim();

                                // Hapus Rp dan spasi
                                value = value.replace(/^Rp\s*/i, '');

                                // Hapus pemisah ribuan
                                value = value.replace(/\./g, '');

                                // Ubah koma desimal menjadi titik
                                value = value.replace(/,/g, '.');

                                // Kalau memang angka, kirim sebagai number
                                if (
                                    value !== '' &&
                                    !isNaN(value)
                                ) {
                                    return Number(value);
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

                    title: 'Item_Harian_' +
                        '<?= esc($tanggal_mulai) ?>' +
                        '_' +
                        '<?= esc($tanggal_sampai) ?>',

                    exportOptions: {
                        columns: ':visible'
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

    });
</script>
<script>
    $(document).ready(function() {

        const $input =
            $('#filterItemHarian');

        const $mulai =
            $('#tanggal_mulai');

        const $sampai =
            $('#tanggal_sampai');


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
                    .startOf('month'),

                    moment()
                    .subtract(
                        1,
                        'month'
                    )
                    .endOf('month')
                ]
            }

        });


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

                $input.val(
                    picker.startDate.format(
                        'DD/MM/YYYY'
                    ) +
                    ' - ' +
                    picker.endDate.format(
                        'DD/MM/YYYY'
                    )
                );
            }
        );


        $input.val(
            $mulai.val().split('-').reverse().join('/') +
            ' - ' +
            $sampai.val().split('-').reverse().join('/')
        );

    });
</script>
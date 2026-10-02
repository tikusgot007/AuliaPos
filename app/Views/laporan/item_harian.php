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
                id="formFilterItemHarian"
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
                        name="tanggal_awal"
                        id="tanggal_awal"
                        value="<?= esc($tanggal_awal) ?>">

                    <input
                        type="hidden"
                        name="tanggal_akhir"
                        id="tanggal_akhir"
                        value="<?= esc($tanggal_akhir) ?>">

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

                    <div class="fs-4 fw-bold text-primary" id="summaryJumlahBaris">0</div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card border-secondary h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Subtotal Item
                    </div>

                    <div class="fs-4 fw-bold">Rp <span id="summarySubtotal">0</span></div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card border-success h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Nilai Terjual / Teralokasi
                    </div>

                    <div class="fs-4 fw-bold text-success">Rp <span id="summaryTeralokasi">0</span></div>

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


                    </tbody>


                        <tfoot
                            class="table-light">

                            <tr>

                                <th>TOTAL</th>

                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>

                                <th class="text-end" id="ftSubtotal">Rp 0</th>

                                <th class="text-end">-</th>

                                <th class="text-end" id="ftTeralokasi">Rp 0</th>

                            </tr>

                        </tfoot>

                </table>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     DATE RANGE PICKER
     ========================================================= -->

<script>
    $(document).ready(function() {

        const exportUrl = '<?= base_url('laporan/item-harian-export') ?>';

        function filterParams() {
            return {
                tanggal_awal: $('#tanggal_awal').val(),
                tanggal_akhir: $('#tanggal_akhir').val(),
                kategori_id: $('[name="kategori_id"]').val(),
                metode: $('[name="metode"]').val(),
                keyword: $('[name="keyword"]').val()
            };
        }

        function rupiah(n) {
            return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
        }

        const table = $('#tableItemHarian').DataTable({
            serverSide: true,
            processing: true,
            responsive: true,
            pageLength: 50,
            dom: 'Bfrtip',
            ajax: {
                url: '<?= base_url('laporan/item-harian-data') ?>',
                data: function(d) {
                    Object.assign(d, filterParams());
                }
            },
            columns: [
                { data: 'tanggal_pembayaran' },
                { data: 'kategori_nama' },
                { data: 'kode_invoice' },
                { data: 'nama_produk' },
                { data: 'jumlah_item', className: 'text-end' },
                { data: 'subtotal_item', className: 'text-end', render: function(d) { return rupiah(d); } },
                { data: 'total_pembayaran', className: 'text-end', render: function(d) { return rupiah(d); } },
                { data: 'total_teralokasi', className: 'text-end', render: function(d) { return rupiah(d); } }
            ],
            order: [[0, 'asc'], [7, 'desc']],
            footerCallback: function() {
                const json = this.api().ajax.json() || {};
                const totals = json.totals || {};
                $('#ftSubtotal').text(rupiah(totals.subtotal_item));
                $('#ftTeralokasi').text(rupiah(totals.total_teralokasi));
                $('#summaryJumlahBaris').text(Number(json.recordsFiltered || 0).toLocaleString('id-ID'));
                $('#summarySubtotal').text(Number(totals.subtotal_item || 0).toLocaleString('id-ID'));
                $('#summaryTeralokasi').text(Number(totals.total_teralokasi || 0).toLocaleString('id-ID'));
            },
            language: {
                processing: 'Memuat...',
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
            },
            buttons: [
                {
                    text: '<i class="fas fa-copy"></i> Copy',
                    className: 'btn btn-secondary btn-sm',
                    action: function() {
                        $.get(exportUrl, filterParams()).done(function(csv) {
                            if (window.navigator.clipboard) {
                                window.navigator.clipboard.writeText(csv);
                            }
                        });
                    }
                },
                {
                    text: '<i class="fas fa-file-excel"></i> Excel/CSV',
                    className: 'btn btn-success btn-sm',
                    action: function() {
                        window.location = exportUrl + '?' + $.param(filterParams());
                    }
                },
                {
                    text: '<i class="fas fa-print"></i> Print',
                    className: 'btn btn-primary btn-sm',
                    action: function() {
                        window.print();
                    }
                }
            ]
        });

        $('#formFilterItemHarian').on('submit', function(e) {
            e.preventDefault();
            table.ajax.reload();
        });

        const $input = $('#filterItemHarian');
        const $mulai = $('#tanggal_awal');
        const $sampai = $('#tanggal_akhir');

        $input.daterangepicker({
            startDate: moment($mulai.val(), 'YYYY-MM-DD'),
            endDate: moment($sampai.val(), 'YYYY-MM-DD'),
            locale: AuliaDateRange.locale(),
            ranges: AuliaDateRange.ranges(),
            showDropdowns: true,
            opens: 'left'
        });

        $input.on('apply.daterangepicker', function(ev, picker) {
            $mulai.val(picker.startDate.format('YYYY-MM-DD'));
            $sampai.val(picker.endDate.format('YYYY-MM-DD'));
            $input.val(
                picker.startDate.format('DD/MM/YYYY') +
                ' - ' +
                picker.endDate.format('DD/MM/YYYY')
            );
        });

        $input.val(
            $mulai.val().split('-').reverse().join('/') +
            ' - ' +
            $sampai.val().split('-').reverse().join('/')
        );

    });
</script>

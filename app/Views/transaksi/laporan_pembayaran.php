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
                id="formFilterPembayaran"
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
                        name="tanggal_awal"
                        id="tanggal_awal"
                        value="<?= esc($tanggal_awal) ?>">

                    <input
                        type="hidden"
                        name="tanggal_akhir"
                        id="tanggal_akhir"
                        value="<?= esc($tanggal_akhir) ?>">

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

                    <div class="fs-4 fw-bold text-success">Rp <span id="summaryTotal">0</span></div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card border-secondary h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Tunai
                    </div>

                    <div class="fs-5 fw-bold">Rp <span id="summaryTunai">0</span></div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card border-info h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        QRIS
                    </div>

                    <div class="fs-5 fw-bold text-info">Rp <span id="summaryQris">0</span></div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card border-primary h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Transfer
                    </div>

                    <div class="fs-5 fw-bold text-primary">Rp <span id="summaryTransfer">0</span></div>

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

                        <?= esc($tanggal_awal) ?>
                        s/d
                        <?= esc($tanggal_akhir) ?>

                    </div>

                </div>

                <span class="badge bg-primary">

                    <span id="summaryJumlahBaris">0</span>
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


                    </tbody>


                        <tfoot
                            class="table-light">

                            <tr>

                                <th>TOTAL</th>

                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>

                                <th class="text-end" id="ftTotal">Rp 0</th>

                                <th></th>
                                <th></th>
                                <th></th>

                            </tr>

                        </tfoot>

                </table>
            </div>

        </div>

    </div>


    <!-- =========================================================
         DATE RANGE PICKER
         ========================================================= -->
<script>
    $(document).ready(function() {

        const exportUrl = '<?= base_url('laporan-pembayaran-export') ?>';

        function filterParams() {
            return {
                tanggal_awal: $('#tanggal_awal').val(),
                tanggal_akhir: $('#tanggal_akhir').val(),
                metode: $('[name="metode"]').val(),
                keyword: $('[name="keyword"]').val()
            };
        }

        function rupiah(n) {
            return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
        }

        function fmtTanggal(s) {
            if (!s) {
                return '-';
            }
            const parts = String(s).split(' ');
            const d = parts[0].split('-');
            const t = (parts[1] || '').slice(0, 5);
            return d[2] + '-' + d[1] + '-' + d[0] + (t ? ' ' + t : '');
        }

        function badgeMetode(metode) {
            const m = String(metode || '').toLowerCase();
            let cls = 'bg-secondary';
            if (m === 'tunai') {
                cls = 'bg-success';
            } else if (m === 'qris') {
                cls = 'bg-info text-dark';
            } else if (m === 'transfer') {
                cls = 'bg-primary';
            }
            const label = m ? m.charAt(0).toUpperCase() + m.slice(1) : '-';
            return '<span class="badge ' + cls + '">' + label + '</span>';
        }

        const table = $('#tableLaporanPembayaran').DataTable({
            serverSide: true,
            processing: true,
            responsive: true,
            pageLength: 25,
            dom: 'Bfrtip',
            ajax: {
                url: '<?= base_url('laporan-pembayaran-data') ?>',
                data: function(d) {
                    Object.assign(d, filterParams());
                }
            },
            columns: [
                { data: 'tanggal_pembayaran', render: function(d) { return fmtTanggal(d); } },
                { data: 'kode_invoice' },
                { data: 'nama_pelanggan' },
                {
                    data: 'nama_kasir',
                    render: function(d, type, row) {
                        return d + (row.username_kasir ? '<br><small class="text-muted">' + row.username_kasir + '</small>' : '');
                    }
                },
                { data: 'metode', render: function(d) { return badgeMetode(d); } },
                { data: 'jumlah', className: 'text-end', render: function(d) { return rupiah(d); } },
                { data: 'uang_diterima', className: 'text-end', render: function(d) { return rupiah(d); } },
                { data: 'kembalian', className: 'text-end', render: function(d) { return rupiah(d); } },
                { data: 'keterangan' }
            ],
            order: [[0, 'desc']],
            footerCallback: function() {
                const json = this.api().ajax.json() || {};
                const totals = json.totals || {};
                $('#ftTotal').text(rupiah(totals.total));
                $('#summaryTotal').text(Number(totals.total || 0).toLocaleString('id-ID'));
                $('#summaryTunai').text(Number(totals.tunai || 0).toLocaleString('id-ID'));
                $('#summaryQris').text(Number(totals.qris || 0).toLocaleString('id-ID'));
                $('#summaryTransfer').text(Number(totals.transfer || 0).toLocaleString('id-ID'));
                $('#summaryJumlahBaris').text(Number(json.recordsFiltered || 0).toLocaleString('id-ID'));
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
                    text: '<i class="fas fa-file-excel"></i> Excel',
                    className: 'btn btn-success btn-sm',
                    action: function() {
                        window.location = exportUrl + '?' + $.param(filterParams());
                    }
                }
            ]
        });

        $('#formFilterPembayaran').on('submit', function(e) {
            e.preventDefault();
            table.ajax.reload();
        });

        const $input = $('#filterPembayaran');
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

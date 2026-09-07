<style>
    .action-buttons {
        white-space: nowrap;
        text-align: center;
    }

    .action-buttons .btn {
        width: 38px;
        height: 36px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px !important;
        margin: 0 2px;
    }

    .action-buttons .btn i {
        font-size: 16px;
    }
</style>
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">Data Produk</h4>
            <small class="text-muted">
                Double-click pada sel untuk mengedit data secara langsung.
            </small>
        </div>

        <div class="d-flex gap-2">
            <?php if (session()->get('role') === 'admin'): ?>
                <a href="<?= base_url('produk/maintenance') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-tools"></i> Maintenance Barang
                </a>
            <?php endif; ?>
            <a href="<?= base_url('produk/tambah') ?>" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Tambah Produk
            </a>
        </div>
    </div>

    <div class="alert alert-info py-2">
        <i class="bi bi-info-circle"></i>
        <strong>Edit langsung:</strong>
        double-click kolom Barcode, Nama, Kategori, Satuan, Harga Beli, atau Harga Jual.
        Tekan <kbd>Enter</kbd> untuk menyimpan atau <kbd>Esc</kbd> untuk membatalkan.
    </div>

    <div class="card shadow-sm">
        <div class="card-body">

            <div class="table-responsive">
                <table id="tableProduk" class="table table-bordered table-hover align-middle w-100">
                    <thead class="table-light">
                        <tr>
                            <th width="50">#</th>
                            <th>Barcode</th>
                            <th>Nama</th>
                            <th>Kategori</th>
                            <th>Satuan</th>
                            <th>Harga Beli</th>
                            <th>Harga Jual</th>
                            <th width="100">Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</div>

<style>
    /* Sel yang bisa diedit */
    .inline-editable {
        cursor: pointer;
        position: relative;
    }

    .inline-editable:hover {
        background-color: rgba(13, 110, 253, 0.08);
    }

    /* Saat sedang diedit */
    .inline-editing {
        padding: 3px !important;
        background-color: rgba(255, 193, 7, 0.12) !important;
    }

    .inline-editing input,
    .inline-editing select {
        width: 100%;
        min-width: 100px;
        border: 1px solid #86b7fe;
        border-radius: 4px;
        padding: 5px 7px;
        outline: none;
    }

    .inline-editing input:focus,
    .inline-editing select:focus {
        border-color: #0d6efd;
        box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.15);
    }

    .inline-saving {
        opacity: 0.6;
        pointer-events: none;
    }

    .inline-error {
        background-color: rgba(220, 53, 69, 0.12) !important;
    }

    .inline-saved {
        background-color: rgba(25, 135, 84, 0.12) !important;
    }

    .price-cell {
        text-align: right;
        white-space: nowrap;
    }

    .action-buttons {
        white-space: nowrap;
    }

    kbd {
        font-size: 0.8em;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        /*
         * ==========================================
         * DATA KATEGORI
         * ==========================================
         */
        const kategori = <?= json_encode($kategori ?? []) ?>;

        /*
         * ==========================================
         * FORMAT RUPIAH
         * ==========================================
         */
        function formatRupiah(value) {
            if (value === null || value === undefined || value === '') {
                return 'Rp 0';
            }

            const number = Number(value);

            if (isNaN(number)) {
                return 'Rp 0';
            }

            return 'Rp ' + number.toLocaleString('id-ID');
        }

        /*
         * ==========================================
         * ESCAPE HTML
         * ==========================================
         */
        function escapeHtml(value) {
            if (value === null || value === undefined) {
                return '';
            }

            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        /*
         * ==========================================
         * OPTIONS KATEGORI
         * ==========================================
         */
        function getKategoriOptions(selectedId) {
            let html = '';

            kategori.forEach(function(item) {
                const selected =
                    String(item.id) === String(selectedId) ?
                    'selected' :
                    '';

                html += `
                <option value="${escapeHtml(item.id)}" ${selected}>
                    ${escapeHtml(item.nama)}
                </option>
            `;
            });

            return html;
        }

        /*
         * ==========================================
         * OPTIONS SATUAN
         * ==========================================
         */
        const satuanOptions = [
            'pcs',
            'meter',
            'lembar',
            'botol',
            'gelas',
            'sachet'
        ];

        function getSatuanOptions(selectedValue) {
            let html = '';

            satuanOptions.forEach(function(item) {
                const selected =
                    String(item) === String(selectedValue) ?
                    'selected' :
                    '';

                html += `
                <option value="${escapeHtml(item)}" ${selected}>
                    ${escapeHtml(item)}
                </option>
            `;
            });

            return html;
        }

        /*
         * ==========================================
         * DATATABLES
         * ==========================================
         */
        const table = $('#tableProduk').DataTable({
            processing: true,
            serverSide: true,

            ajax: {
                url: '<?= base_url('produk/get-produk-data') ?>',
                type: 'GET'
            },

            pageLength: 10,

            lengthMenu: [
                [10, 25, 50, 100],
                [10, 25, 50, 100]
            ],

            order: [
                [0, 'desc']
            ],

            columns: [

                {
                    data: 'id',
                    searchable: false,
                    orderable: true
                },

                {
                    data: 'barcode',
                    className: 'inline-editable',
                    render: function(data) {
                        return data ?
                            escapeHtml(data) :
                            '<span class="text-muted">-</span>';
                    }
                },

                {
                    data: 'nama',
                    className: 'inline-editable',
                    render: function(data) {
                        return escapeHtml(data);
                    }
                },

                {
                    data: 'kategori_id',
                    className: 'inline-editable',
                    render: function(data, type, row) {
                        return escapeHtml(row.kategori_nama || '-');
                    }
                },

                {
                    data: 'satuan',
                    className: 'inline-editable',
                    render: function(data) {
                        return escapeHtml(data || '-');
                    }
                },

                {
                    data: 'harga_beli',
                    className: 'inline-editable price-cell',
                    render: function(data) {
                        return formatRupiah(data);
                    }
                },

                {
                    data: 'harga_jual',
                    className: 'inline-editable price-cell',
                    render: function(data) {
                        return formatRupiah(data);
                    }
                },

                {
                    data: null,
                    searchable: false,
                    orderable: false,
                    className: 'action-buttons text-center',

                    render: function(data, type, row) {
                        return `
    <div class="action-buttons">

        <a href="<?= base_url('produk/edit') ?>/${row.id}"
           class="btn btn-warning"
           title="Edit">
            <i class="bi bi-pencil"></i>
        </a>

        <a href="<?= base_url('produk/hapus') ?>/${row.id}"
           class="btn btn-danger"
           title="Nonaktifkan"
           data-confirm-message="Nonaktifkan produk ini?" data-confirm-ok-text="Ya, Nonaktifkan">
            <i class="bi bi-trash"></i>
        </a>

    </div>
`;
                    }
                }
            ],

            language: {
                processing: 'Memproses...',
                search: 'Cari:',
                lengthMenu: 'Tampilkan _MENU_ data',
                info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',
                infoEmpty: 'Tidak ada data',
                zeroRecords: 'Produk tidak ditemukan',
                emptyTable: 'Belum ada data produk',
                paginate: {
                    first: 'Pertama',
                    last: 'Terakhir',
                    next: '›',
                    previous: '‹'
                }
            }
        });

        /*
         * ==========================================
         * INLINE EDIT
         * ==========================================
         */
        $('#tableProduk tbody').on('dblclick', 'td.inline-editable', function() {

            const cell = table.cell(this);
            const row = table.row(this.closest('tr'));
            const rowData = row.data();

            if (!rowData) {
                return;
            }

            const columnIndex = cell.index().column;
            const column = table.settings()[0].aoColumns[columnIndex];

            const fieldMap = {
                1: 'barcode',
                2: 'nama',
                3: 'kategori_id',
                4: 'satuan',
                5: 'harga_beli',
                6: 'harga_jual'
            };

            const field = fieldMap[columnIndex];

            if (!field) {
                return;
            }

            startInlineEdit(this, rowData, field);
        });

        /*
         * ==========================================
         * MULAI EDIT
         * ==========================================
         */
        function startInlineEdit(td, rowData, field) {

            // Jangan membuka editor kedua
            if ($(td).hasClass('inline-editing')) {
                return;
            }

            const oldValue = rowData[field] ?? '';

            $(td)
                .addClass('inline-editing')
                .data('old-value', oldValue);

            let input;

            /*
             * Barcode
             */
            if (field === 'barcode') {

                input = $('<input>', {
                    type: 'text',
                    value: oldValue ?? ''
                });

            }

            /*
             * Nama
             */
            else if (field === 'nama') {

                input = $('<input>', {
                    type: 'text',
                    value: oldValue ?? ''
                });

            }

            /*
             * Kategori
             */
            else if (field === 'kategori_id') {

                input = $('<select>');

                input.html(getKategoriOptions(oldValue));

            }

            /*
             * Satuan
             */
            else if (field === 'satuan') {

                input = $('<select>');

                input.html(getSatuanOptions(oldValue));

            }

            /*
             * Harga
             */
            else if (
                field === 'harga_beli' ||
                field === 'harga_jual'
            ) {

                input = $('<input>', {
                    type: 'number',
                    min: 0,
                    step: '0.01',
                    value: oldValue ?? ''
                });

            }

            if (!input) {
                return;
            }

            $(td)
                .empty()
                .append(input);

            input.focus();

            if (input.is('input')) {
                input.select();
            }

            /*
             * ==========================================
             * ENTER = SIMPAN
             * ESC = BATAL
             * ==========================================
             */
            input.on('keydown', function(e) {

                if (e.key === 'Enter') {
                    e.preventDefault();

                    saveInlineEdit(
                        td,
                        rowData.id,
                        field,
                        input.val(),
                        oldValue
                    );
                }

                if (e.key === 'Escape') {
                    e.preventDefault();

                    cancelInlineEdit(
                        td,
                        rowData,
                        field,
                        oldValue
                    );
                }
            });

            /*
             * Untuk select, perubahan langsung bisa
             * disimpan dengan Enter.
             */
            input.on('change', function() {

                if (field === 'kategori_id' || field === 'satuan') {
                    saveInlineEdit(
                        td,
                        rowData.id,
                        field,
                        input.val(),
                        oldValue
                    );
                }
            });
        }

        /*
         * ==========================================
         * SIMPAN
         * ==========================================
         */
        function saveInlineEdit(td, id, field, value, oldValue) {

            // Tidak ada perubahan
            if (String(value ?? '') === String(oldValue ?? '')) {
                restoreCell(td, field, oldValue);
                return;
            }

            $(td).addClass('inline-saving');

            $.ajax({
                url: '<?= base_url('produk/update-inline') ?>',
                type: 'POST',

                data: {
                    id: id,
                    field: field,
                    value: value
                },

                dataType: 'json'

            }).done(function(response) {

                if (!response || response.success !== true) {

                    showInlineError(
                        td,
                        field,
                        oldValue,
                        response?.message || 'Gagal menyimpan data.'
                    );

                    return;
                }

                // Ambil row DataTables
                const row = table.row($(td).closest('tr'));
                const rowData = row.data();

                if (!rowData) {
                    return;
                }

                // Update field yang baru disimpan
                rowData[field] = value;

                // Jika kategori berubah, update nama kategori
                if (field === 'kategori_id') {

                    const selectedKategori = kategori.find(function(item) {
                        return String(item.id) === String(value);
                    });

                    rowData.kategori_nama = selectedKategori ?
                        selectedKategori.nama :
                        '-';
                }

                /*
                 * Update baris di browser saja.
                 * TIDAK melakukan AJAX reload seluruh tabel.
                 */
                row.data(rowData).draw(false);

            }).fail(function(xhr) {

                let message = 'Gagal menyimpan data.';

                if (
                    xhr.responseJSON &&
                    xhr.responseJSON.message
                ) {
                    message = xhr.responseJSON.message;
                }

                showInlineError(
                    td,
                    field,
                    oldValue,
                    message
                );

            }).always(function() {

                $(td).removeClass('inline-saving');

            });
        }

        /*
         * ==========================================
         * BATAL EDIT
         * ==========================================
         */
        function cancelInlineEdit(td, rowData, field, oldValue) {

            restoreCell(td, field, oldValue);
        }

        /*
         * ==========================================
         * RESTORE CELL
         * ==========================================
         */
        function restoreCell(td, field, value) {

            let displayValue = value;

            if (field === 'harga_beli' || field === 'harga_jual') {
                displayValue = formatRupiah(value);
            }

            if (field === 'barcode') {
                displayValue = value ?
                    escapeHtml(value) :
                    '<span class="text-muted">-</span>';
            }

            if (field === 'nama') {
                displayValue = escapeHtml(value);
            }

            if (field === 'kategori_id') {

                const item = kategori.find(function(kategoriItem) {
                    return String(kategoriItem.id) === String(value);
                });

                displayValue = item ?
                    escapeHtml(item.nama) :
                    '-';
            }

            if (field === 'satuan') {
                displayValue = escapeHtml(value || '-');
            }

            $(td)
                .removeClass('inline-editing inline-error')
                .html(displayValue);
        }

        /*
         * ==========================================
         * ERROR
         * ==========================================
         */
        function showInlineError(td, field, oldValue, message) {

            restoreCell(td, field, oldValue);

            $(td)
                .addClass('inline-error')
                .attr('title', message);

            alert(message);

            setTimeout(function() {
                $(td)
                    .removeClass('inline-error')
                    .removeAttr('title');
            }, 1500);
        }

    });
</script>
```
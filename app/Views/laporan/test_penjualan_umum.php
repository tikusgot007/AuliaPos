<div class="container-fluid py-4">

    <!-- =========================================================
         HEADER
         ========================================================= -->

    <div class="card mb-3">

        <div class="card-header bg-primary text-white">

            <div class="d-flex justify-content-between align-items-center">

                <div>

                    <h5 class="mb-1">
                        <i class="fas fa-chart-line"></i>
                        Test Laporan Penjualan
                    </h5>

                    <div class="small opacity-75">
                        Perhitungan berdasarkan pembayaran aktual
                    </div>

                </div>

            </div>

        </div>


        <div class="card-body">

            <!-- =================================================
                 FILTER
                 ================================================= -->

            <form
                method="get"
                class="row g-3 align-items-end">

                <!-- TANGGAL -->

                <div class="col-md-3">

                    <label
                        for="tanggal"
                        class="form-label fw-semibold">

                        Tanggal Pembayaran

                    </label>

                    <input
                        type="date"
                        id="tanggal"
                        name="tanggal"
                        value="<?= esc($tanggal) ?>"
                        class="form-control">

                </div>


                <!-- KATEGORI -->

                <div class="col-md-4">

                    <label
                        for="kategori_id"
                        class="form-label fw-semibold">

                        Kategori

                    </label>

                    <select
                        id="kategori_id"
                        name="kategori_id"
                        class="form-select">

                        <?php foreach (
                            $kategoriRows
                            as $kategori
                        ): ?>

                            <option
                                value="<?= esc($kategori['id']) ?>"
                                <?= (
                                    (int) $kategori['id']
                                    ===
                                    (int) $kategoriId
                                )
                                    ? 'selected'
                                    : '' ?>>

                                <?= esc($kategori['nama']) ?>

                                (ID:
                                <?= esc($kategori['id']) ?>
                                )

                            </option>

                        <?php endforeach; ?>

                    </select>

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

            </form>

        </div>

    </div>


    <!-- =========================================================
         RINGKASAN
         ========================================================= -->

    <div class="row mb-3">

        <!-- TOTAL LAPORAN -->

        <div class="col-md-6">

            <div class="card border-success h-100">

                <div class="card-body">

                    <div class="small text-muted mb-1">
                        Total yang masuk laporan
                    </div>

                    <div class="fs-3 fw-bold text-success">

                        Rp
                        <?= number_format(
                            $total,
                            2,
                            ',',
                            '.'
                        ) ?>

                    </div>

                    <div class="small text-muted mt-2">

                        Kategori:
                        <strong>
                            <?= esc($kategoriNama) ?>
                        </strong>

                        <br>

                        Tanggal:
                        <strong>
                            <?= esc($tanggal) ?>
                        </strong>

                    </div>

                </div>

            </div>

        </div>


        <!-- KETERANGAN -->

        <div class="col-md-6">

            <div class="card border-info h-100">

                <div class="card-body">

                    <div class="fw-semibold mb-2">
                        Logika Perhitungan
                    </div>

                    <div class="small">

                        <div class="mb-1">
                            <strong>1.</strong>
                            Ambil pembayaran yang terjadi
                            pada tanggal laporan.
                        </div>

                        <div class="mb-1">
                            <strong>2.</strong>
                            Kelompokkan pembayaran berdasarkan
                            transaksi.
                        </div>

                        <div class="mb-1">
                            <strong>3.</strong>
                            Hitung proporsi setiap detail terhadap
                            seluruh subtotal detail transaksi.
                        </div>

                        <div class="mb-1">
                            <strong>4.</strong>
                            Alokasikan pembayaran aktual sesuai
                            proporsi tersebut.
                        </div>

                        <div>
                            <strong>5.</strong>
                            Hanya detail dengan kategori yang dipilih
                            yang masuk total laporan.
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         TABEL HASIL
         ========================================================= -->

    <div class="card">

        <div class="card-header">

            <div
                class="d-flex justify-content-between
                       align-items-center
                       flex-wrap
                       gap-2">

                <div>

                    <h6 class="mb-0">
                        Detail Perhitungan
                    </h6>

                    <small class="text-muted">

                        Semua detail transaksi ditampilkan
                        untuk memudahkan pengecekan proporsi.

                    </small>

                </div>

                <div>

                    <span class="badge bg-primary">

                        Kategori:
                        <?= esc($kategoriNama) ?>

                    </span>

                </div>

            </div>

        </div>


        <div class="card-body p-0">

            <div class="table-responsive">

                <table
                    class="table table-bordered
                           table-hover
                           table-striped
                           table-sm
                           mb-0">

                    <thead class="table-light">

                        <tr>

                            <th
                                class="text-center"
                                style="width: 50px;">

                                #

                            </th>

                            <th>
                                Invoice
                            </th>

                            <th>
                                Produk
                            </th>

                            <th
                                class="text-center">

                                Kategori

                            </th>

                            <th
                                class="text-end">

                                Qty

                            </th>

                            <th
                                class="text-end">

                                Subtotal Detail

                            </th>

                            <th
                                class="text-end">

                                Total Subtotal
                                Transaksi

                            </th>

                            <th
                                class="text-end">

                                Proporsi

                            </th>

                            <th
                                class="text-end">

                                Pembayaran Hari Ini

                            </th>

                            <th
                                class="text-end">

                                Alokasi Pembayaran

                            </th>

                            <th
                                class="text-center">

                                Masuk Laporan

                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (empty($rows)): ?>

                            <tr>

                                <td
                                    colspan="11"
                                    class="text-center
                                       text-muted
                                       py-5">

                                    <i
                                        class="fas fa-inbox fa-2x mb-2
                                           d-block">
                                    </i>

                                    Tidak ada pembayaran
                                    pada tanggal

                                    <strong>
                                        <?= esc($tanggal) ?>
                                    </strong>.

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php
                            $nomor = 1;
                            ?>

                            <?php foreach (
                                $rows
                                as $row
                            ): ?>

                                <tr>

                                    <!-- NO -->

                                    <td class="text-center">

                                        <?= $nomor++ ?>

                                    </td>


                                    <!-- INVOICE -->

                                    <td>

                                        <div class="fw-semibold">
                                            <?= esc(
                                                $row['invoice']
                                            ) ?>
                                        </div>

                                        <div class="small text-muted">

                                            ID:
                                            <?= esc(
                                                $row['transaksi_id']
                                            ) ?>

                                        </div>

                                    </td>


                                    <!-- PRODUK -->

                                    <td>

                                        <?= esc(
                                            $row['produk']
                                        ) ?>

                                    </td>


                                    <!-- KATEGORI -->

                                    <td class="text-center">

                                        <?php if (
                                            $row['kategori_id']
                                            ==
                                            $kategoriId
                                        ): ?>

                                            <span
                                                class="badge bg-success">

                                                <?= esc(
                                                    $row['kategori_id']
                                                ) ?>

                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="badge bg-secondary">

                                                <?= esc(
                                                    $row['kategori_id']
                                                ) ?>

                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- QTY -->

                                    <td class="text-end">

                                        <?= rtrim(
                                            rtrim(
                                                number_format(
                                                    $row['jumlah'],
                                                    2,
                                                    ',',
                                                    '.'
                                                ),
                                                '0'
                                            ),
                                            ','
                                        ) ?>

                                    </td>


                                    <!-- SUBTOTAL DETAIL -->

                                    <td class="text-end">

                                        Rp
                                        <?= number_format(
                                            $row['subtotal_detail'],
                                            2,
                                            ',',
                                            '.'
                                        ) ?>

                                    </td>


                                    <!-- TOTAL SUBTOTAL TRANSAKSI -->

                                    <td class="text-end">

                                        Rp
                                        <?= number_format(
                                            $row['total_subtotal_transaksi'],
                                            2,
                                            ',',
                                            '.'
                                        ) ?>

                                    </td>


                                    <!-- PROPORSI -->

                                    <td class="text-end">

                                        <?= number_format(
                                            $row['persentase'],
                                            4,
                                            ',',
                                            '.'
                                        ) ?>%

                                    </td>


                                    <!-- PEMBAYARAN HARI INI -->

                                    <td class="text-end">

                                        Rp
                                        <?= number_format(
                                            $row['pembayaran_hari_ini'],
                                            2,
                                            ',',
                                            '.'
                                        ) ?>

                                    </td>


                                    <!-- ALOKASI -->

                                    <td
                                        class="text-end
                                           fw-semibold">

                                        Rp
                                        <?= number_format(
                                            $row['nilai_dibayar_detail'],
                                            2,
                                            ',',
                                            '.'
                                        ) ?>

                                    </td>


                                    <!-- MASUK LAPORAN -->

                                    <td class="text-center">

                                        <?php if (
                                            $row['masuk_laporan']
                                        ): ?>

                                            <span
                                                class="badge bg-success">

                                                YA

                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="badge bg-secondary">

                                                TIDAK

                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>


                    <?php if (!empty($rows)): ?>

                        <tfoot
                            class="table-light">

                            <tr>

                                <th
                                    colspan="10"
                                    class="text-end">

                                    TOTAL KATEGORI
                                    <?= esc(
                                        $kategoriNama
                                    ) ?>

                                </th>

                                <th
                                    class="text-end">

                                    Rp
                                    <?= number_format(
                                        $total,
                                        2,
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


    <!-- =========================================================
         CATATAN
         ========================================================= -->

    <div class="alert alert-warning mt-3 mb-0">

        <i class="fas fa-info-circle"></i>

        <strong>Catatan test:</strong>

        pembayaran dibagi berdasarkan proporsi
        <strong>subtotal setiap detail terhadap total
            subtotal seluruh detail transaksi</strong>.
        Setelah itu hanya detail dari kategori yang dipilih
        yang dijumlahkan.

    </div>

</div>
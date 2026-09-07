<div class="container-fluid py-4">

    <!-- =========================================================
         HEADER
         ========================================================= -->

    <div class="card mb-3">

        <div class="card-header">

            <div class="d-flex justify-content-between align-items-center">

                <div>

                    <h5 class="mb-1">
                        <i class="fas fa-chart-bar"></i>
                        Laporan Penjualan
                    </h5>

                    <small class="text-muted">
                        Berdasarkan pembayaran aktual per tanggal
                    </small>

                </div>

            </div>

        </div>


        <div class="card-body">

            <!-- =================================================
                 FILTER TANGGAL
                 ================================================= -->

            <form
                method="get"
                class="row g-3 align-items-end">

                <div class="col-md-3">

                    <label
                        for="tanggal_mulai"
                        class="form-label fw-semibold">

                        Dari Tanggal

                    </label>

                    <input
                        type="date"
                        id="tanggal_mulai"
                        name="tanggal_mulai"
                        value="<?= esc($tanggal_mulai) ?>"
                        class="form-control">

                </div>


                <div class="col-md-3">

                    <label
                        for="tanggal_sampai"
                        class="form-label fw-semibold">

                        Sampai Tanggal

                    </label>

                    <input
                        type="date"
                        id="tanggal_sampai"
                        name="tanggal_sampai"
                        value="<?= esc($tanggal_sampai) ?>"
                        class="form-control">

                </div>


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
         RINGKASAN TOTAL PER KATEGORI
         ========================================================= -->

    <div class="card mb-3">

        <div class="card-header">

            <strong>
                Total Periode
            </strong>

        </div>

        <div class="card-body">

            <div class="row g-2">

                <?php foreach (
                    $kategoriRows
                    as $kategori
                ): ?>

                    <?php
                    $kategoriId =
                        (int) $kategori['id'];

                    $totalKategori =
                        $totalPerKategori[$kategoriId] ?? 0;
                    ?>

                    <div class="col-6 col-md-4 col-lg-3">

                        <div class="border rounded p-3 h-100">

                            <div class="small text-muted">

                                <?= esc(
                                    $kategori['nama']
                                ) ?>

                            </div>

                            <div
                                class="fw-bold fs-5">

                                Rp
                                <?= number_format(
                                    $totalKategori,
                                    2,
                                    ',',
                                    '.'
                                ) ?>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>


                <!-- GRAND TOTAL -->

                <div class="col-12 mt-2">

                    <div class="alert alert-success mb-0">

                        <div class="small">
                            Total Seluruh Kategori
                        </div>

                        <div class="fs-4 fw-bold">

                            Rp
                            <?= number_format(
                                $grandTotal,
                                2,
                                ',',
                                '.'
                            ) ?>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         TABEL UTAMA
         ========================================================= -->

    <div class="card">

        <div class="card-header">

            <strong>
                Rekap Harian
            </strong>

        </div>


        <div class="card-body p-0">

            <div class="table-responsive">

                <table
                    class="table
                           table-bordered
                           table-hover
                           table-sm
                           mb-0">

                    <thead class="table-light">

                        <tr>

                            <th
                                class="text-center"
                                style="width: 120px;">

                                Tanggal

                            </th>


                            <?php foreach (
                                $kategoriRows
                                as $kategori
                            ): ?>

                                <th
                                    class="text-end"
                                    style="min-width:150px;">

                                    <?= esc(
                                        $kategori['nama']
                                    ) ?>

                                </th>

                            <?php endforeach; ?>


                            <th
                                class="text-end"
                                style="min-width:160px;">

                                Total

                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                            empty($hasilPerTanggal)
                        ): ?>

                            <tr>

                                <td
                                    colspan="<?= count($kategoriRows) + 2 ?>"
                                    class="text-center
                                       text-muted
                                       py-5">

                                    <i
                                        class="fas fa-inbox fa-2x mb-2 d-block">
                                    </i>

                                    Tidak ada pembayaran
                                    pada periode tersebut.

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach (
                                $hasilPerTanggal
                                as $tanggal => $kategoriData
                            ): ?>

                                <?php
                                $totalHari =
                                    0;
                                ?>

                                <tr>

                                    <td
                                        class="fw-semibold">

                                        <?= date(
                                            'd-m-Y',
                                            strtotime(
                                                $tanggal
                                            )
                                        ) ?>

                                    </td>


                                    <?php foreach (
                                        $kategoriRows
                                        as $kategori
                                    ): ?>

                                        <?php
                                        $kategoriId =
                                            (int) $kategori['id'];

                                        $nilai =
                                            $kategoriData[$kategoriId] ?? 0;

                                        $totalHari +=
                                            (float) $nilai;
                                        ?>

                                        <td class="text-end">

                                            <?php if (
                                                $nilai != 0
                                            ): ?>

                                                Rp
                                                <?= number_format(
                                                    $nilai,
                                                    2,
                                                    ',',
                                                    '.'
                                                ) ?>

                                            <?php else: ?>

                                                <span
                                                    class="text-muted">

                                                    -

                                                </span>

                                            <?php endif; ?>

                                        </td>

                                    <?php endforeach; ?>


                                    <td
                                        class="text-end fw-bold">

                                        Rp
                                        <?= number_format(
                                            $totalHari,
                                            2,
                                            ',',
                                            '.'
                                        ) ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>


                    <!-- =================================================
                         FOOTER TOTAL
                         ================================================= -->

                    <?php if (
                        !empty($hasilPerTanggal)
                    ): ?>

                        <tfoot class="table-light">

                            <tr>

                                <th>
                                    TOTAL
                                </th>


                                <?php foreach (
                                    $kategoriRows
                                    as $kategori
                                ): ?>

                                    <?php
                                    $kategoriId =
                                        (int) $kategori['id'];

                                    $nilai =
                                        $totalPerKategori[$kategoriId] ?? 0;
                                    ?>

                                    <th
                                        class="text-end">

                                        Rp
                                        <?= number_format(
                                            $nilai,
                                            2,
                                            ',',
                                            '.'
                                        ) ?>

                                    </th>

                                <?php endforeach; ?>


                                <th
                                    class="text-end">

                                    Rp
                                    <?= number_format(
                                        $grandTotal,
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

    <div class="alert alert-info mt-3">

        <i class="fas fa-info-circle"></i>

        Nilai setiap kategori merupakan bagian dari
        <strong>pembayaran yang benar-benar diterima pada tanggal tersebut</strong>.
        Pembayaran transaksi dialokasikan berdasarkan proporsi
        subtotal setiap detail terhadap seluruh subtotal detail
        transaksi.

    </div>

</div>
<div class="container-fluid py-4">

    <!-- =========================================================
         HEADER
         ========================================================= -->

    <div class="card mb-3">

        <div class="card-header">

            <h5 class="mb-1">
                <i class="fas fa-chart-bar"></i>
                Pemasukan Harian
            </h5>

            <small class="text-muted">
                Rekap pemasukan berdasarkan tanggal pembayaran
            </small>

        </div>

        <div class="card-body">

            <form
                method="get"
                class="row g-3 align-items-end">

                <div class="col-md-6">

                    <label
                        for="dateRangePemasukan"
                        class="form-label fw-semibold">

                        Periode

                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="fas fa-calendar-alt"></i>
                        </span>

                        <input
                            type="text"
                            id="dateRangePemasukan"
                            class="form-control"
                            placeholder="Pilih rentang tanggal"
                            autocomplete="off"
                            readonly>

                    </div>

                    <input
                        type="hidden"
                        id="tanggal_mulai"
                        name="tanggal_mulai"
                        value="<?= esc($tanggal_mulai) ?>">

                    <input
                        type="hidden"
                        id="tanggal_sampai"
                        name="tanggal_sampai"
                        value="<?= esc($tanggal_sampai) ?>">

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
         RINGKASAN
         ========================================================= -->

    <div class="card mb-3">

        <div class="card-header">

            <strong>
                Ringkasan Periode
            </strong>

        </div>


        <div class="card-body">

            <div class="row g-2">

                <!-- TOTAL PEMASUKAN -->

                <div class="col-md-4">

                    <div class="card border-success h-100">

                        <div class="card-body">

                            <div class="small text-muted">
                                Total Pemasukan
                            </div>

                            <div
                                class="fs-3 fw-bold text-success">

                                Rp
                                <?= number_format(
                                    $totalPeriode['total'] ?? 0,
                                    0,
                                    ',',
                                    '.'
                                ) ?>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- JUMLAH TRANSAKSI -->

                <div class="col-md-4">

                    <div class="card border-primary h-100">

                        <div class="card-body">

                            <div class="small text-muted">
                                Transaksi yang Membayar
                            </div>

                            <div
                                class="fs-3 fw-bold text-primary">

                                <?= number_format(
                                    $totalPeriode['jumlah_transaksi'] ?? 0,
                                    0,
                                    ',',
                                    '.'
                                ) ?>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- JUMLAH PEMBAYARAN -->

                <div class="col-md-4">

                    <div class="card border-secondary h-100">

                        <div class="card-body">

                            <div class="small text-muted">
                                Jumlah Pembayaran
                            </div>

                            <div
                                class="fs-3 fw-bold text-secondary">

                                <?= number_format(
                                    $totalPeriode['jumlah_pembayaran'] ?? 0,
                                    0,
                                    ',',
                                    '.'
                                ) ?>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         TABEL PEMASUKAN HARIAN
         ========================================================= -->

    <div class="card">

        <div class="card-header">

            <div
                class="d-flex
                       justify-content-between
                       align-items-center">

                <div>

                    <strong>
                        Rekap Pemasukan Harian
                    </strong>

                    <div class="small text-muted">

                        Pembayaran dialokasikan berdasarkan
                        proporsi subtotal setiap detail transaksi.

                    </div>

                </div>

                <span class="badge bg-primary">

                    <?= esc($tanggal_mulai) ?>
                    s/d
                    <?= esc($tanggal_sampai) ?>

                </span>

            </div>

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

                            <!-- TANGGAL -->

                            <th
                                class="text-center"
                                style="min-width:110px;">

                                Tanggal

                            </th>

                            <th
                                class="text-end"
                                style="min-width:150px;">

                                QRIS

                            </th>

                            <th
                                class="text-end"
                                style="min-width:150px;">

                                Transfer

                            </th>

                            <th
                                class="text-end"
                                style="min-width:170px;">

                                QRIS + Transfer

                            </th>


                            <!-- KATEGORI -->

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


                            <!-- GANTI / EDIT -->

                            <th
                                class="text-end"
                                style="min-width:160px;">

                                Ganti/Edit

                            </th>


                            <!-- TOTAL -->

                            <th
                                class="text-end"
                                style="min-width:160px;">

                                Total

                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                            empty($hasil)
                        ): ?>

                            <tr>

                                <td
                                    colspan="<?= count($kategoriRows) + 6 ?>"
                                    class="text-center
                                       text-muted
                                       py-5">

                                    <i
                                        class="fas fa-inbox fa-2x mb-2 d-block">
                                    </i>

                                    Tidak ada pemasukan
                                    pada periode tersebut.

                                </td>

                            </tr>

                        <?php else: ?>


                            <?php foreach (
                                $hasil
                                as $tanggal => $dataHari
                            ): ?>

                                <?php
                                $totalHari =
                                    (float) (
                                        $dataHari['total']
                                        ?? 0
                                    );
                                ?>

                                <tr>

                                    <!-- TANGGAL -->

                                    <td
                                        class="fw-semibold
                                           text-center">

                                        <?= date(
                                            'd-m-Y',
                                            strtotime(
                                                $tanggal
                                            )
                                        ) ?>

                                    </td>

                                    <?php
                                    $nonTunai =
                                        $nonTunaiPerTanggal[$tanggal] ?? [
                                            'qris' => 0,
                                            'transfer' => 0,
                                            'total_non_tunai' => 0,
                                        ];
                                    ?>

                                    <td class="text-end">

                                        <?php if (
                                            abs((float) $nonTunai['qris']) > 0.000001
                                        ): ?>

                                            Rp <?= number_format(
                                                    $nonTunai['qris'],
                                                    0,
                                                    ',',
                                                    '.'
                                                ) ?>

                                        <?php else: ?>

                                            <span class="text-muted">-</span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="text-end">

                                        <?php if (
                                            abs((float) $nonTunai['transfer']) > 0.000001
                                        ): ?>

                                            Rp <?= number_format(
                                                    $nonTunai['transfer'],
                                                    0,
                                                    ',',
                                                    '.'
                                                ) ?>

                                        <?php else: ?>

                                            <span class="text-muted">-</span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="text-end fw-semibold">

                                        <?php if (
                                            abs((float) $nonTunai['total_non_tunai']) > 0.000001
                                        ): ?>

                                            Rp <?= number_format(
                                                    $nonTunai['total_non_tunai'],
                                                    0,
                                                    ',',
                                                    '.'
                                                ) ?>

                                        <?php else: ?>

                                            <span class="text-muted">-</span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- SEMUA KATEGORI -->

                                    <?php foreach (
                                        $kategoriRows
                                        as $kategori
                                    ): ?>

                                        <?php

                                        $kategoriId =
                                            (int) $kategori['id'];

                                        $key =
                                            'kategori_' .
                                            $kategoriId;

                                        $nilai =
                                            (float) (
                                                $dataHari[$key] ?? 0
                                            );

                                        ?>

                                        <td
                                            class="text-end">

                                            <?php if (
                                                abs($nilai) > 0.000001
                                            ): ?>

                                                Rp
                                                <?= number_format(
                                                    $nilai,
                                                    0,
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


                                    <!-- GANTI / EDIT -->

                                    <?php
                                    $gantiEdit =
                                        (float) (
                                            $dataHari['ganti_edit'] ?? 0
                                        );
                                    ?>

                                    <td
                                        class="text-end">

                                        <?php if (
                                            abs($gantiEdit)
                                            > 0.000001
                                        ): ?>

                                            Rp
                                            <?= number_format(
                                                $gantiEdit,
                                                0,
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


                                    <!-- TOTAL -->

                                    <td
                                        class="text-end
                                           fw-bold">

                                        Rp
                                        <?= number_format(
                                            $totalHari,
                                            0,
                                            ',',
                                            '.'
                                        ) ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>


                    <!-- =================================================
                         TOTAL PERIODE
                         ================================================= -->

                    <?php if (
                        !empty($hasil)
                    ): ?>

                        <tfoot
                            class="table-light">

                            <tr>

                                <th
                                    class="text-center">

                                    TOTAL

                                </th>

                                <th class="text-end">

                                    Rp
                                    <?= number_format(
                                        array_sum(
                                            array_map(
                                                static function ($row) {
                                                    return (float) ($row['qris'] ?? 0);
                                                },
                                                $nonTunaiPerTanggal
                                            )
                                        ),
                                        0,
                                        ',',
                                        '.'
                                    ) ?>

                                </th>

                                <th class="text-end">

                                    Rp
                                    <?= number_format(
                                        array_sum(
                                            array_map(
                                                static function ($row) {
                                                    return (float) ($row['transfer'] ?? 0);
                                                },
                                                $nonTunaiPerTanggal
                                            )
                                        ),
                                        0,
                                        ',',
                                        '.'
                                    ) ?>

                                </th>

                                <th class="text-end">

                                    Rp
                                    <?= number_format(
                                        array_sum(
                                            array_map(
                                                static function ($row) {
                                                    return (float) ($row['total_non_tunai'] ?? 0);
                                                },
                                                $nonTunaiPerTanggal
                                            )
                                        ),
                                        0,
                                        ',',
                                        '.'
                                    ) ?>

                                </th>


                                <!-- TOTAL MASING-MASING KATEGORI -->

                                <?php foreach (
                                    $kategoriRows
                                    as $kategori
                                ): ?>

                                    <?php

                                    $kategoriId =
                                        (int) $kategori['id'];

                                    $key =
                                        'kategori_' .
                                        $kategoriId;

                                    $nilai =
                                        (float) (
                                            $totalPeriode[$key] ?? 0
                                        );

                                    ?>

                                    <th
                                        class="text-end">

                                        Rp
                                        <?= number_format(
                                            $nilai,
                                            0,
                                            ',',
                                            '.'
                                        ) ?>

                                    </th>

                                <?php endforeach; ?>


                                <!-- TOTAL GANTI / EDIT -->

                                <th
                                    class="text-end">

                                    Rp
                                    <?= number_format(
                                        $totalPeriode['ganti_edit'] ?? 0,
                                        0,
                                        ',',
                                        '.'
                                    ) ?>

                                </th>


                                <!-- GRAND TOTAL -->

                                <th
                                    class="text-end">

                                    Rp
                                    <?= number_format(
                                        $totalPeriode['total'] ?? 0,
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


    <!-- =========================================================
         KETERANGAN
         ========================================================= -->

    <div class="alert alert-info mt-3 mb-0">

        <i class="fas fa-info-circle"></i>

        <strong>Pemasukan Harian</strong>
        menggunakan tanggal pada tabel
        <strong>pembayaran</strong>.

        Jadi apabila sebuah transaksi membayar DP hari ini
        dan melunasi beberapa hari kemudian, masing-masing
        pembayaran masuk ke tanggal saat pembayaran tersebut
        benar-benar diterima.

    </div>

</div>


<!-- =========================================================
     DATE RANGE SELECTOR
     ========================================================= -->

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css">

<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/litepicker.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const rangeInput =
            document.getElementById('dateRangePemasukan');

        const mulaiInput =
            document.getElementById('tanggal_mulai');

        const sampaiInput =
            document.getElementById('tanggal_sampai');

        if (!rangeInput || !mulaiInput || !sampaiInput) {
            return;
        }

        function formatTampilan(tanggal) {
            const parts = String(tanggal).split('-');

            if (parts.length !== 3) {
                return tanggal;
            }

            return parts[2] + '-' + parts[1] + '-' + parts[0];
        }

        function setTextRange() {
            if (!mulaiInput.value || !sampaiInput.value) {
                rangeInput.value = '';
                return;
            }

            rangeInput.value =
                formatTampilan(mulaiInput.value) +
                ' s/d ' +
                formatTampilan(sampaiInput.value);
        }

        setTextRange();

        const picker = new Litepicker({

            element: rangeInput,

            singleMode: false,

            numberOfMonths: 2,

            numberOfColumns: 2,

            format: 'YYYY-MM-DD',

            autoApply: true,

            setup: function(picker) {

                picker.on(
                    'selected',
                    function(date1, date2) {

                        if (!date1 || !date2) {
                            return;
                        }

                        mulaiInput.value =
                            date1.format('YYYY-MM-DD');

                        sampaiInput.value =
                            date2.format('YYYY-MM-DD');

                        setTextRange();
                    }
                );
            }
        });

        if (mulaiInput.value && sampaiInput.value) {

            picker.setDateRange(
                mulaiInput.value,
                sampaiInput.value
            );
        }

    });
</script>
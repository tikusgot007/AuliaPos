<style>
    .inputCustom {
        width: 75px;
    }

    .color-box {
        border-radius: 4px;
        border: 1px solid #dee2e6;
    }

    .card-header.bg-gradient-secondary {
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
    }

    .table-ukuran th {
        font-size: 0.85rem;
    }

    .table-ukuran td {
        font-size: 1rem;
        vertical-align: middle;
    }
</style>

<div class="container-fluid">
    <!-- Page Heading -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
            <i class="fas fa-ruler-combined me-2"></i> Skala Ukuran
        </h4>
        <a href="<?= base_url('/kasir') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Kembali ke Kasir
        </a>
    </div>

    <div class="row">
        <!-- Kolom Kiri: Tabel Skala Ukuran -->
        <div class="col-lg-8 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 bg-secondary text-white">
                    <h6 class="m-0 font-weight-bold">
                        <i class="fas fa-table me-2"></i> Perhitungan Skala Ukuran
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="skala" class="form-label fw-bold">Skala:</label>
                        <input type="number" id="skala" value="2500"
                            oninput="ubahSkala()"
                            style="width: 120px;" step="100"
                            class="form-control form-control-sm d-inline-block">
                        <small class="text-muted ms-2">(Masukkan angka skala yang diinginkan)</small>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-hover table-ukuran" id="tabelUkuran">
                            <thead class="table-secondary">
                                <tr>
                                    <th width="40">#</th>
                                    <th>Ukuran</th>
                                    <th class="text-center">Lebar (mm)</th>
                                    <th class="text-center">Panjang (mm)</th>
                                    <th class="text-end">Harga</th>
                                    <th class="text-center" id="headerS">Skala x 2500</th>
                                </tr>
                            </thead>
                            <tbody id="bodyUkuran">
                                <!-- Row Custom -->
                                <tr data-custom>
                                    <td class="text-center">1</td>
                                    <td id="nama">Custom</td>
                                    <td>
                                        <input class="inputCustom form-control form-control-sm"
                                            type="number" id="lebar" value="1"
                                            oninput="ubahSkala()" step="10" min="1">
                                    </td>
                                    <td>
                                        <input class="inputCustom form-control form-control-sm"
                                            type="number" id="panjang" value="1"
                                            oninput="ubahSkala()" step="10" min="1">
                                    </td>
                                    <td id="harga" class="text-end">____</td>
                                    <td id="hasilSkalaCustom" class="text-center">-</td>
                                </tr>

                                <!-- Data dari database -->
                                <?php
                                $nomor = 2;
                                $skala = 2500;
                                foreach ($ukuran_foto as $ukuran):
                                    $lebar_asli = $ukuran['lebar'];
                                    $panjang_asli = $ukuran['panjang'];

                                    // 🔥 CEK: Jika panjang atau lebar 0, skip atau gunakan nilai default
                                    if ($panjang_asli <= 0 || $lebar_asli <= 0) {
                                        continue; // Skip data yang tidak valid
                                    }

                                    $ukuran_baru = round(($skala / $panjang_asli) * $lebar_asli);
                                ?>
                                    <tr>
                                        <td class="text-center"><?= $nomor++ ?></td>
                                        <td><?= esc($ukuran['nama']) ?></td>
                                        <td class="text-center"><?= number_format($ukuran['lebar'], 0, ',', '.') ?></td>
                                        <td class="text-center"><?= number_format($ukuran['panjang'], 0, ',', '.') ?></td>
                                        <td class="text-end">Rp <?= number_format($ukuran['harga'], 0, ',', '.') ?></td>
                                        <td class="text-center" id="hasilSkala<?= $nomor - 1 ?>"><?= $ukuran_baru ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (empty($ukuran_foto)): ?>
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Belum ada data produk dengan kategori Foto/Studio.
                            Silakan tambahkan produk dengan kategori ID 16 dan isi kolom panjang & lebar.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Kolom Kanan: Kode Warna -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 bg-secondary text-white">
                    <h6 class="m-0 font-weight-bold">
                        <i class="fas fa-palette me-2"></i> Kode Warna
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                            <thead class="table-secondary">
                                <tr>
                                    <th>Warna</th>
                                    <th>Nama Warna</th>
                                    <th>Kode Warna</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="color-box" style="background-color: #cc0000; width: 25px; height: 25px;"></div>
                                    </td>
                                    <td style="color: #cc0000; font-weight: bold;">MERAH</td>
                                    <td>#cc0000</td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="color-box" style="background-color: #0066cc; width: 25px; height: 25px;"></div>
                                    </td>
                                    <td style="color: #0066cc; font-weight: bold;">BIRU</td>
                                    <td>#0066cc</td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="color-box" style="background-color: #339900; width: 25px; height: 25px;"></div>
                                    </td>
                                    <td style="color: #339900; font-weight: bold;">HIJAU</td>
                                    <td>#339900</td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="color-box" style="background-color: #ffcc00; width: 25px; height: 25px;"></div>
                                    </td>
                                    <td style="color: #ffcc00; font-weight: bold;">KUNING</td>
                                    <td>#ffcc00</td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="color-box" style="background-color: #f55c10; width: 25px; height: 25px;"></div>
                                    </td>
                                    <td style="color: #f55c10; font-weight: bold;">ORANGE</td>
                                    <td>#f55c10</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>



<script>
    let daftarHarga = [];
    let hasilTerakhir = null;

    // Ambil daftar barang dari server
    fetch('<?= base_url('ukuran/getList') ?>')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                daftarHarga = data.barangList;

                ubahSkala(); // Panggil sekali setelah data dimuat
            }
        })
        .catch(err => console.error('Gagal memuat daftar harga:', err));

    function ubahSkala() {
        var skalaBaru = parseInt(document.getElementById('skala').value) || 2500;

        // Update header skala
        var headerS = document.getElementById('headerS');
        if (headerS) headerS.textContent = "Skala x " + skalaBaru;

        // Update hasil skala untuk setiap baris dari database
        <?php
        $nomor = 2;
        foreach ($ukuran_foto as $ukuran):
            $nomor++;
        ?>
            var lebarAsli<?= $nomor ?> = <?= $ukuran['lebar'] ?>;
            var panjangAsli<?= $nomor ?> = <?= $ukuran['panjang'] ?>;
            var ukuranBaru<?= $nomor ?> = Math.ceil((skalaBaru / panjangAsli<?= $nomor ?>) * lebarAsli<?= $nomor ?>);
            var el<?= $nomor ?> = document.getElementById('hasilSkala<?= $nomor - 1 ?>');
            if (el<?= $nomor ?>) el<?= $nomor ?>.textContent = ukuranBaru<?= $nomor ?>;
        <?php endforeach; ?>

        // Hitung custom
        var lebarCustom = parseInt(document.getElementById('lebar').value) || 1;
        var panjangCustom = parseInt(document.getElementById('panjang').value) || 1;

        if (lebarCustom > 0 && panjangCustom > 0) {
            var ukuranBaruCustom = Math.ceil((skalaBaru / panjangCustom) * lebarCustom);
            var hasilSkalaCustom = document.getElementById('hasilSkalaCustom');
            if (hasilSkalaCustom) hasilSkalaCustom.textContent = ukuranBaruCustom;

            // Hitung harga custom
            if (daftarHarga.length > 0) {
                hasilTerakhir = hitungHarga(lebarCustom, panjangCustom, daftarHarga);
                var hargaPerMM2 = hasilTerakhir.totalHarga.toLocaleString();
                var namaEl = document.getElementById('nama');
                var hargaEl = document.getElementById('harga');
                if (namaEl) namaEl.textContent = hasilTerakhir.namaCustom;
                if (hargaEl) hargaEl.textContent = "Rp " + hargaPerMM2;
            }
        } else {
            var hasilSkalaCustom = document.getElementById('hasilSkalaCustom');
            var hargaEl = document.getElementById('harga');
            if (hasilSkalaCustom) hasilSkalaCustom.textContent = '';
            if (hargaEl) hargaEl.textContent = '____';
        }
    }

    function hitungHarga(lebar, panjang, daftarHarga) {
        const luas = lebar * panjang;
        const namaCustom = `${lebar}x${panjang}`;

        // Pastikan semua item punya harga_permm
        daftarHarga = daftarHarga.map(item => ({
            ...item,
            luas: parseFloat(item.luas) || 0,
            harga: parseFloat(item.harga_barang) || 0,
            harga_permm: (parseFloat(item.harga_barang) || 0) / (parseFloat(item.luas) || 1)
        }));

        // Cari ukuran referensi terdekat
        let terdekat = daftarHarga[0];
        let selisihMin = Math.abs(luas - terdekat.luas);

        for (const item of daftarHarga) {
            const selisih = Math.abs(luas - item.luas);
            if (selisih < selisihMin) {
                selisihMin = selisih;
                terdekat = item;
            }
        }

        // Abaikan data yang terlalu kecil (luas < 1000 mm²)
        const kandidatValid = daftarHarga.filter(item => item.luas > 1000 && item.harga_permm > 0);

        // Urutkan berdasarkan kedekatan luas
        const sorted = kandidatValid
            .map(item => ({
                ...item,
                selisih: Math.abs(luas - item.luas)
            }))
            .sort((a, b) => a.selisih - b.selisih);

        // Ambil 3 terdekat
        const sekitar = sorted.slice(0, 3);

        if (sekitar.length === 0) {
            return {
                namaCustom,
                luas,
                totalHarga: 0,
                hargaPerMm: 0,
                referensi: 'Data tidak tersedia'
            };
        }

        // Hitung rata-rata harga per mm2
        const totalHargaPerMm = sekitar.reduce((sum, i) => sum + i.harga_permm, 0);
        const avgHargaPerMm = totalHargaPerMm / sekitar.length;

        // Total harga
        let totalHargaMentah = luas * avgHargaPerMm;

        // Tentukan kelipatan pembulatan
        let kelipatan;
        if (totalHargaMentah < 2000) {
            kelipatan = 100;
        } else if (totalHargaMentah < 10000) {
            kelipatan = 500;
        } else if (totalHargaMentah < 50000) {
            kelipatan = 1000;
        } else {
            kelipatan = 5000;
        }

        // Lakukan pembulatan ke atas sesuai kelipatan
        const totalHarga = Math.ceil(totalHargaMentah / kelipatan) * kelipatan;

        return {
            namaCustom,
            luas,
            totalHarga,
            hargaPerMm: avgHargaPerMm,
            referensi: sekitar.map(i => i.nama_barang).join(', ')
        };
    }

    // Panggil ubahSkala pertama kali setelah DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(ubahSkala, 500);
    });
</script>
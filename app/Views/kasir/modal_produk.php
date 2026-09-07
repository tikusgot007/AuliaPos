<!-- ========================================== -->
<!-- MODAL PRODUK: UBAH HARGA + CUSTOM + MANUAL -->
<!-- Reusable by Kasir and Edit Transaksi -->
<!-- ========================================== -->

<!-- MODAL UBAH HARGA SEBELUM MASUK KERANJANG -->
<div class="modal fade" id="modalUbahHarga" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Ubah Harga</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Produk</label>
                    <p class="fw-bold" id="ubahHargaNama">-</p>
                </div>
                <div class="mb-3">
                    <label class="form-label">Harga Default</label>
                    <p id="ubahHargaDefault" class="text-muted">Rp 0</p>
                </div>
                <div class="mb-3">
                    <label class="form-label">Harga Baru (Rp) *</label>
                    <input type="number" class="form-control" id="ubahHargaInput" placeholder="Masukkan harga baru" min="0" step="100">
                </div>
                <div class="mb-3">
                    <label class="form-label">Jumlah</label>
                    <input type="number" class="form-control" id="ubahHargaJumlah"
                        value="1" min="1"
                        onkeydown="if(event.key === 'Enter'){ event.preventDefault(); document.querySelector('#modalUbahHarga .btn-primary').click(); }">

                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" onclick="tambahDenganHargaBaru()">
                    <i class="fas fa-plus"></i> Tambahkan
                </button>
            </div>
        </div>
    </div>
</div>
<!-- MODAL UKURAN CUSTOM -->
<div class="modal fade" id="modalCustomSize" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-vector-square"></i> Hitung Harga Ukuran Custom</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Masukkan ukuran dalam <strong>milimeter (mm)</strong>.
                    Harga akan dihitung berdasarkan referensi produk cetak terdekat.
                </div>

                <div class="row">
                    <div class="col-md-5">
                        <div class="mb-3">
                            <label class="form-label">Lebar (mm) *</label>
                            <input type="number" class="form-control" id="customLebar"
                                placeholder="Contoh: 100" min="1" step="1"
                                oninput="hitungCustomSize()">
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="mb-3">
                            <label class="form-label">Panjang (mm) *</label>
                            <input type="number" class="form-control" id="customPanjang"
                                placeholder="Contoh: 150" min="1" step="1"
                                oninput="hitungCustomSize()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label class="form-label">Jumlah</label>
                            <input type="number" class="form-control" id="customQty"
                                value="1" min="1" oninput="hitungCustomSize()">
                        </div>
                    </div>
                </div>

                <div class="border-top pt-3 mt-3">
                    <h6>Hasil Perhitungan</h6>
                    <div id="hasilCustom" class="mt-2">
                        <div class="row">
                            <div class="col-md-4">
                                <p><strong>Luas:</strong> <span id="customLuas">0</span> mm²</p>
                            </div>
                            <div class="col-md-4">
                                <p><strong>Harga/mm²:</strong> <span id="customHargaPerMm">Rp 0</span></p>
                            </div>
                            <div class="col-md-4">
                                <p><strong>Referensi:</strong> <span id="customReferensi">-</span></p>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Total Harga:</strong> <span id="customTotal" class="text-success fw-bold">Rp 0</span></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Nama Custom:</strong> <span id="customNama">-</span></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-success" onclick="tambahCustomSize()">
                    <i class="fas fa-plus"></i> Tambahkan ke Keranjang
                </button>
            </div>
        </div>
    </div>
</div>
<!-- MODAL INPUT MANUAL TOTAL -->
<div class="modal fade" id="modalManualInput" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-pencil-alt"></i> Input Penjualan Manual</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Gunakan fitur ini jika Anda lupa detail item yang dijual. Cukup masukkan total harga yang diterima dari customer.
                </div>
                <div class="mb-3">
                    <label class="form-label">Kategori</label>
                    <select class="form-control" id="manualKategori" onchange="ubahNamaManualOtomatis()">
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($kategori as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= $k['nama'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Nama Item / Deskripsi</label>
                    <input type="text" class="form-control" id="manualNama" placeholder="Contoh: ATK Campuran, Paket Fotokopi, dll" value="ATK Campuran" disabled>
                </div>
                <div class="mb-3">
    <label class="form-label">Total Harga (Rp) *</label>
    <input type="text" inputmode="numeric" class="form-control" id="manualTotal" placeholder="Masukkan total harga" required>
</div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-warning" onclick="tambahManual()">
                    <i class="fas fa-plus"></i> Tambahkan ke Keranjang
                </button>
            </div>
        </div>
    </div>
</div>
<script>
// Format angka jadi "1.000.000" saat mengetik
document.getElementById('manualTotal').addEventListener('input', function (e) {
    let value = e.target.value.replace(/\D/g, ''); // buang semua selain digit
    if (value === '') {
        e.target.value = '';
        return;
    }
    e.target.value = new Intl.NumberFormat('id-ID').format(value);
});

// Auto-focus + reset saat modal dibuka
document.getElementById('modalManualInput').addEventListener('shown.bs.modal', function () {
    const inputTotal = document.getElementById('manualTotal');
    inputTotal.value = '';
    inputTotal.focus();
});

// Enter -> langsung tambahManual()
document.getElementById('manualTotal').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        tambahManual();
    }
});

// Helper: ambil angka asli (tanpa titik) dari input
function getManualTotalValue() {
    return parseInt(document.getElementById('manualTotal').value.replace(/\D/g, ''), 10) || 0;
}
</script>

<script>
    // ================================================================
    // STATE MODAL PRODUK
    // ================================================================
    let produkDipilih = null;
    let hasilCustom = null;
    let daftarProdukRef = [];

    // ================================================================
    // REFERENSI PRODUK CETAK (UNTUK CUSTOM SIZE)
    // ================================================================
    function loadProdukReferensi() {
        $.ajax({
            url: '<?= base_url('/api/get-produk-cetak') ?>',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    daftarProdukRef = Array.isArray(response.data) ? response.data : [];
                    console.log('✅ Produk referensi loaded:', daftarProdukRef.length);
                } else {
                    showToast('Gagal memuat produk referensi.', 'danger');
                }
            },
            error: function() {
                showToast('Gagal memuat produk referensi.', 'danger');
            }
        });
    }

    // ================================================================
    // MODAL UBAH HARGA
    // ================================================================
    // ================================================================

    // -----------------------------------------------------------------
    // 8.1. Tampilkan Modal Ubah Harga
    // -----------------------------------------------------------------
    function tampilkanModalUbahHarga(id, nama, harga, kategoriId) {
        produkDipilih = {
            id: id,
            nama: nama,
            harga: harga,
            kategori_id: kategoriId
        };

        document.getElementById('ubahHargaNama').textContent = nama;
        document.getElementById('ubahHargaDefault').textContent = formatRupiah(harga);
        document.getElementById('ubahHargaInput').value = harga;
        document.getElementById('ubahHargaJumlah').value = 1;

        $('#modalUbahHarga').modal('show');
        setTimeout(function() {
            const qtyInput = document.getElementById('ubahHargaJumlah');
            if (qtyInput) {
                qtyInput.focus();
                qtyInput.select();
            }
        }, 500);
    }

    // -----------------------------------------------------------------
    // 8.2. Tambah Dengan Harga Baru
    // -----------------------------------------------------------------
    function tambahDenganHargaBaru() {
        if (!produkDipilih) return;

        const hargaBaru = parseInt(
            document.getElementById('ubahHargaInput').value,
            10
        );

        const jumlah = parseInt(
            document.getElementById('ubahHargaJumlah').value,
            10
        ) || 1;

        if (!hargaBaru || hargaBaru <= 0) {
            showToast('Harga harus lebih dari 0.', 'warning');
            return;
        }

        if (jumlah <= 0) {
            showToast('Jumlah harus lebih dari 0.', 'warning');
            return;
        }

        $('#modalUbahHarga').modal('hide');

        tambahItemKeCart({
            id: String(produkDipilih.id),
            produk_id: Number(produkDipilih.id),
            kategori_id: Number(produkDipilih.kategori_id),
            nama: produkDipilih.nama,
            harga: hargaBaru,
            jumlah: jumlah,
            satuan: produkDipilih.satuan || 'pcs',
            is_custom: false,
            is_banner: false,
            is_manual: false,
            is_cetak: Number(produkDipilih.kategori_id) === 16
        });
    }


    // ================================================================
    // ================================================================
    // MODAL MANUAL INPUT
    // ================================================================
    // ================================================================

    // -----------------------------------------------------------------
    // 9.1. Tampilkan Modal Manual Input
    // -----------------------------------------------------------------
    function showManualInput() {
        $('#modalManualInput').modal('show');
    }

    // -----------------------------------------------------------------
    // 9.2. Tambah Manual ke Keranjang
    // -----------------------------------------------------------------
    function tambahManual() {
        const nama = document.getElementById('manualNama').value.trim();
        const total = getManualTotalValue();
        const kategoriId = parseInt(document.getElementById('manualKategori').value, 10) || 0;

        if (!kategoriId) {
            showToast('Silakan pilih kategori terlebih dahulu.', 'warning');
            document.getElementById('manualKategori').focus();
            return;
        }

        if (!nama) {
            showToast('Nama item harus diisi.', 'warning');
            document.getElementById('manualNama').focus();
            return;
        }

        if (total <= 0) {
            showToast('Total harga harus diisi dan lebih dari 0.', 'warning');
            document.getElementById('manualTotal').focus();
            return;
        }

        showToast('⏳ Mencari atau membuat produk...', 'info');

        $.ajax({
            url: '<?= base_url('/api/cari-atau-buat-produk') ?>',
            type: 'POST',
            data: JSON.stringify({
                nama: nama,
                kategori_id: kategoriId,
                harga_jual: 0
            }),
            contentType: 'application/json',
            dataType: 'json',

            success: function(response) {
                if (response.status !== 'success') {
                    showToast('❌ ' + (response.message || 'Gagal memproses produk.'), 'danger');
                    return;
                }

                const produk = response.produk;

                tambahItemKeCart({
                    id: 'manual_' + Date.now(),
                    produk_id: Number(produk.id),
                    kategori_id: kategoriId,
                    nama: nama,
                    harga: total,
                    jumlah: 1,
                    satuan: 'item',
                    is_manual: true,
                    is_custom: false,
                    is_banner: false,
                    is_edit: false,
                    is_cetak: kategoriId === 16
                });

                if (response.found) {
                    showToast('✅ Produk ditemukan: ' + produk.nama, 'success');
                } else {
                    showToast('✅ ' + response.message, 'success');
                }

                $('#modalManualInput').modal('hide');

                document.getElementById('manualTotal').value = '';
                document.getElementById('manualKategori').value = '';
                document.getElementById('manualNama').value = '';
            },

            error: function(xhr) {
                console.error('Tambah manual:', xhr.responseText);
                showToast('❌ Gagal memproses produk.', 'danger');
            }
        });
    }
    // ================================================================
    // FUNGSI UBAH NAMA MANUAL OTOMATIS (BERDASARKAN KATEGORI)
    // ================================================================

    function ubahNamaManualOtomatis() {
        const kategoriSelect = document.getElementById('manualKategori');
        const namaInput = document.getElementById('manualNama');

        // Ambil teks dari option yang dipilih
        const selectedOption = kategoriSelect.options[kategoriSelect.selectedIndex];
        const kategoriNama = selectedOption ? selectedOption.text : '';

        if (kategoriNama && kategoriNama !== '-- Pilih Kategori --') {
            // Set nama otomatis: "[Nama Kategori] Glondongan"
            namaInput.value = kategoriNama + '*';
        } else {
            // Jika tidak ada kategori dipilih, kosongkan atau set default
            namaInput.value = '';
        }
    }

    // ================================================================
    // RESET MANUAL INPUT (SAAT MODAL DITAMPILKAN)
    // ================================================================

    // Event ketika modal manual input ditampilkan
    $('#modalManualInput').on('show.bs.modal', function() {
        // Reset form
        document.getElementById('manualNama').value = '';
        document.getElementById('manualTotal').value = '';
        document.getElementById('manualKategori').value = '';

        // 🔥 Ambil kategori pertama sebagai default (jika ada)
        const kategoriSelect = document.getElementById('manualKategori');
        if (kategoriSelect.options.length > 1) {
            // Pilih opsi pertama (setelah placeholder)
            kategoriSelect.selectedIndex = 1;
            ubahNamaManualOtomatis();
        }
    });


    // ================================================================
    // ================================================================
    // MODAL UKURAN CUSTOM
    // ================================================================
    // ================================================================

    // -----------------------------------------------------------------
    // 10.1. Tampilkan Modal Custom Size
    // -----------------------------------------------------------------
    function showModalCustomSize() {
        document.getElementById('customLebar').value = '';
        document.getElementById('customPanjang').value = '';
        document.getElementById('customQty').value = '1';
        document.getElementById('customLuas').textContent = '0';
        document.getElementById('customHargaPerMm').textContent = 'Rp 0';
        document.getElementById('customTotal').textContent = 'Rp 0';
        document.getElementById('customReferensi').textContent = '-';
        document.getElementById('customNama').textContent = '-';
        hasilCustom = null;

        if (daftarProdukRef.length === 0) {
            loadProdukReferensi();
        }

        $('#modalCustomSize').modal('show');
    }

    // -----------------------------------------------------------------
    // 10.2. Hitung Custom Size
    // -----------------------------------------------------------------
    function hitungCustomSize() {
        const lebar = parseFloat(document.getElementById('customLebar').value) || 0;
        const panjang = parseFloat(document.getElementById('customPanjang').value) || 0;
        const qty = parseInt(document.getElementById('customQty').value) || 1;

        if (lebar <= 0 || panjang <= 0) {
            document.getElementById('customLuas').textContent = '0';
            document.getElementById('customHargaPerMm').textContent = 'Rp 0';
            document.getElementById('customTotal').textContent = 'Rp 0';
            document.getElementById('customReferensi').textContent = '-';
            document.getElementById('customNama').textContent = '-';
            return;
        }

        const luas = lebar * panjang;
        const result = hitungHargaCustom(lebar, panjang, daftarProdukRef);

        if (result) {
            const subtotal = result.totalHarga * qty;
            document.getElementById('customLuas').textContent = luas.toLocaleString();
            document.getElementById('customHargaPerMm').textContent = formatRupiah(result.hargaPerMm);
            document.getElementById('customTotal').textContent = formatRupiah(subtotal);
            document.getElementById('customReferensi').textContent = result.referensi;
            document.getElementById('customNama').textContent = result.namaCustom;

            hasilCustom = {
                nama: result.namaCustom,
                harga: result.totalHarga,
                subtotal: subtotal,
                qty: qty,
                lebar: lebar,
                panjang: panjang,
            };
        }
    }

    // -----------------------------------------------------------------
    // 10.3. Hitung Harga Custom (Helper)
    // -----------------------------------------------------------------
    function hitungHargaCustom(lebar, panjang, daftarProduk) {
        const luas = lebar * panjang;

        const produkWithPrice = daftarProduk.map(item => {
            const luasProduk = (item.panjang || 0) * (item.lebar || 0);
            return {
                ...item,
                luas: luasProduk,
                harga_permm: luasProduk > 0 ? (item.harga_jual / luasProduk) : 0
            };
        });

        const produkValid = produkWithPrice.filter(item =>
            item.luas > 1000 && item.harga_permm > 0
        );

        if (produkValid.length === 0) {
            return null;
        }

        const sorted = produkValid.map(item => ({
            ...item,
            selisih: Math.abs(luas - item.luas)
        })).sort((a, b) => a.selisih - b.selisih);

        const sekitar = sorted.slice(0, 3);

        const totalHargaPerMm = sekitar.reduce((sum, i) => sum + i.harga_permm, 0);
        const avgHargaPerMm = totalHargaPerMm / sekitar.length;

        let totalHargaMentah = luas * avgHargaPerMm;

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

        const totalHarga = Math.ceil(totalHargaMentah / kelipatan) * kelipatan;

        return {
            namaCustom: `${lebar}x${panjang}`,
            luas: luas,
            totalHarga: totalHarga,
            hargaPerMm: avgHargaPerMm,
            referensi: sekitar.map(i => i.nama).join(', ')
        };
    }

    // -----------------------------------------------------------------
    // 10.4. Tambah Custom Size ke Keranjang
    // -----------------------------------------------------------------
    function tambahCustomSize() {
        if (!hasilCustom) {
            showToast('Hitung dulu ukuran custom sebelum menambahkan!', 'warning');
            return;
        }

        tambahItemKeCart({
            id: 'custom_' + Date.now(),
            produk_id: 4,
            nama: 'Cetak ' + hasilCustom.nama,
            harga: hasilCustom.harga,
            subtotal: hasilCustom.subtotal,
            jumlah: hasilCustom.qty,
            kategori_id: 16,
            satuan: 'mm²',
            is_custom: true,
            is_cetak: true,
            detail: {
                lebar: hasilCustom.lebar,
                panjang: hasilCustom.panjang
            }
        });

        $('#modalCustomSize').modal('hide');
        showToast('Ukuran custom berhasil ditambahkan ke keranjang.', 'success');
    }


    // ================================================================
</script>
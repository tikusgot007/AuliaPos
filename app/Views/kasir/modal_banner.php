<script>
    // Variabel Banner
    let bannerRows = [];
    let bannerRowCounter = 0;
    let totalLuasBanner = 0;
    let HARGA_STANDAR = 22000;
</script>

<!-- ========================================== -->
<!-- MODAL BANNER / CETAK                       -->
<!-- ========================================== -->
<div class="modal fade" id="modalBanner" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">

            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-ruler-combined"></i>
                    Input Banner / Cetak
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal">
                </button>
            </div>

            <div class="modal-body">

                <div class="alert alert-info small">
                    <i class="fas fa-info-circle"></i>
                    Masukkan ukuran banner/cetak.
                    Setiap baris akan menjadi 1 item terpisah di keranjang.
                </div>

                <!-- HARGA PER M² -->
                <div class="row mb-3">

                    <div class="col-md-4">
                        <label class="form-label fw-bold">
                            Harga per m² (Rp)
                        </label>

                        <input
                            type="number"
                            class="form-control"
                            id="bannerHargaPerM2"
                            value="22000"
                            min="0"
                            step="500"
                            oninput="hitungSemuaBanner()">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">
                            Total Luas
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="bannerTotalLuas"
                            readonly
                            value="0 m²">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">
                            Total Harga
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="bannerTotalHarga"
                            readonly
                            value="Rp 0">
                    </div>

                </div>

                <!-- TABEL BANNER -->
                <div class="table-responsive">

                    <table
                        class="table table-bordered"
                        id="bannerTable">

                        <thead>
                            <tr>
                                <th style="width: 50px;">
                                    No
                                </th>

                                <th>
                                    P (cm)
                                </th>

                                <th>
                                    L (cm)
                                </th>

                                <th>
                                    Luas (m²)
                                </th>

                                <th style="width: 80px;">
                                    Qty
                                </th>

                                <th>
                                    Subtotal
                                </th>

                                <th style="width: 40px;">
                                </th>
                            </tr>
                        </thead>

                        <tbody id="bannerTableBody">
                            <!--
                                Baris Banner dibuat oleh
                                kasir-shared.js
                            -->
                        </tbody>

                    </table>

                </div>

                <!-- TAMBAH BARIS -->
                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    id="btnTambahBarisBanner"
                    onclick="tambahBarisBanner()">

                    <i class="fas fa-plus"></i>
                    Tambah Baris

                </button>

                <!--
                <div class="mt-3">
                    <label class="form-label">
                        Catatan Banner (opsional)
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        id="bannerCatatan"
                        placeholder="Contoh: bahan flexi china, design corel, dll">
                </div>
                -->

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal">
                    Batal
                </button>

                <button
                    type="button"
                    class="btn btn-info text-white"
                    onclick="prosesBanner()">

                    <i class="fas fa-plus"></i>
                    Tambahkan ke Keranjang

                </button>

            </div>

        </div>
    </div>
</div>
<script>
    // -----------------------------------------------------------------
    // 12.11. Event Listener Auto Calculate Banner
    // -----------------------------------------------------------------
    $(document).on(
        "input",
        '.banner-input, #bannerHargaPerM2, input[id^="bannerQty_"]',
        function() {
            hitungSemuaBanner();
        }
    );

    // -----------------------------------------------------------------
    // 12.12. Event Modal Banner Ditutup
    // -----------------------------------------------------------------
    $("#modalBanner").on("hidden.bs.modal", function() {
        resetModalBanner();
    });

    // -----------------------------------------------------------------
    // 12.13. Event Modal Banner Ditampilkan (Focus)
    // -----------------------------------------------------------------
    $("#modalBanner").on("shown.bs.modal", function() {
        setTimeout(function() {
            focusPanjangInput();
        }, 300);
    });

    // -----------------------------------------------------------------
    // 12.3. Fokus ke Input Panjang
    // -----------------------------------------------------------------
    function focusPanjangInput() {
        if (bannerRows.length === 0) return;

        const firstRowId = bannerRows[0];
        if (firstRowId) {
            const inputP = document.getElementById("bannerP_" + firstRowId);
            if (inputP) {
                setTimeout(function() {
                    inputP.focus();
                    inputP.select();
                }, 100);
            }
        }
    }

    // -----------------------------------------------------------------
    // 12.4. Event Navigasi Tab Banner
    // -----------------------------------------------------------------
    $(document).off('keydown', '.banner-p-input, .banner-l-input, #btnTambahBarisBanner');

    $(document).on('keydown', '.banner-p-input', function(e) {
        if (e.key === 'Tab' && !e.shiftKey) {
            // Panjang → pindah ke Lebar (baris yang sama)
            e.preventDefault();
            const currentId = $(this).attr('id');
            const rowId = currentId.replace('bannerP_', '');
            const inputL = document.getElementById("bannerL_" + rowId);
            if (inputL) {
                inputL.focus();
                inputL.select();
            }
        } else if (e.key === 'Tab' && e.shiftKey) {
            // Shift+Tab dari Panjang → pindah ke Lebar (baris sebelumnya) atau tombol Tambah
            e.preventDefault();
            const currentId = $(this).attr('id');
            const rowId = parseInt(currentId.replace('bannerP_', ''));
            const index = bannerRows.indexOf(rowId);

            if (index > 0) {
                // Ada baris sebelumnya → pindah ke Lebar baris sebelumnya
                const prevRowId = bannerRows[index - 1];
                const prevInputL = document.getElementById("bannerL_" + prevRowId);
                if (prevInputL) {
                    prevInputL.focus();
                    prevInputL.select();
                }
            } else {
                // Baris pertama → pindah ke tombol Tambah Baris
                const btnTambah = document.getElementById("btnTambahBarisBanner");
                if (btnTambah) {
                    btnTambah.focus();
                }
            }
        }
    });

    $(document).on('keydown', '.banner-l-input', function(e) {
        if (e.key === 'Tab' && !e.shiftKey) {
            // Lebar → pindah ke Qty (baris yang sama)
            e.preventDefault();
            const currentId = $(this).attr('id');
            const rowId = currentId.replace('bannerL_', '');
            const inputQty = document.getElementById("bannerQty_" + rowId);
            if (inputQty) {
                inputQty.focus();
                inputQty.select();
            }
        } else if (e.key === 'Tab' && e.shiftKey) {
            // Shift+Tab dari Lebar → pindah ke Panjang (baris yang sama)
            e.preventDefault();
            const currentId = $(this).attr('id');
            const rowId = currentId.replace('bannerL_', '');
            const inputP = document.getElementById("bannerP_" + rowId);
            if (inputP) {
                inputP.focus();
                inputP.select();
            }
        }
    });
    $(document).on('keydown', '.banner-qty-input', function(e) {
        if (e.key === 'Tab' && !e.shiftKey) {
            // Qty → pindah ke TOMBOL TAMBAH BARIS
            e.preventDefault();
            const btnTambah = document.getElementById("btnTambahBarisBanner");
            if (btnTambah) {
                btnTambah.focus();
            }
        } else if (e.key === 'Tab' && e.shiftKey) {
            // Shift+Tab dari Qty → pindah ke Lebar (baris yang sama)
            e.preventDefault();
            const currentId = $(this).attr('id');
            const rowId = currentId.replace('bannerQty_', '');
            const inputL = document.getElementById("bannerL_" + rowId);
            if (inputL) {
                inputL.focus();
                inputL.select();
            }
        }
    });


    $(document).on('keydown', '#btnTambahBarisBanner', function(e) {
        if (e.key === 'Tab' && !e.shiftKey) {
            // Tombol Tambah → pindah ke Panjang (baris pertama)
            e.preventDefault();
            focusPanjangInput();
        } else if (e.key === 'Tab' && e.shiftKey) {
            // Shift+Tab dari tombol → pindah ke Lebar (baris terakhir)
            e.preventDefault();
            if (bannerRows.length > 0) {
                const lastRowId = bannerRows[bannerRows.length - 1];
                const inputL = document.getElementById("bannerL_" + lastRowId);
                if (inputL) {
                    inputL.focus();
                    inputL.select();
                }
            }
        }

        if (e.key === 'Enter') {
            // Enter = tambah baris baru
            e.preventDefault();
            tambahBarisBanner();
        }
    });

    // -----------------------------------------------------------------
    // 12.5. Tambah Baris Banner
    // -----------------------------------------------------------------
    function tambahBarisBanner() {
        bannerRowCounter++;
        const rowId = bannerRowCounter;

        const tr = document.createElement("tr");
        tr.id = "bannerRow_" + rowId;
        tr.innerHTML = `
        <td class="text-center">${rowId}</td>
        <td>
            <input type="number" class="form-control form-control-sm banner-input banner-p-input" 
                   id="bannerP_${rowId}" min="1" step="0.1" 
                   placeholder=""
                   onchange="hitungSemuaBanner()">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm banner-input banner-l-input" 
                   id="bannerL_${rowId}" min="1" step="0.1" 
                   placeholder=""
                   onchange="hitungSemuaBanner()">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm text-center" 
                   id="bannerLuas_${rowId}" readonly value="0">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm text-center banner-qty-input" 
                   id="bannerQty_${rowId}" value="1" min="1" 
                   onchange="hitungSemuaBanner()">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm text-end" 
                   id="bannerSubtotal_${rowId}" readonly value="Rp 0">
        </td>
        <td class="text-center">
            <button class="btn btn-danger btn-sm" onclick="hapusBarisBanner(${rowId})">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;

        document.getElementById("bannerTableBody").appendChild(tr);
        bannerRows.push(rowId);
        renumberBannerRows();

        hitungSemuaBanner();

        setTimeout(function() {
            const inputP = document.getElementById("bannerP_" + rowId);
            if (inputP) {
                inputP.focus();
                inputP.select();
            }
        }, 200);
    }

    function bukaModalBanner() {
        const adaBanner = cart.some(item => item.is_banner === true);

        if (adaBanner) {
            kelolaBanner();
        } else {
            showModalBanner();
        }
    }
    // -----------------------------------------------------------------
    // 12.1. Tampilkan Modal Banner
    // -----------------------------------------------------------------
    function showModalBanner() {
        resetModalBanner();

        bannerRows = [];
        bannerRowCounter = 0;
        totalLuasBanner = 0;
        document.getElementById("bannerTableBody").innerHTML = "";
        document.getElementById("bannerTotalHarga").value = "Rp 0";
        document.getElementById("bannerTotalLuas").value = "0 m²";
        document.getElementById("bannerHargaPerM2").value = HARGA_STANDAR;

        tambahBarisBanner();

        $("#modalBanner").modal("show");

        setTimeout(function() {
            focusPanjangInput();
        }, 600);
    }

    // -----------------------------------------------------------------
    // 12.2. Reset Modal Banner
    // -----------------------------------------------------------------
    function resetModalBanner() {
        const modalTitle = document.querySelector("#modalBanner .modal-title");
        if (modalTitle) {
            modalTitle.innerHTML = '<i class="fas fa-ruler-combined"></i> Input Banner / Cetak';
        }

        const btnSubmit = document.querySelector("#modalBanner .modal-footer .btn-info");
        if (btnSubmit) {
            btnSubmit.innerHTML = '<i class="fas fa-plus"></i> Tambahkan ke Keranjang';
            btnSubmit.onclick = prosesBanner;
        }


        document.getElementById("bannerHargaPerM2").value = HARGA_STANDAR || 22000;
    }







    // -----------------------------------------------------------------
    // 12.6. Tambah Baris Banner Dengan Data (Edit)
    // -----------------------------------------------------------------

    function tambahBarisBannerWithData(p, l, qty, hargaPerM2) {
        bannerRowCounter++;
        const rowId = bannerRowCounter;

        const tr = document.createElement("tr");
        tr.id = "bannerRow_" + rowId;
        tr.innerHTML = `
        <td class="text-center">${rowId}</td>
        <td>
            <input type="number" class="form-control form-control-sm banner-input banner-p-input" 
                   id="bannerP_${rowId}" min="1" step="0.1" 
                   value="${p}"
                   placeholder=""
                   onchange="hitungSemuaBanner()">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm banner-input banner-l-input" 
                   id="bannerL_${rowId}" min="1" step="0.1" 
                   value="${l}"
                   placeholder=""
                   onchange="hitungSemuaBanner()">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm text-center" 
                   id="bannerLuas_${rowId}" readonly value="${((p * l) / 10000).toFixed(2)}">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm text-center banner-qty-input" 
                   id="bannerQty_${rowId}" value="${qty}" min="1" 
                   onchange="hitungSemuaBanner()">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm text-end" 
                   id="bannerSubtotal_${rowId}" readonly value="Rp 0">
        </td>
        <td class="text-center">
            <button class="btn btn-danger btn-sm" onclick="hapusBarisBanner(${rowId})">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;

        document.getElementById("bannerTableBody").appendChild(tr);
        bannerRows.push(rowId);
        renumberBannerRows();

        hitungSemuaBanner();
    }

    // -----------------------------------------------------------------
    // 12.7. Hapus Baris Banner
    // -----------------------------------------------------------------
    function hapusBarisBanner(rowId) {
        if (bannerRows.length <= 1) {
            showToast("Minimal 1 baris banner.", "warning");
            return;
        }

        const row = document.getElementById("bannerRow_" + rowId);
        if (row) {
            row.remove();
            bannerRows = bannerRows.filter((id) => id !== rowId);
            renumberBannerRows();
            hitungSemuaBanner();

            setTimeout(function() {
                focusPanjangInput();
            }, 200);
        }
    }

    function renumberBannerRows() {
        const rows = document.querySelectorAll('#bannerTableBody tr');
        rows.forEach((row, index) => {
            const noCell = row.querySelector('td:first-child');
            if (noCell) {
                noCell.textContent = index + 1;
            }
        });
    }

    function hitungSemuaBanner() {
        HARGA_STANDAR =
            parseInt(
                document.getElementById('bannerHargaPerM2').value,
                10
            ) || 22000;

        totalLuasBanner = 0;
        const rowData = [];

        bannerRows.forEach((id) => {
            const p = parseFloat(
                document.getElementById('bannerP_' + id)?.value
            ) || 0;

            const l = parseFloat(
                document.getElementById('bannerL_' + id)?.value
            ) || 0;

            const qty = parseInt(
                document.getElementById('bannerQty_' + id)?.value,
                10
            ) || 1;

            const luas = (p * l) / 10000;
            const luasTotalBaris = luas * qty;

            totalLuasBanner += luasTotalBaris;
            rowData.push({
                id,
                luas,
                qty,
                luasTotalBaris
            });
        });

        // Untuk total < 1 m², harga minimum / pembulatan dihitung SATU KALI
        // berdasarkan total luas seluruh Banner, lalu dialokasikan proporsional.
        // Untuk total >= 1 m², setiap baris kembali dihitung normal dan
        // dibulatkan Rp500 per baris.
        let grandTotal = 0;

        if (totalLuasBanner > 0 && totalLuasBanner < 1.0) {
            grandTotal =
                Math.round((totalLuasBanner * HARGA_STANDAR * 1.1) / 500) * 500;

            if (grandTotal > HARGA_STANDAR) {
                grandTotal = HARGA_STANDAR;
            }

            if (grandTotal < 10000) {
                grandTotal = 10000;
            }
        } else if (totalLuasBanner >= 1.0) {
            rowData.forEach((row) => {
                if (row.luasTotalBaris > 0) {
                    grandTotal +=
                        Math.round((row.luasTotalBaris * HARGA_STANDAR) / 500) * 500;
                }
            });
        }

        // < 1 m²: bagikan total secara proporsional berdasarkan luas x qty.
        // Baris terakhir menjadi balancer agar jumlah subtotal persis sama
        // dengan grandTotal.
        // >= 1 m²: subtotal dihitung normal per baris, tanpa alokasi global.
        let allocatedTotal = 0;

        rowData.forEach((row, index) => {
            const inputLuas = document.getElementById('bannerLuas_' + row.id);
            const inputSubtotal = document.getElementById('bannerSubtotal_' + row.id);

            if (inputLuas) {
                inputLuas.value = row.luas.toFixed(2);
            }

            if (!inputSubtotal) {
                return;
            }

            let subtotal = 0;

            if (grandTotal > 0 && totalLuasBanner > 0) {
                if (totalLuasBanner < 1.0) {
                    if (index === rowData.length - 1) {
                        subtotal = grandTotal - allocatedTotal;
                    } else {
                        subtotal = Math.round(
                            grandTotal * (row.luasTotalBaris / totalLuasBanner)
                        );
                        allocatedTotal += subtotal;
                    }
                } else {
                    subtotal = row.luasTotalBaris > 0 ?
                        Math.round((row.luasTotalBaris * HARGA_STANDAR) / 500) * 500 :
                        0;
                }
            }

            inputSubtotal.value = formatRupiah(subtotal);
        });

        document.getElementById('bannerTotalLuas').value =
            totalLuasBanner.toFixed(2) + ' m²';

        document.getElementById('bannerTotalHarga').value =
            formatRupiah(grandTotal);
    }

    // -----------------------------------------------------------------
    // 12.8. Hitung Total Luas Banner
    // -----------------------------------------------------------------
    function hitungTotalLuasBanner() {
        totalLuasBanner = 0;
        bannerRows.forEach((id) => {
            const luasText = document.getElementById("bannerLuas_" + id).value;
            const luas = parseFloat(luasText) || 0;
            const qty = parseInt(document.getElementById("bannerQty_" + id).value) || 1;
            totalLuasBanner += luas * qty;
        });
        return totalLuasBanner;
    }
    // -----------------------------------------------------------------
    // 12.10. Hitung Satu Baris Banner
    // -----------------------------------------------------------------

    function hitungBannerRow(rowId) {
        const inputP = document.getElementById('bannerP_' + rowId);
        const inputL = document.getElementById('bannerL_' + rowId);
        const inputLuas = document.getElementById('bannerLuas_' + rowId);

        if (!inputP || !inputL || !inputLuas) {
            return;
        }

        const p = parseFloat(inputP.value) || 0;
        const l = parseFloat(inputL.value) || 0;
        const luas = (p * l) / 10000;

        inputLuas.value = luas.toFixed(2);
    }
</script>
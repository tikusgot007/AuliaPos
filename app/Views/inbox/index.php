<link rel="stylesheet" href="<?= base_url('assets/css/inbox.css') ?>">

<div class="card">
    <div class="card-header inbox-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="fab fa-whatsapp"></i> Inbox WhatsApp</h5>
        <span class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#modalChatBaru">
                <i class="fas fa-plus"></i> Chat Baru
            </button>
            <span id="perluDibalasBadge" class="badge bg-danger" style="display:none;"></span>
            <span id="gatewayStatusBadge" class="badge bg-secondary">
                <i class="fas fa-circle-notch fa-spin"></i> Memeriksa...
            </span>
            <span id="realtimeStatusBadge" class="badge bg-secondary" title="Koneksi realtime Inbox">
                <i class="fas fa-circle-notch fa-spin"></i> Realtime: Memulai...
            </span>
        </span>
    </div>
    <div class="card-body p-2">
        <div class="inbox-wrapper">
            <!-- ============================================ -->
            <!-- PANEL KIRI: DAFTAR CONVERSATION               -->
            <!-- ============================================ -->
            <div class="inbox-list-col">
                <!-- Search (M3 Fase 1c, TASK-017): runs on Enter or the  -->
                <!-- search icon, never per keystroke; sent to the server  -->
                <!-- as `q` so it covers ALL conversations (CL-001).       -->
                <form class="p-2 border-bottom bg-white" onsubmit="return cariConversationDariInput(event)">
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="inputCariConversation" maxlength="255" placeholder="Cari nama / nomor..." autocomplete="off">
                        <button type="button" class="btn btn-outline-secondary" title="Hapus pencarian" onclick="hapusPencarianConversation()">&#x2715;</button>
                        <button type="submit" class="btn btn-outline-primary" title="Cari"><i class="fas fa-search"></i></button>
                    </div>
                </form>
                <div class="inbox-list-filter d-flex gap-1 p-2 border-bottom flex-wrap bg-white">
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterBelum_diambil" onclick="setFilterConversation('belum_diambil')">Belum Diambil <span class="badge bg-light text-dark border tab-count" data-count-for="belum_diambil">0</span></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterOpen" onclick="setFilterConversation('open')">Open <span class="badge bg-light text-dark border tab-count" data-count-for="open">0</span></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterMenunggu" onclick="setFilterConversation('menunggu')">Menunggu <span class="badge bg-light text-dark border tab-count" data-count-for="menunggu">0</span></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterDitunda" onclick="setFilterConversation('ditunda')">Ditunda <span class="badge bg-light text-dark border tab-count" data-count-for="ditunda">0</span></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterSelesai" onclick="setFilterConversation('selesai')">Selesai <span class="badge bg-light text-dark border tab-count" data-count-for="selesai">0</span></button>
                    <!-- Grup Tahap 1 (REQ-009) -- SENGAJA paling akhir, 5 tab lama tidak bergeser posisi. -->
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterGrup" onclick="setFilterConversation('grup')">Grup <span class="badge bg-light text-dark border tab-count" data-count-for="grup">0</span></button>
                </div>
                <!-- Filled only by renderDaftarConversation() (TODO-Q5): one renderer, no PHP duplicate. -->
                <div class="inbox-list-panel" id="inboxListPanel"></div>
            </div>

            <!-- ============================================ -->
            <!-- PANEL KANAN: RIWAYAT PESAN + FORM KIRIM        -->
            <!-- ============================================ -->
            <div class="inbox-thread-panel" id="inboxThreadPanel">
                <div class="inbox-drop-overlay" id="inboxDropOverlay">
                    <span class="inbox-drop-overlay-text"><i class="fas fa-paperclip"></i> Lepas file di sini untuk melampirkan</span>
                </div>

                <div class="inbox-thread-header d-flex justify-content-between align-items-center" id="threadHeader">
                    <span class="text-muted">Pilih percakapan di sebelah kiri untuk mulai.</span>
                </div>

                <div class="inbox-thread-wrap">
                    <div class="inbox-thread-messages" id="threadMessages">
                        <div class="inbox-thread-empty">
                            <i class="fas fa-comments fa-2x me-2"></i> Belum ada percakapan dipilih.
                        </div>
                    </div>
                    <!-- Muncul saat pesan baru masuk selagi kasir membaca riwayat (P7, AC-29). -->
                    <!-- Unduh massal: aktifkan mode pilih, centang bubble bermedia, lalu Unduh. -->
                    <button type="button" class="inbox-btn-mode-pilih" id="btnModePilih" onclick="alihkanModePilih()" title="Pilih media untuk diunduh">
                        <i class="fas fa-check-square"></i> Pilih media
                    </button>
                    <button type="button" class="inbox-gulung-baru" id="btnGulungBaru" style="display:none;" onclick="gulungKeTerbaru()" title="Gulung ke pesan terbaru">
                        <span class="inbox-gulung-jumlah">0</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>

                <div class="inbox-bar-pilih" id="barPilihUnduh">
                    <span><strong class="inbox-pilih-jumlah">0</strong> dipilih</span>
                    <button type="button" class="btn btn-success btn-sm inbox-pilih-unduh" onclick="unduhTerpilih()" disabled>
                        <i class="fas fa-download"></i> Unduh
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="alihkanModePilih(false)">Batal</button>
                </div>

                <!-- Nudge 1 (Fase 5): banner tepat di atas textarea balasan,
                     di luar area scroll thread. Muncul setelah 7 dtk pada
                     percakapan belum_diambil; boleh muncul lagi setelah pindah
                     conversation lalu kembali. -->
                <div class="inbox-nudge-ambil" id="nudgeAmbilBanner" style="display:none;">
                    <span class="inbox-nudge-ambil-teks">Mau diambil, atau lihat-lihat saja?</span>
                    <span class="inbox-nudge-ambil-aksi">
                        <button type="button" class="btn btn-sm btn-primary" onclick="nudgeAmbilSekarang()">Ambil</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="nudgeAmbilLihatSaja()">Lihat saja</button>
                    </span>
                </div>

                <div class="inbox-thread-form">
                    <!-- Area kutipan aktif (Balas Pesan, REQ-005): muncul
                         hanya setelah kasir menekan "Balas" pada salah satu
                         bubble. Tepat di atas kotak ketik supaya jelas
                         balasan mana yang sedang ditulis, dan bisa dibatalkan
                         tanpa mengirim apa pun. -->
                    <div id="kutipanAktif" class="kutipan-aktif" style="display:none;">
                        <i class="fas fa-reply align-self-center text-success"></i>
                        <div class="inbox-kutipan-isi flex-grow-1">
                            <div class="kutipan-aktif-judul" id="kutipanAktifJudul"></div>
                            <div class="inbox-kutipan-snippet" id="kutipanAktifSnippet"></div>
                        </div>
                        <button type="button" class="btn-close btn-sm align-self-center" id="btnBatalKutipan" style="font-size: var(--fs-caption);" aria-label="Batalkan kutipan" onclick="batalkanKutipan()"></button>
                    </div>
                    <!-- Badge per-file di-render dinamis oleh renderAntrianMediaBalasan()
                         -- lihat definisi antrianMediaBalasan di bawah. -->
                    <div id="previewMediaBalasan" class="mb-2 d-flex flex-wrap gap-1" style="display:none;"></div>
                    <!-- data-operation-id (M1 Wave 2, TASK-019): kunci
                         idempotensi milik frontend, hidup selama satu
                         percobaan kirim dan dipakai ulang saat kirim ulang
                         setelah gagal/timeout (REQ-039). Kosong = kunci
                         akan dibuat saat kirim pertama. -->
                    <div id="panelEmoji" class="inbox-emoji-panel" style="display:none;" role="dialog" aria-label="Pilih emoji"></div>
                    <form id="formBalas" data-operation-id="" onsubmit="return kirimBalasan(event)">
                        <div class="inbox-composer">
                            <button class="inbox-composer-ikon" type="button" id="btnLampirkanMedia" disabled onclick="document.getElementById('inputMediaBalasan').click()" title="Lampirkan file" aria-label="Lampirkan file">
                                <i class="fas fa-paperclip"></i>
                            </button>
                            <input type="file" id="inputMediaBalasan" multiple style="display:none;" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" onchange="pilihMediaBalasan(event)">
                            <button class="inbox-composer-ikon" type="button" id="btnTemplateBalasan" disabled onclick="bukaModalTemplate()" title="Template Balasan" aria-label="Template Balasan">
                                <i class="fas fa-comment-dots"></i>
                            </button>
                            <button class="inbox-composer-ikon" type="button" id="btnEmojiBalasan" disabled onclick="toggleEmojiPanel()" title="Emoji" aria-label="Emoji">
                                <i class="far fa-smile"></i>
                            </button>
                            <textarea class="inbox-composer-teks" id="teksBalasan" rows="1" placeholder="Pilih percakapan dulu..." disabled aria-label="Tulis balasan"></textarea>
                            <button class="inbox-composer-kirim" type="submit" id="btnKirimBalasan" disabled title="Kirim" aria-label="Kirim">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                    <!-- Status kirim yang tidak auto-hilang (M1 Wave 2,
                         TASK-019/AC-046): dipakai untuk memberi tahu kasir
                         bahwa hasil kirim BELUM PASTI, sehingga kirim ulang
                         buta tidak disarankan. -->
                    <div id="statusKirimBalasan" class="small mt-1" style="display:none;" role="status" aria-live="polite"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL CHAT BARU (kirim ke nomor yang belum pernah masuk) -->
<!-- ============================================ -->
<div class="modal fade" id="modalChatBaru" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Chat Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formChatBaru" onsubmit="return mulaiChatBaru(event)">
                <div class="modal-body">
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle"></i>
                        Dipakai untuk mulai chat ke nomor yang <strong>belum pernah</strong> mengirim
                        pesan ke toko. Kalau nomor ini sudah pernah chat sebelumnya, pesan akan
                        otomatis masuk ke percakapan yang sudah ada.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nomor WhatsApp Customer</label>
                        <input type="text" class="form-control" id="nomorChatBaru" placeholder="Contoh: 08123456789" required>
                        <small class="text-muted">Format 08xx, 62xx, atau +62xx.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pesan Pertama</label>
                        <textarea class="form-control" id="teksChatBaru" rows="3" required placeholder="Ketik pesan pembuka..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnChatBaru">
                        <i class="fas fa-paper-plane"></i> Kirim & Mulai Chat
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL KONFIRMASI HAPUS PERCAKAPAN              -->
<!-- ============================================ -->
<div class="modal fade" id="modalHapusPercakapan" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="fas fa-trash-alt"></i> Hapus Percakapan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger small mb-0">
                    <i class="fas fa-exclamation-triangle"></i>
                    Percakapan dengan <strong id="namaHapusPercakapan"></strong> beserta
                    <strong>SEMUA riwayat pesannya</strong> akan dihapus permanen dan
                    <strong>tidak bisa dikembalikan</strong>. Yakin lanjutkan?
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" id="btnKonfirmasiHapusPercakapan" onclick="konfirmasiHapusPercakapan()">
                    <i class="fas fa-trash-alt"></i> Ya, Hapus Permanen
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL EDIT PROFIL PELANGGAN (Task Group 1.5)   -->
<!-- ============================================ -->
<div class="modal fade" id="modalEditProfil" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-edit"></i> Edit Profil Pelanggan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formEditProfil" onsubmit="return simpanProfilPelanggan(event)">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nama Pelanggan</label>
                        <input type="text" class="form-control" id="editProfilNama" placeholder="Kosongkan untuk hapus nama manual">
                        <small class="text-muted">Nama ini TIDAK akan ditimpa otomatis oleh nama WhatsApp customer.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">No. Telepon</label>
                        <input type="text" class="form-control" id="editProfilTelepon" placeholder="Contoh: 08123456789">
                        <small class="text-muted">Format 08xx, 62xx, atau +62xx. Murni catatan -- bukan nomor WhatsApp yang terverifikasi.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSimpanProfil">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL KONFIRMASI NOMOR WHATSAPP (Task Group 1.5 -->
<!-- revisi LID-FIRST -> PN-LATER)                  -->
<!-- ============================================ -->
<div class="modal fade" id="modalKonfirmasiNomor" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-shield-alt"></i> Konfirmasi Nomor WhatsApp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formKonfirmasiNomor" onsubmit="return simpanKonfirmasiNomor(event)">
                <div class="modal-body">
                    <div class="alert alert-warning small">
                        <i class="fas fa-exclamation-triangle"></i>
                        Percakapan ini belum punya nomor WhatsApp yang terverifikasi
                        (identitas teknisnya <code>@lid</code>, WhatsApp tidak
                        membocorkan nomornya ke Gateway). Isi nomor ini HANYA kalau
                        Anda BENAR-BENAR YAKIN ini nomor WhatsApp customer yang sama
                        -- pesan berikutnya dari nomor ini akan otomatis masuk ke
                        percakapan ini.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nomor WhatsApp yang Dikonfirmasi</label>
                        <input type="text" class="form-control" id="konfirmasiNomorInput" placeholder="Contoh: 08123456789" required>
                        <small class="text-muted">Format 08xx, 62xx, atau +62xx.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning" id="btnKonfirmasiNomor">
                        <i class="fas fa-shield-alt"></i> Ya, Konfirmasi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL HANDOFF (M3 Fase 2a, TB-01/TASK-004)     -->
<!-- ============================================ -->
<div class="modal fade" id="modalHandoff" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-share-square"></i> Serahkan Percakapan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formHandoff" onsubmit="return kirimHandoff(event)">
                <div class="modal-body">
                    <div class="alert alert-danger small d-none" id="handoffAlert">
                        <div id="handoffAlertMessage"></div>
                        <!-- TASK-007 (loser UX): pesan server dipakai apa
                             adanya (409 sudah menyebut nama pemilik sah).
                             Tombol ini menyegarkan daftar Queue + header
                             supaya UI tidak menampilkan keadaan basi, lalu
                             menutup dialog agar nilai expected_owner lama
                             tidak terkirim ulang -- retry apa pun tetap
                             jatuh 409 (K-09). -->
                        <button type="button" class="btn btn-sm btn-outline-danger mt-2" id="btnMuatUlangHandoff"
                            onclick="muatUlangSetelahHandoffBasi()">
                            <i class="fas fa-sync-alt"></i> Muat ulang
                        </button>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Serahkan kepada (kasir aktif)</label>
                        <select class="form-select" id="handoffTarget" required>
                            <option value="">-- Pilih kasir --</option>
                            <?php foreach (($daftarKasir ?? []) as $kasir): ?>
                                <?php if ((int) $kasir['id'] !== (int) $currentUserId): ?>
                                    <option value="<?= (int) $kasir['id'] ?>"><?= esc($kasir['inisial'] ?: ($kasir['username'] ?: ('Kasir #' . $kasir['id']))) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            Hanya kasir aktif. Kalau kasir ini dinonaktifkan setelah dialog dibuka,
                            server akan menolak (403) dan percakapan tidak berpindah.
                        </small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan Handoff (opsional)</label>
                        <textarea class="form-control" id="handoffSummary" rows="4" maxlength="4096"
                            placeholder="Contoh: customer tanya harga grosir, sudah dikirim price list v3; follow up besok pagi jika belum ada balasan."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnKirimHandoff">
                        <i class="fas fa-share-square"></i> Serahkan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL TERUSKAN (Tahap 4, TASK-003)             -->
<!-- ============================================ -->
<div class="modal fade" id="modalTeruskan" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-share"></i> Teruskan Pesan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <!-- data-operation-id (pola formBalas, M1 Wave 2): kunci
                 idempotensi milik frontend untuk satu percobaan Teruskan.
                 SENGAJA dipisah dari formBalas supaya kunci balasan yang
                 belum selesai tidak pernah dipakai ulang oleh aksi Teruskan
                 (bisa terbaca sebagai replay bila tujuannya sama). -->
            <form id="formTeruskan" data-operation-id="" onsubmit="return kirimTeruskan(event)">
                <div class="modal-body">
                    <!-- Cuplikan pesan sumber: hanya untuk memastikan kasir
                         memilih pesan yang benar. Isi yang benar-benar dikirim
                         diambil server dari DB (Section 9 "Always do"), jadi
                         TIDAK ada kolom yang bisa diedit kasir di sini. -->
                    <div class="teruskan-sumber">
                        <div class="teruskan-sumber-judul">Pesan yang diteruskan</div>
                        <div class="inbox-kutipan-snippet" id="teruskanSumberCuplikan"></div>
                    </div>

                    <div class="alert alert-danger small d-none mt-3" id="teruskanAlert">
                        <div id="teruskanAlertMessage"></div>
                    </div>

                    <label class="form-label mt-3">Kirim ke percakapan</label>
                    <!-- REQ-005: memakai endpoint pencarian percakapan yang
                         sudah ada. TIDAK ADA opsi "buat percakapan baru". -->
                    <div class="input-group mb-2">
                        <input type="text" class="form-control" id="cariTujuanTeruskan"
                            placeholder="Cari nama / nomor..."
                            onkeydown="if (event.key === 'Enter') { event.preventDefault(); jalankanPencarianTujuanTeruskan(); }">
                        <button class="btn btn-outline-secondary" type="button" onclick="jalankanPencarianTujuanTeruskan()">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                    <div id="daftarTujuanTeruskan" class="teruskan-daftar-tujuan">
                        <div class="text-muted small p-2">Memuat percakapan...</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnKirimTeruskan" disabled>
                        <i class="fas fa-share"></i> Teruskan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL EDIT PESAN KELUAR (task 2026-10-07)      -->
<!-- ============================================ -->
<div class="modal fade" id="modalEditPesan" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pen"></i> Edit Pesan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formEditPesan" onsubmit="return simpanEditPesan(event)">
                <div class="modal-body">
                    <div class="text-muted small mb-2">WhatsApp hanya mengizinkan edit dalam 15 menit pertama setelah pesan dikirim.</div>
                    <div class="alert alert-warning small d-none" id="editPesanAlert" role="alert"></div>
                    <textarea class="form-control" id="teksEditPesan" rows="3" maxlength="4096"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSimpanEditPesan"><i class="fas fa-pen"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL KONFIRMASI HAPUS PESAN KELUAR            -->
<!-- ============================================ -->
<div class="modal fade" id="modalKonfirmasiHapus" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash"></i> Hapus Pesan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger small d-none" id="hapusPesanAlert" role="alert"></div>
                <p class="mb-0">Hapus pesan ini untuk semua orang? Tindakan ini tidak bisa dibatalkan.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" id="btnKonfirmasiHapus" onclick="konfirmasiHapusPesan()"><i class="fas fa-trash"></i> Hapus untuk semua</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL TEMPLATE BALASAN CEPAT (TODO-R1) -->
<!-- ============================================ -->
<div class="modal fade" id="modalTemplateBalasan" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-comment-dots"></i> Template Balasan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="daftarTemplateBalasan" class="teruskan-daftar-tujuan">
                    <div class="text-muted small p-2">Memuat template...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL SNOOZE / FOLLOW-UP (M3 Fase 1b, TASK-012) -->
<!-- ============================================ -->
<div class="modal fade" id="modalSnooze" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-clock"></i> Follow-up Nanti</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formSnooze" onsubmit="return kirimSnoozeDariModal(event)">
                <div class="modal-body">
                    <p class="mb-3">Tunda percakapan ini selama <strong id="snoozeLabelDurasi"></strong>.</p>
                    <div class="mb-3">
                        <label class="form-label">Alasan (opsional)</label>
                        <textarea class="form-control" id="snoozeAlasan" rows="3" maxlength="4096"
                            placeholder="Contoh: customer minta dihubungi lagi setelah gajian."></textarea>
                        <small class="text-muted">Disimpan sebagai Internal Note, tidak terkirim ke pelanggan.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning" id="btnKirimSnooze">
                        <i class="fas fa-clock"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL CATATAN INTERNAL (M3 Fase 1c, TASK-015)  -->
<!-- ============================================ -->
<div class="modal fade" id="modalCatatanInternal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sticky-note"></i> Catatan Internal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formCatatanInternal" onsubmit="return simpanCatatanInternal(event)">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea class="form-control" id="catatanInternalTeks" rows="4" maxlength="4096"
                            placeholder="Contoh: cek stok dulu sebelum janji ke customer."></textarea>
                        <small class="text-muted">Hanya terlihat oleh staff, tidak terkirim ke pelanggan.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSimpanCatatanInternal">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Lightbox gambar (klik gambar di thread). Di luar #threadMessages supaya polling tidak menutupnya. -->
<div class="modal fade" id="lightboxMedia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <span class="modal-title small text-muted inbox-lightbox-nama"></span>
                <button type="button" class="btn btn-success btn-sm ms-auto me-2" onclick="unduhDariLightbox(this)">
                    <i class="fas fa-download"></i> Unduh
                </button>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <img class="inbox-lightbox-img" alt="Gambar">
            </div>
        </div>
    </div>
</div>

<!-- Nudge 2 (Fase 2): konfirmasi sebelum unduh media pada percakapan yang
     belum diambil / dipegang orang lain. Di luar #threadMessages supaya
     polling tidak menutupnya. Tombol "Ambil"/"Ambil Alih" memanggil endpoint
     /ambil yang sudah ada; "Unduh saja" tetap mengunduh (read-only). -->
<div class="modal fade" id="nudgeUnduhModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <span class="modal-title small fw-semibold">Ambil percakapan?</span>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="nudgeUnduhTeks"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-primary" id="btnNudgeAmbilUnduh" onclick="nudgeUnduhPilih('ambil')">Ambil &amp; Unduh</button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnNudgeUnduhSaja" onclick="nudgeUnduhPilih('unduh')">Unduh saja</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="nudgeUnduhPilih('batal')">Batal</button>
            </div>
        </div>
    </div>
</div>

<script>
    window.INBOX_THREAD_CONFIG = {
        mediaBaseUrl: <?= json_encode(base_url('/inbox/media/')) ?>,
        apiMessagesUrl: <?= json_encode(base_url('/inbox/api/conversations')) ?>,
        maxMediaDownloadMb: <?= json_encode($maxMediaDownloadMb) ?>
    };
    window.INBOX_TEMPLATE_CONFIG = {
        apiUrl: <?= json_encode(base_url('/inbox/api/balasan-template')) ?>
    };
</script>
<script src="<?= base_url('assets/js/inbox-thread.js') ?>"></script>
<script src="<?= base_url('assets/js/inbox-template.js') ?>"></script>
<script>
    window.INBOX_PAGE_CONFIG = {
        url: {
            conversations: <?= json_encode(base_url('/inbox/api/conversations')) ?>,
            gatewayStatus: <?= json_encode(base_url('/inbox/api/gateway-status')) ?>,
            realtimeTicket: <?= json_encode(base_url('/inbox/api/realtime-ticket')) ?>,
            kirim: <?= json_encode(base_url('/inbox/kirim')) ?>,
            kirimMedia: <?= json_encode(base_url('/inbox/kirim-media')) ?>,
            mulaiPercakapan: <?= json_encode(base_url('/inbox/mulai-percakapan')) ?>,
            percakapan: <?= json_encode(base_url('/inbox/percakapan/')) ?>,
            pesan: <?= json_encode(base_url('/inbox/pesan/')) ?>
        },
        conversations: <?= json_encode($conversations) ?>,
        currentUserId: <?= (int) $currentUserId ?>,
        currentUserRole: <?= json_encode($currentUserRole) ?>,
        semuaUserLabel: <?= json_encode((object) ($semuaUserLabel ?? []), JSON_UNESCAPED_UNICODE) ?>,
        tipeTeruskanLampiran: <?= json_encode($tipeTeruskanLampiran) ?>,
        gatewayStatus: <?= json_encode($gatewayStatus) ?>
    };
</script>
<script src="<?= base_url('assets/js/inbox-emoji.js') ?>"></script>
<script src="<?= base_url('assets/js/inbox-page.js') ?>"></script>

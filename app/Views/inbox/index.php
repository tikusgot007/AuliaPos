<style>
    /* ========================================================== */
    /* LAYOUT INBOX - 2 KOLOM (daftar chat | riwayat pesan)        */
    /* ========================================================== */
    .inbox-wrapper {
        display: flex;
        flex: 1 1 auto;
        min-height: 480px;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        overflow: hidden;
        background: #fff;
    }

    /* .card/.card-body di sini adalah SATU-SATUNYA card di halaman ini
       (langsung anak <main> di layout/minimal.php) -- dibuat flex
       column mengisi tinggi viewport supaya flex:1 1 auto di atas
       benar-benar punya ruang untuk tumbuh, bukan cuma ukuran konten. */
    .card {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
    }

    .card-body {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
    }

    .inbox-list-col {
        width: 320px;
        min-width: 260px;
        border-right: 1px solid #dee2e6;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .inbox-list-filter .btn.active {
        background: #0d6efd;
        color: #fff;
        border-color: #0d6efd;
    }

    .inbox-list-panel {
        flex: 1;
        overflow-y: auto;
        background: #f8f9fa;
        min-height: 0;
    }

    .inbox-list-item {
        display: block;
        padding: 12px 14px;
        border-bottom: 1px solid #e9ecef;
        border-left: 3px solid transparent;
        cursor: pointer;
        text-decoration: none;
        color: inherit;
    }

    .inbox-list-item:hover {
        background: #eef2f7;
    }

    .inbox-list-item.active {
        background: #d7e6fb;
        border-left-color: #0d6efd;
    }

    .inbox-list-item .list-name {
        font-weight: 600;
        font-size: 0.9rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .inbox-list-item .list-preview {
        font-size: 0.78rem;
        color: #6c757d;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .inbox-list-item .list-time {
        font-size: 0.7rem;
        color: #9aa0a6;
    }

    .inbox-thread-panel {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-width: 0;
        position: relative; /* anchor untuk overlay drag-and-drop */
    }

    .inbox-drop-overlay {
        display: none;
        position: absolute;
        inset: 0;
        z-index: 20;
        align-items: center;
        justify-content: center;
        background: rgba(37, 211, 102, 0.15);
        border: 3px dashed #25d366;
        border-radius: 6px;
        pointer-events: none; /* drop tetap ditangkap panel, bukan overlay */
    }

    .inbox-drop-overlay.active {
        display: flex;
    }

    .inbox-drop-overlay-text {
        background: #fff;
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 600;
        color: #075e54;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .inbox-thread-header {
        padding: 10px 16px;
        border-bottom: 1px solid #dee2e6;
        background: #fff;
    }

    .inbox-thread-messages {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
        background: #e5ddd5;
        /* nuansa hijau muda WA */
    }

    .inbox-thread-empty {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: #6c757d;
    }

    .inbox-bubble {
        max-width: 70%;
        padding: 8px 12px;
        border-radius: 10px;
        margin-bottom: 8px;
        font-size: 0.88rem;
        word-wrap: break-word;
        white-space: pre-wrap;
    }

    .inbox-bubble.incoming {
        background: #fff;
        margin-right: auto;
        border-top-left-radius: 2px;
    }

    .inbox-bubble.outgoing {
        background: #d9fdd3;
        margin-left: auto;
        border-top-right-radius: 2px;
    }

    .inbox-bubble.internal-note {
        background: #fff3cd;
        margin-left: auto;
        margin-right: auto;
        border: 1px dashed #d39e00;
        max-width: 78%;
    }

    .inbox-bubble.internal-note .bubble-sender {
        color: #856404;
    }

    .inbox-internal-label {
        display: inline-block;
        font-size: 0.68rem;
        font-weight: 700;
        color: #856404;
        margin-bottom: 4px;
    }

    .inbox-bubble .bubble-meta {
        font-size: 0.68rem;
        color: #8a8a8a;
        margin-top: 2px;
        text-align: right;
    }

    .inbox-bubble .bubble-sender {
        font-size: 0.7rem;
        font-weight: 600;
        color: #1da47f;
        margin-bottom: 2px;
    }

    .inbox-media-image {
        max-width: 100%;
        max-height: 300px;
        border-radius: 6px;
        display: block;
        cursor: pointer;
    }

    .inbox-media-sticker {
        max-width: 130px;
        max-height: 130px;
        display: block;
    }

    .inbox-media-document {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 10px;
        background: rgba(0, 0, 0, 0.04);
        border-radius: 6px;
        text-decoration: none;
        color: inherit;
        font-size: 0.85rem;
        word-break: break-all;
    }

    .inbox-media-document:hover {
        background: rgba(0, 0, 0, 0.08);
    }

    .inbox-media-unavailable {
        padding: 10px;
        background: rgba(0, 0, 0, 0.04);
        border-radius: 6px;
        color: #999;
        font-size: 0.8rem;
        font-style: italic;
    }

    .inbox-media-caption {
        margin-top: 4px;
        font-size: 0.85rem;
    }

    .inbox-thread-form {
        padding: 10px 16px;
        border-top: 1px solid #dee2e6;
        background: #fff;
    }

    #gatewayStatusBadge.bg-success { background-color: #198754 !important; }
    #gatewayStatusBadge.bg-warning { background-color: #ffc107 !important; color: #212529 !important; }
    #gatewayStatusBadge.bg-secondary { background-color: #6c757d !important; }

    /* ========================================================== */
    /* PANEL RIWAYAT HANDOFF (TB-03/TASK-010)                      */
    /* ========================================================== */
    .inbox-handoff-panel {
        max-height: 168px;
        overflow-y: auto;
        padding: 8px 16px;
        border-bottom: 1px solid #dee2e6;
        background: #fffdf5;
        font-size: 0.8rem;
    }

    .inbox-handoff-judul {
        font-weight: 600;
        color: #8a6d3b;
        margin-bottom: 2px;
    }

    .inbox-handoff-item {
        padding: 4px 0;
        border-top: 1px solid #f1e9d6;
    }
</style>

<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="fab fa-whatsapp"></i> Inbox WhatsApp</h5>
        <span class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#modalChatBaru">
                <i class="fas fa-plus"></i> Chat Baru
            </button>
            <span id="perluDibalasBadge" class="badge bg-danger" style="display:none;"></span>
            <span id="gatewayStatusBadge" class="badge bg-secondary">
                <i class="fas fa-circle-notch fa-spin"></i> Memeriksa...
            </span>
        </span>
    </div>
    <div class="card-body p-2">
        <div class="inbox-wrapper">
            <!-- ============================================ -->
            <!-- PANEL KIRI: DAFTAR CONVERSATION               -->
            <!-- ============================================ -->
            <div class="inbox-list-col">
            <div class="inbox-list-filter d-flex gap-1 p-2 border-bottom flex-wrap" style="background:#fff;">
                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterBelum_diambil" onclick="setFilterConversation('belum_diambil')">Belum Diambil <span class="badge bg-light text-dark border tab-count" data-count-for="belum_diambil">0</span></button>
                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterOpen" onclick="setFilterConversation('open')">Open <span class="badge bg-light text-dark border tab-count" data-count-for="open">0</span></button>
                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterMenunggu" onclick="setFilterConversation('menunggu')">Menunggu <span class="badge bg-light text-dark border tab-count" data-count-for="menunggu">0</span></button>
                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterDitunda" onclick="setFilterConversation('ditunda')">Ditunda <span class="badge bg-light text-dark border tab-count" data-count-for="ditunda">0</span></button>
                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterSelesai" onclick="setFilterConversation('selesai')">Selesai <span class="badge bg-light text-dark border tab-count" data-count-for="selesai">0</span></button>
            </div>
            <div class="inbox-list-panel" id="inboxListPanel">
                <?php if (empty($conversations)): ?>
                    <div class="p-3 text-muted small text-center">
                        Belum ada percakapan masuk.
                    </div>
                <?php endif; ?>
                <?php foreach ($conversations as $c): ?>
                    <a href="#" class="inbox-list-item" data-conversation-id="<?= esc($c['id']) ?>" onclick="return pilihConversation(<?= (int) $c['id'] ?>)">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="list-name"><?= esc($c['contact_name'] ?: $c['whatsapp_name'] ?: $c['phone'] ?: $c['chat_id']) ?></span>
                            <span class="d-flex align-items-center gap-1">
                                <span class="list-time"><?= $c['last_message_at'] ? date('d/m H:i', strtotime($c['last_message_at'])) : '' ?></span>
                                <button type="button" class="btn btn-sm btn-link p-0 text-muted" style="font-size:0.75rem;" title="Edit profil pelanggan" onclick="event.stopPropagation(); editPercakapanDariList(<?= (int) $c['id'] ?>)"><i class="fas fa-pen"></i></button>
                                <button type="button" class="btn btn-sm btn-link p-0 text-danger" style="font-size:0.75rem;" title="Hapus percakapan" onclick="event.stopPropagation(); hapusPercakapanDariList(<?= (int) $c['id'] ?>)"><i class="fas fa-trash-alt"></i></button>
                            </span>
                        </div>
                        <div class="list-preview">
                            <?= $c['last_message_direction'] === 'outgoing' ? '<i class="fas fa-reply fa-xs"></i> ' : '' ?>
                            <?= esc($c['manual_phone'] ?: $c['phone'] ?: ($c['jid_type'] === 'lid' ? 'LID' : $c['jid_type'])) ?>
                            <?php if ($c['status'] === 'closed'): ?>
                                <span class="badge bg-secondary" style="font-size: 0.6rem;">closed</span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
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

                <!-- ============================================ -->
                <!-- RIWAYAT HANDOFF (TB-03/TASK-010)              -->
                <!-- Muncul hanya kalau percakapan aktif punya      -->
                <!-- riwayat penyerahan; dimuat ulang saat pindah   -->
                <!-- percakapan, setelah Handoff sukses, dan        -->
                <!-- setelah permintaan Handoff kalah (409).        -->
                <!-- ============================================ -->
                <div class="inbox-handoff-panel" id="panelRiwayatHandoff" style="display:none;">
                    <div class="inbox-handoff-judul"><i class="fas fa-share-square"></i> Riwayat Penyerahan</div>
                    <div id="daftarRiwayatHandoff"></div>
                </div>

                <div class="inbox-thread-messages" id="threadMessages">
                    <div class="inbox-thread-empty">
                        <i class="fas fa-comments fa-2x me-2"></i> Belum ada percakapan dipilih.
                    </div>
                </div>

                <div class="inbox-thread-form">
                    <div id="previewMediaBalasan" class="mb-2" style="display:none;">
                        <span class="badge bg-light text-dark border">
                            <i class="fas fa-paperclip"></i> <span id="previewMediaNama"></span>
                            <button type="button" class="btn-close btn-sm ms-1" style="font-size:0.6rem;" onclick="batalkanMediaBalasan()"></button>
                        </span>
                    </div>
                    <form id="formBalas" onsubmit="return kirimBalasan(event)">
                        <div class="input-group">
                            <button class="btn btn-outline-secondary" type="button" id="btnLampirkanMedia" disabled onclick="document.getElementById('inputMediaBalasan').click()">
                                <i class="fas fa-paperclip"></i>
                            </button>
                            <input type="file" id="inputMediaBalasan" style="display:none;" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" onchange="pilihMediaBalasan(event)">
                            <textarea class="form-control" id="teksBalasan" rows="1" placeholder="Pilih percakapan dulu..." disabled></textarea>
                            <button class="btn btn-success" type="submit" id="btnKirimBalasan" disabled>
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
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
                                    <option value="<?= (int) $kasir['id'] ?>"><?= esc($kasir['nama'] ?: ('Kasir #' . $kasir['id'])) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            Hanya kasir aktif. Kalau kasir ini dinonaktifkan setelah dialog dibuka,
                            server akan menolak (403) dan percakapan tidak berpindah.
                        </small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ringkasan Keadaan Percakapan (wajib)</label>
                        <textarea class="form-control" id="handoffSummary" rows="3" maxlength="4096" required
                            placeholder="Contoh: customer tanya harga grosir, sudah dikirim price list v3."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tindakan Lanjutan yang Diharapkan (wajib)</label>
                        <textarea class="form-control" id="handoffNextAction" rows="2" maxlength="4096" required
                            placeholder="Contoh: follow up besok pagi kalau belum ada balasan."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan (opsional)</label>
                        <textarea class="form-control" id="handoffNote" rows="2" maxlength="4096"
                            placeholder="Catatan bebas antar staff, tidak terkirim ke pelanggan."></textarea>
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

<script>
    // ================================================================
    // STATE
    // ================================================================
    let conversationAktif = null;
    let conversationUntukHapus = null; // target hapus dari row daftar kiri, terpisah dari conversationAktif
    const mediaGagal = new Set(); // id pesan yang medianya sudah dipastikan gagal dimuat -- reset tiap reload halaman (cukup untuk 1 sesi kerja, tidak perlu persisten di frontend).
    let gatewayTerhubung = true; // Tahap F -- optimistic default sebelum poll pertama datang
    let daftarConversation = <?= json_encode($conversations) ?>;
    // Queue View: tab aktif hanya memilih hasil dari queue_status yang
    // sudah dihitung backend oleh ConversationModel::withComputedStatus().
    // Tidak ada perhitungan status di client-side.
    let filterAktif = 'belum_diambil';
    const currentUserId = <?= (int) $currentUserId ?>;
    const currentUserRole = <?= json_encode($currentUserRole) ?>;

    // ================================================================
    // UTIL
    // ================================================================
    function escapeHtmlInbox(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function formatWaktuInbox(iso) {
        if (!iso) return '';
        const d = new Date(iso.replace(' ', 'T'));
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleString('id-ID', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    // Cari conversation berdasarkan id. Perbandingan via String() SENGAJA
    // -- kolom `id` dari CodeIgniter/MySQLi kadang berupa string ("45"),
    // sedangkan id di JS (dari onclick, parameter fetch, dst) berupa
    // number -- "45" === 45 selalu false, bikin conv "tidak ketemu" tanpa
    // error apa pun (root cause pernah bikin modal edit/header/highlight
    // aktif semuanya diam-diam kosong). Satu fungsi ini dipakai di semua
    // pemanggil supaya perbaikannya tidak perlu diulang di tiap tempat.
    function cariConversation(id) {
        return daftarConversation.find(function(c) { return String(c.id) === String(id); });
    }

    // ================================================================
    // DAFTAR CONVERSATION
    // ================================================================
    // Tombol filter Queue View -- state visual saja, tidak mengubah
    // conversationAktif/daftarConversation itu sendiri.
    const QUEUE_STATUS_LABEL = {
        belum_diambil: 'Belum Diambil',
        open: 'Open',
        menunggu: 'Menunggu',
        ditunda: 'Ditunda',
        selesai: 'Selesai',
    };

    function renderFilterButtons() {
        Object.keys(QUEUE_STATUS_LABEL).forEach(function(f) {
            const idSuffix = f.charAt(0).toUpperCase() + f.slice(1);
            const btn = document.getElementById('btnFilter' + idSuffix);
            if (btn) btn.classList.toggle('active', filterAktif === f);
        });
    }

    function renderTabCounts() {
        const counts = {};
        Object.keys(QUEUE_STATUS_LABEL).forEach(function(status) {
            counts[status] = daftarConversation.filter(function(c) {
                return c.queue_status === status;
            }).length;
        });

        document.querySelectorAll('.tab-count').forEach(function(el) {
            const status = el.getAttribute('data-count-for');
            el.textContent = counts[status] ?? 0;
        });
    }

    function setFilterConversation(filter) {
        filterAktif = filter;
        renderFilterButtons();
        renderDaftarConversation();
    }

    function renderBadgePerluDibalas() {
        const badge = document.getElementById('perluDibalasBadge');
        const jumlah = daftarConversation.filter(function(c) {
            return c.queue_status === 'belum_diambil' || c.queue_status === 'open';
        }).length;
        if (jumlah > 0) {
            badge.textContent = jumlah + ' Perlu Dibalas';
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    }

    const RESPONSE_STATE_LABEL = {
        perlu_dibalas: { text: 'Perlu Dibalas', kelas: 'bg-danger' },
        menunggu_customer: { text: 'Menunggu Customer', kelas: 'bg-info text-dark' },
        follow_up: { text: 'Follow-up', kelas: 'bg-warning text-dark' },
        selesai: { text: 'Selesai', kelas: 'bg-secondary' },
    };

    function renderDaftarConversation() {
        renderFilterButtons();
        renderTabCounts();
        renderBadgePerluDibalas();

        const panel = document.getElementById('inboxListPanel');
        const daftarTampil = daftarConversation.filter(function(c) {
            return c.queue_status === filterAktif;
        });

        if (!daftarTampil.length) {
            panel.innerHTML = '<div class="p-3 text-muted small text-center">' +
                (daftarConversation.length ? 'Tidak ada percakapan ' + (QUEUE_STATUS_LABEL[filterAktif] || filterAktif) + '.' : 'Belum ada percakapan masuk.') +
                '</div>';
            return;
        }

        panel.innerHTML = daftarTampil.map(function(c) {
            const activeClass = (String(c.id) === String(conversationAktif)) ? ' active' : '';
            const nama = c.contact_name || c.whatsapp_name || c.phone || c.chat_id;
            const waktu = c.last_message_at ? formatWaktuInbox(c.last_message_at) : '';
            const panah = c.last_message_direction === 'outgoing' ? '<i class="fas fa-reply fa-xs"></i> ' : '';
            const closedBadge = c.status === 'closed' ? '<span class="badge bg-secondary" style="font-size:0.6rem;">closed</span>' : '';
            const rsInfo = RESPONSE_STATE_LABEL[c.response_state];
            const responseStateBadge = rsInfo ? '<span class="badge ' + rsInfo.kelas + '" style="font-size:0.6rem;">' + rsInfo.text + '</span>' : '';
            // String() SENGAJA -- assigned_to dari MySQLi/JSON kadang
            // string ("3"), currentUserId number -- lihat catatan
            // cariConversation() di atas untuk root cause bug yang sama.
            const assignBadge = c.assigned_to
                ? '<span class="badge ' + (String(c.assigned_to) === String(currentUserId) ? 'bg-info' : 'bg-light text-dark border') + '" style="font-size:0.6rem;">' +
                  '<i class="fas fa-user"></i> Dipegang: ' + escapeHtmlInbox(c.assigned_to_name || ('User #' + c.assigned_to)) + '</span>'
                : '<span class="badge bg-light text-muted border" style="font-size:0.6rem;">Belum diambil</span>';

            const nomorAtauLid = c.manual_phone || c.phone || (c.jid_type === 'lid' ? 'LID' : c.jid_type);

            return '<a href="#" class="inbox-list-item' + activeClass + '" onclick="return pilihConversation(' + c.id + ')">' +
                '<div class="d-flex justify-content-between align-items-start">' +
                '<span class="list-name">' + escapeHtmlInbox(nama) + '</span>' +
                '<span class="d-flex align-items-center gap-1">' +
                '<span class="list-time">' + escapeHtmlInbox(waktu) + '</span>' +
                '<button type="button" class="btn btn-sm btn-link p-0 text-muted" style="font-size:0.75rem;" title="Edit profil pelanggan" onclick="event.stopPropagation(); editPercakapanDariList(' + c.id + ')"><i class="fas fa-pen"></i></button>' +
                '<button type="button" class="btn btn-sm btn-link p-0 text-danger" style="font-size:0.75rem;" title="Hapus percakapan" onclick="event.stopPropagation(); hapusPercakapanDariList(' + c.id + ')"><i class="fas fa-trash-alt"></i></button>' +
                '</span>' +
                '</div>' +
                '<div class="list-preview">' + panah + escapeHtmlInbox(nomorAtauLid) + ' ' + closedBadge + ' ' + responseStateBadge + ' ' + assignBadge + '</div>' +
                '</a>';
        }).join('');
    }

    function muatUlangDaftarConversation() {
        fetch('<?= base_url('/inbox/api/conversations') ?>')
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    daftarConversation = json.conversations;
                    renderDaftarConversation();
                    renderThreadHeader();
                }
            })
            .catch(function() {
                // Diamkan -- polling berikutnya akan coba lagi. Tidak
                // perlu toast tiap gagal 1 siklus, cukup mengganggu.
            });
    }

    // ================================================================
    // IDENTITAS CUSTOMER: "Nama (No. Telepon)" / "Nama (LID)" -- TIDAK
    // PERNAH menampilkan conversation_id ("#45") sebagai identitas
    // utama, dan TIDAK PERNAH memperlakukan digit @lid sebagai nomor
    // telepon (lihat business rule reconciliation LID<->PN).
    // ================================================================
    function formatIdentitasCustomer(conv) {
        if (!conv) return '';

        const nama = conv.contact_name || conv.whatsapp_name || null;
        const nomor = conv.manual_phone || conv.phone || null;

        if (nama && nomor) return nama + ' (' + nomor + ')';
        if (nomor) return nomor;
        if (nama) return nama + (conv.jid_type === 'lid' ? ' (LID)' : '');
        if (conv.jid_type === 'lid') return 'LID';
        return conv.chat_id;
    }

    // ================================================================
    // HEADER THREAD (identitas customer + badge/tombol assignment)
    // ================================================================
    function renderThreadHeader() {
        if (!conversationAktif) return;

        // Jangan render ulang selagi dropdown Follow-up terbuka -- polling
        // 6 detik (muatUlangDaftarConversation) mengganti innerHTML header
        // dan menutup paksa dropdown sebelum sempat diklik.
        if (document.querySelector('#threadHeader .dropdown-menu.show')) return;

        const conv = cariConversation(conversationAktif);
        const identitas = formatIdentitasCustomer(conv);

        let infoAssign = '';
        let tombolAssign = '';

        if (conv && conv.assigned_to) {
            // String() SENGAJA -- lihat catatan cariConversation() di atas
            // (root cause bug: assigned_to string dari server vs
            // currentUserId number, "3" === 3 selalu false).
            const punyaSaya = String(conv.assigned_to) === String(currentUserId);
            infoAssign = ' <span class="badge ' + (punyaSaya ? 'bg-info' : 'bg-light text-dark border') + '">' +
                '<i class="fas fa-user"></i> Dipegang: ' + escapeHtmlInbox(conv.assigned_to_name || ('User #' + conv.assigned_to)) + '</span>';

            if (punyaSaya || currentUserRole === 'admin') {
                tombolAssign = '<button type="button" class="btn btn-sm btn-outline-secondary me-1" title="Lepas percakapan" onclick="lepasPercakapan()">' +
                    '<i class="fas fa-user-slash"></i> Lepas</button>';
            }
        } else {
            infoAssign = ' <span class="badge bg-light text-muted border">Belum diambil</span>';
            tombolAssign = '<button type="button" class="btn btn-sm btn-outline-primary me-1" title="Ambil percakapan" onclick="ambilPercakapan()">' +
                '<i class="fas fa-user-plus"></i> Ambil</button>';
        }

        // Revisi LID-FIRST -> PN-LATER: tombol konfirmasi nomor manual
        // HANYA relevan kalau conversation ini @lid DAN belum punya
        // `phone` ter-verifikasi (kalau sudah ada, reconciliation
        // otomatis/sebelumnya sudah menanganinya).
        const tombolKonfirmasiNomor = (conv && conv.jid_type === 'lid' && !conv.phone)
            ? ' <button type="button" class="btn btn-sm btn-link p-0 text-warning" style="font-size:0.75rem;" title="Konfirmasi nomor WhatsApp customer ini" onclick="bukaModalKonfirmasiNomor()">' +
              '<i class="fas fa-shield-alt"></i> Konfirmasi Nomor</button>'
            : '';

        // Tahap 1 lifecycle status (Section 12): tombol "Tutup" HANYA
        // muncul kalau conversation sedang OPEN -- tidak ada tombol
        // "Open" manual (reopen cuma lewat pesan masuk baru, lihat
        // InboxGatewayApi::messages()).
        const badgeStatus = conv
            ? ' <span class="badge ' + (conv.status === 'closed' ? 'bg-secondary' : 'bg-success') + '">' + conv.status.toUpperCase() + '</span>'
            : '';
        const tombolTutup = (conv && conv.status === 'open')
            ? '<button type="button" class="btn btn-sm btn-outline-danger me-1" title="Tutup percakapan" onclick="tutupPercakapan()">' +
              '<i class="fas fa-times-circle"></i> Tutup</button>'
            : '';

        // Response state (Langkah 9): "Tandai Dibaca" (perlu_dibalas ->
        // menunggu_customer) dan "Follow-up" (snooze sementara) -- keduanya
        // dipanggil lewat endpoint Langkah 5 & 6.
        const tombolTandaiDibaca = (conv && conv.response_state === 'perlu_dibalas')
            ? '<button type="button" class="btn btn-sm btn-outline-success me-1" title="Tandai sudah dibaca" onclick="tandaiDibacaAktif()">' +
              '<i class="fas fa-check"></i> Tandai Dibaca</button>'
            : '';
        // M3 Fase 2a (TB-01/TASK-004): tombol Handoff hanya untuk
        // percakapan eligible + inisiator yang diizinkan server
        // (assignee saat ini, atau kasir aktif pada belum_diambil) --
        // Q1/P-05 dicerminkan di UI supaya tidak menawarkan aksi 403.
        const dapatHandoff = conv && conv.queue_status !== 'selesai' && (
            (conv.assigned_to && String(conv.assigned_to) === String(currentUserId)) ||
            (!conv.assigned_to && currentUserRole === 'kasir')
        );
        const tombolHandoff = dapatHandoff
            ? '<button type="button" class="btn btn-sm btn-outline-primary me-1" title="Serahkan percakapan ke kasir lain" onclick="bukaModalHandoff()">' +
              '<i class="fas fa-share-square"></i> Handoff</button>'
            : '';

        const tombolFollowUp =
            '<div class="btn-group me-1">' +
            '<button type="button" class="btn btn-sm btn-outline-warning dropdown-toggle" data-bs-toggle="dropdown" title="Follow-up nanti">' +
            '<i class="fas fa-clock"></i> Follow-up</button>' +
            '<ul class="dropdown-menu dropdown-menu-end">' +
            '<li><a class="dropdown-item" href="#" onclick="return snoozePercakapanAktif(60)">1 jam</a></li>' +
            '<li><a class="dropdown-item" href="#" onclick="return snoozePercakapanAktif(180)">3 jam</a></li>' +
            '<li><a class="dropdown-item" href="#" onclick="return snoozePercakapanAktif(' + menitSampaiBesokPagi() + ')">Besok pagi</a></li>' +
            (conv && conv.snoozed_until ? '<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="#" onclick="return snoozePercakapanAktif(0)">Batal</a></li>' : '') +
            '</ul></div>';

        // Edit & Hapus TIDAK lagi tampil di header -- dipindah ke masing-
        // masing row percakapan di daftar kiri (lihat editPercakapanDariList()/
        // hapusPercakapanDariList()).
        document.getElementById('threadHeader').innerHTML =
            '<span><strong>' + escapeHtmlInbox(identitas) + '</strong>' +
            badgeStatus +
            tombolKonfirmasiNomor +
            infoAssign +
            '</span>' +
            '<span>' + tombolTandaiDibaca + tombolHandoff + tombolFollowUp + tombolTutup + tombolAssign + '</span>';
    }

    // Menit dari sekarang sampai jam 08:00 hari berikutnya -- dipakai
    // opsi "Besok pagi" di dropdown Follow-up.
    function menitSampaiBesokPagi() {
        const now = new Date();
        const besokPagi = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 8, 0, 0);
        return Math.round((besokPagi.getTime() - now.getTime()) / 60000);
    }

    function tandaiDibacaAktif() {
        if (!conversationAktif) return;

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/tandai-dibaca', { method: 'POST' })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    muatUlangDaftarConversation();
                } else {
                    showToast(json.message || 'Gagal menandai dibaca.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            });
    }

    function snoozePercakapanAktif(menit) {
        if (!conversationAktif) return false;

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/snooze', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ menit: menit })
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    showToast(menit > 0 ? 'Percakapan di-follow-up.' : 'Follow-up dibatalkan.', 'success');
                    muatUlangDaftarConversation();
                } else {
                    showToast(json.message || 'Gagal follow-up.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            });

        return false;
    }

    function tutupPercakapan() {
        if (!conversationAktif) return;

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/tutup', { method: 'POST' })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    showToast('Percakapan ditutup.', 'success');
                    const idx = daftarConversation.findIndex(function(c) { return String(c.id) === String(conversationAktif); });
                    if (idx !== -1) daftarConversation[idx] = json.conversation;
                    renderDaftarConversation();
                    renderThreadHeader();
                } else {
                    showToast(json.message || 'Gagal menutup percakapan.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            });
    }

    function ambilPercakapan() {
        if (!conversationAktif) return;

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/ambil', { method: 'POST' })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    showToast('Percakapan berhasil diambil.', 'success');
                    muatUlangDaftarConversation();
                } else {
                    showToast(json.message || 'Gagal mengambil percakapan.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            });
    }

    function lepasPercakapan() {
        if (!conversationAktif) return;

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/lepas', { method: 'POST' })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    showToast('Percakapan dilepas.', 'success');
                    muatUlangDaftarConversation();
                } else {
                    showToast(json.message || 'Gagal melepas percakapan.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            });
    }

    // ================================================================
    // HANDOFF (M3 Fase 2a, TB-01/TASK-004)
    // ================================================================
    // expected_owner dibaca SAAT DIALOG DIBUKA (bukan saat submit) --
    // itulah nilai assigned_to yang "dilihat" kasir; server memakainya
    // sebagai syarat conditional write (`assigned_to <=> expected`),
    // sehingga bentrok dua staff otomatis ditolak 409 (K-01/Q5).
    let handoffExpectedOwner = '';

    // Penjaga dobel-klik (TASK-007/D-03): selama satu request masih
    // terbang, submit kedua diabaikan. Tombol submit juga di-disable saat
    // request berjalan; flag ini menutup celah submit lewat Enter/klik
    // sangat cepat sebelum disable sempat terpasang.
    let handoffSedangKirim = false;

    // Kode HTTP balasan Handoff terakhir -- dipakai untuk membedakan
    // jalur kalah conditional write (409) dari penolakan lain, supaya
    // panel riwayat hanya dimuat ulang saat keadaan server memang berubah.
    let handoffStatusHttp = 0;

    function bukaModalHandoff() {
        if (!conversationAktif) return;

        const conv = cariConversation(conversationAktif);
        if (!conv) return;

        // null/undefined -> string kosong = "saya lihat belum diambil".
        handoffExpectedOwner = (conv.assigned_to === null || conv.assigned_to === undefined)
            ? ''
            : String(conv.assigned_to);

        document.getElementById('formHandoff').reset();
        document.getElementById('handoffAlert').classList.add('d-none');
        // Sisa keadaan dari percobaan sebelumnya tidak boleh terbawa.
        handoffSedangKirim = false;
        document.getElementById('btnKirimHandoff').disabled = false;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHandoff')).show();
    }

    // Pesan server dipakai APA ADANYA -- pada 409 pesan itu sudah memuat
    // nama pemilik sah (atau fallback "User #{id}"), sehingga kasir tahu
    // harus berkoordinasi dengan siapa. Ditulis ke elemen pesan supaya
    // tombol "Muat ulang" di kotak yang sama tidak ikut terhapus.
    function tampilkanNoticeHandoff(pesan) {
        document.getElementById('handoffAlertMessage').textContent = pesan;
        document.getElementById('handoffAlert').classList.remove('d-none');
    }

    // Aksi satu-klik untuk kasir yang kalah (TASK-007): segarkan daftar
    // Queue + header dari server supaya UI tidak menampilkan keadaan basi,
    // lalu tutup dialog agar nilai expected_owner lama tidak dikirim ulang.
    // Percobaan ulang apa pun tetap ditolak 409 oleh server (K-09).
    function muatUlangSetelahHandoffBasi() {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHandoff')).hide();
        muatUlangDaftarConversation();
        showToast('Daftar percakapan dimuat ulang.', 'info');
    }

    function kirimHandoff(e) {
        e.preventDefault();
        if (!conversationAktif) return false;

        // Dobel-klik/Enter berulang aman: selama request pertama masih
        // terbang, submit kedua diabaikan (lihat handoffSedangKirim).
        // Kalau request pertama ternyata kalah 409, klik ulang setelahnya
        // tetap ditolak 409 oleh server (K-09) -- tidak mungkin ada dua
        // pemilik sekaligus.
        if (handoffSedangKirim) return false;

        const target = document.getElementById('handoffTarget').value;
        const summary = document.getElementById('handoffSummary').value.trim();
        const nextAction = document.getElementById('handoffNextAction').value.trim();
        const note = document.getElementById('handoffNote').value.trim();
        const btn = document.getElementById('btnKirimHandoff');

        if (!target || !summary || !nextAction) {
            showToast('Target, ringkasan, dan tindakan lanjutan wajib diisi.', 'warning');
            return false;
        }

        handoffSedangKirim = true;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyerahkan...';

        const body = 'to_user_id=' + encodeURIComponent(target)
            + '&summary=' + encodeURIComponent(summary)
            + '&next_action=' + encodeURIComponent(nextAction)
            + '&note=' + encodeURIComponent(note)
            + '&expected_owner=' + encodeURIComponent(handoffExpectedOwner);

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/handoff', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            })
            .then(function(res) {
                handoffStatusHttp = res.status;
                return res.json();
            })
            .then(function(json) {
                if (json.status === 'success') {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHandoff')).hide();
                    showToast(json.message || 'Percakapan berhasil diserahkan.', 'success');
                    muatUlangDaftarConversation();
                    // Sukses = riwayat pasti bertambah satu entri (REQ-H07),
                    // jadi panel riwayat ikut disegarkan.
                    muatUlangRiwayatHandoff();
                } else {
                    // 400 (validasi), 403 (inisiator/target), 409 (kalah
                    // conditional write / percakapan selesai) -- pesan
                    // server dipakai apa adanya; 409 menyebut nama
                    // pemilik sah supaya kasir tahu harus koordinasi
                    // dengan siapa.
                    tampilkanNoticeHandoff(json.message || 'Gagal menyerahkan percakapan.');
                    showToast(json.message || 'Gagal menyerahkan percakapan.', 'danger');

                    // 409 = kalah conditional write: owner (dan mungkin
                    // riwayat) sudah berubah di server -- segarkan panel
                    // supaya tidak menampilkan keadaan basi.
                    if (handoffStatusHttp === 409) {
                        muatUlangRiwayatHandoff();
                    }
                }
            })
            .catch(function(err) {
                tampilkanNoticeHandoff('Gagal menghubungi server: ' + err.message);
            })
            .finally(function() {
                handoffSedangKirim = false;
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-share-square"></i> Serahkan';
            });

        return false;
    }

    // ================================================================
    // RIWAYAT HANDOFF (TB-03/TASK-010) -- panel daftar penyerahan
    // ================================================================
    // Nama staff di-resolve dari daftarKasir yang MEMANG sudah ada di
    // halaman ini (sumber Q6, sama dengan dropdown dialog Handoff) --
    // kontrak GET (P-04) sengaja hanya mengirim id, jadi tidak ada field
    // nama di response. Id di luar daftar (mis. akun non-aktif/dihapus)
    // jatuh ke 'Kasir #id', sejalan dengan fallback "User #{id}" di server.
    const namaKasirById = <?= json_encode((object) array_column($daftarKasir ?? [], 'nama', 'id'), JSON_UNESCAPED_UNICODE) ?>;

    function namaStaffHandoff(id) {
        if (id === null || id === undefined || id === '') return 'Belum diambil';

        const nama = namaKasirById[String(id)];

        return nama ? String(nama) : ('Kasir #' + id);
    }

    function renderRiwayatHandoff(handoffs) {
        const panel = document.getElementById('panelRiwayatHandoff');
        const wadah = document.getElementById('daftarRiwayatHandoff');

        if (!handoffs || handoffs.length === 0) {
            panel.style.display = 'none';
            wadah.innerHTML = '';
            return;
        }

        wadah.innerHTML = handoffs.map(function(h) {
            const dari = (h.from_user_id === null || h.from_user_id === undefined || h.from_user_id === '')
                ? 'Belum diambil'
                : namaStaffHandoff(h.from_user_id);

            return '<div class="inbox-handoff-item">' +
                '<div><strong>' + escapeHtmlInbox(dari) + '</strong> &rarr; ' + escapeHtmlInbox(namaStaffHandoff(h.to_user_id)) +
                ' <span class="text-muted">oleh ' + escapeHtmlInbox(namaStaffHandoff(h.initiated_by_user_id)) +
                ', ' + escapeHtmlInbox(formatWaktuInbox(h.created_at)) + '</span></div>' +
                '<div>' + escapeHtmlInbox(h.summary) + '</div>' +
                '<div class="text-muted">Tindakan lanjutan: ' + escapeHtmlInbox(h.next_action) + '</div>' +
                (h.note ? '<div class="text-muted fst-italic">Catatan: ' + escapeHtmlInbox(h.note) + '</div>' : '') +
                '</div>';
        }).join('');

        panel.style.display = 'block';
    }

    // Dipanggil saat pindah percakapan, setelah Handoff sukses, dan
    // setelah permintaan Handoff kalah 409 (owner/riwayat sudah berubah
    // di server). Tanpa percakapan aktif panel disembunyikan.
    function muatUlangRiwayatHandoff() {
        if (!conversationAktif) {
            renderRiwayatHandoff([]);
            return;
        }

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/handoff')
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    renderRiwayatHandoff(json.handoffs);
                }
            })
            .catch(function() {
                // Diamkan -- pemuatan berikutnya (pindah percakapan atau
                // selesai Handoff) mencoba lagi.
            });
    }

    // ================================================================
    // PILIH CONVERSATION & RIWAYAT PESAN
    // ================================================================
    function pilihConversation(id) {
        conversationAktif = id;
        renderDaftarConversation();
        renderThreadHeader();

        document.getElementById('teksBalasan').disabled = false;
        document.getElementById('teksBalasan').placeholder = 'Ketik balasan...';
        document.getElementById('btnKirimBalasan').disabled = false;
        document.getElementById('btnLampirkanMedia').disabled = false;
        document.getElementById('teksBalasan').focus();

        muatUlangPesan(true);
        muatUlangRiwayatHandoff();
        return false;
    }

    function renderIsiPesan(m) {
        const urlMedia = '<?= base_url('/inbox/media/') ?>' + m.id;

        if (m.message_type === 'image') {
            // Tahap E: sekali gagal, JANGAN buat tag <img> lagi sama
            // sekali untuk pesan ini -- mencegah polling 4 detik terus
            // meminta ulang media yang sudah dipastikan kadaluarsa.
            if (mediaGagal.has(m.id)) {
                return '<div class="inbox-media-unavailable"><i class="fas fa-image"></i> Gambar tidak tersedia (kemungkinan sudah kadaluarsa)</div>' +
                    (m.text ? '<div class="inbox-media-caption">' + escapeHtmlInbox(m.text) + '</div>' : '');
            }
            // Tahap F -- jangan buat <img> sama sekali kalau sudah TAHU
            // bakal gagal (belum ada di disk lokal DAN Gateway terputus)
            // -- beda dari mediaGagal (baru tahu SETELAH gagal request).
            // Pesan placeholder SENGAJA beda dari yang di atas (410
            // kadaluarsa, Tahap E) supaya kasir tidak bingung 2 penyebab
            // berbeda dikira sama.
            if (!gatewayTerhubung && !m.media_local_filename) {
                return '<div class="inbox-media-unavailable"><i class="fas fa-wifi"></i> Gateway terputus -- gambar belum bisa dimuat, coba lagi nanti</div>' +
                    (m.text ? '<div class="inbox-media-caption">' + escapeHtmlInbox(m.text) + '</div>' : '');
            }
            // onerror: media bisa saja sudah kadaluarsa di server WhatsApp
            // (lihat catatan desain -- kita cuma simpan referensi, bukan
            // file permanen) -- tampilkan placeholder yang jelas, bukan
            // ikon "broken image" generik browser.
            return '<img src="' + urlMedia + '" alt="Gambar" class="inbox-media-image" ' +
                'onerror="mediaGagal.add(' + m.id + '); this.outerHTML=\'<div class=&quot;inbox-media-unavailable&quot;><i class=&quot;fas fa-image&quot;></i> Gambar tidak tersedia (kemungkinan sudah kadaluarsa)</div>\'">' +
                (m.text ? '<div class="inbox-media-caption">' + escapeHtmlInbox(m.text) + '</div>' : '');
        }

        if (m.message_type === 'sticker') {
            // Sticker TIDAK PERNAH punya caption di WhatsApp -- beda dari
            // image/document, tidak perlu render m.text sama sekali.
            if (mediaGagal.has(m.id)) {
                return '<div class="inbox-media-unavailable"><i class="fas fa-icons"></i> Sticker tidak tersedia (kemungkinan sudah kadaluarsa)</div>';
            }
            // Tahap F -- lihat catatan sama di blok image di atas.
            if (!gatewayTerhubung && !m.media_local_filename) {
                return '<div class="inbox-media-unavailable"><i class="fas fa-wifi"></i> Gateway terputus -- sticker belum bisa dimuat, coba lagi nanti</div>';
            }
            return '<img src="' + urlMedia + '" alt="Sticker" class="inbox-media-sticker" ' +
                'onerror="mediaGagal.add(' + m.id + '); this.outerHTML=\'<div class=&quot;inbox-media-unavailable&quot;><i class=&quot;fas fa-icons&quot;></i> Sticker tidak tersedia (kemungkinan sudah kadaluarsa)</div>\'">';
        }

        if (m.message_type === 'document') {
            const namaFile = m.media_filename || 'Dokumen';
            return '<a href="' + urlMedia + '" target="_blank" class="inbox-media-document">' +
                '<i class="fas fa-file-alt"></i> ' + escapeHtmlInbox(namaFile) +
                '</a>' +
                (m.text ? '<div class="inbox-media-caption">' + escapeHtmlInbox(m.text) + '</div>' : '');
        }

        // Audio (termasuk voice note -- tidak dibedakan, lihat catatan
        // desain di InboxGatewayApi::messages()) dan video: SENGAJA TIDAK
        // ADA player/download sama sekali -- binary-nya tidak pernah
        // diambil AuliaPos maupun Gateway, cukup placeholder yang jelas
        // + caption (kalau ada). Kasir yang perlu dengar/lihat isinya
        // buka langsung dari WhatsApp Web/HP toko.
        if (m.message_type === 'audio' || m.message_type === 'video') {
            const label = m.message_type === 'audio' ? 'audio' : 'video';
            const icon = m.message_type === 'audio' ? 'fa-microphone' : 'fa-video';
            return '<div class="inbox-media-unavailable" style="font-style:normal;">' +
                '<i class="fas ' + icon + '"></i> Customer mengirim ' + label + ' — cek WhatsApp Web.' +
                '</div>' +
                (m.text ? '<div class="inbox-media-caption">' + escapeHtmlInbox(m.text) + '</div>' : '');
        }

        return escapeHtmlInbox(m.text);
    }

    function renderPesan(messages, paksaScroll) {
        const container = document.getElementById('threadMessages');

        // Tentukan SEBELUM innerHTML diganti -- scrollHeight/scrollTop
        // lama masih relevan di sini. Toleransi 80px: kasir yang sudah
        // scroll ke atas membaca pesan lama TIDAK boleh diseret balik
        // ke bawah oleh polling (setiap 4 detik); tapi kalau memang
        // sudah dekat bawah (baru geser dikit / belum sempat scroll),
        // tetap auto-scroll seperti biasa supaya pesan baru terlihat.
        const dekatBawah = (container.scrollHeight - container.scrollTop - container.clientHeight) <= 80;
        const harusScroll = !!paksaScroll || dekatBawah;

        if (!messages.length) {
            container.innerHTML = '<div class="inbox-thread-empty"><i class="fas fa-comment-dots fa-2x me-2"></i> Belum ada pesan di percakapan ini.</div>';
            if (harusScroll) container.scrollTop = container.scrollHeight;
            return;
        }

        container.innerHTML = messages.map(function(m) {
            const internal = m.is_internal === true || m.is_internal === 1 || m.is_internal === '1';
            const arah = internal ? 'internal-note' : (m.direction === 'outgoing' ? 'outgoing' : 'incoming');
            const senderLabel = (m.sender_name)
                ? '<div class="bubble-sender">' + escapeHtmlInbox(m.sender_name) + '</div>'
                : '';
            const internalLabel = internal
                ? '<div class="inbox-internal-label"><i class="fas fa-sticky-note"></i> Internal</div>'
                : '';

            return '<div class="inbox-bubble ' + arah + '">' +
                internalLabel +
                senderLabel +
                renderIsiPesan(m) +
                '<div class="bubble-meta">' + formatWaktuInbox(m.message_timestamp) + '</div>' +
                '</div>';
        }).join('');

        if (harusScroll) {
            container.scrollTop = container.scrollHeight;
        }
    }

    function muatUlangPesan(scrollPaksa) {
        if (!conversationAktif) return;

        fetch('<?= base_url('/inbox/api/conversations') ?>/' + conversationAktif + '/messages')
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    renderPesan(json.messages, scrollPaksa);
                }
            })
            .catch(function() {
                // Diamkan, siklus polling berikutnya coba lagi.
            });
    }

    // ================================================================
    // CHAT BARU (ke nomor yang belum pernah masuk)
    // ================================================================
    function mulaiChatBaru(e) {
        e.preventDefault();

        if (!gatewayTerhubung) {
            alert('Gateway terputus -- pesan belum bisa dikirim sekarang. Draft Anda tetap tersimpan, coba lagi begitu status kembali "Terhubung".');
            return false;
        }

        const nomor = document.getElementById('nomorChatBaru').value.trim();
        const text = document.getElementById('teksChatBaru').value.trim();
        const btn = document.getElementById('btnChatBaru');

        if (!nomor || !text) {
            showToast('Nomor dan pesan wajib diisi.', 'warning');
            return false;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Mengirim...';

        fetch('<?= base_url('/inbox/mulai-percakapan') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'phone=' + encodeURIComponent(nomor) + '&text=' + encodeURIComponent(text)
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    showToast('Chat berhasil dimulai!', 'success');

                    // Tutup modal, reset form.
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalChatBaru')).hide();
                    document.getElementById('formChatBaru').reset();

                    // Muat ulang daftar conversation, lalu langsung buka
                    // conversation yang baru dibuat/dipakai.
                    fetch('<?= base_url('/inbox/api/conversations') ?>')
                        .then(function(r) { return r.json(); })
                        .then(function(listJson) {
                            if (listJson.status === 'success') {
                                daftarConversation = listJson.conversations;
                                renderDaftarConversation();
                                pilihConversation(json.conversation_id);
                            }
                        });
                } else {
                    showToast(json.message || 'Gagal memulai chat.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Kirim & Mulai Chat';
            });

        return false;
    }

    // ================================================================
    // LAMPIRAN MEDIA (dipilih, siap dikirim bareng pesan berikutnya)
    // ================================================================
    let fileMediaBalasan = null;

    // Dipakai bersama oleh input file (klik paperclip) dan drag-and-drop --
    // satu tempat yang menetapkan file terlampir, supaya kedua jalur
    // selalu berperilaku identik.
    function terapkanFileMediaBalasan(file) {
        if (!file) return;

        fileMediaBalasan = file;
        document.getElementById('previewMediaNama').textContent = file.name;
        document.getElementById('previewMediaBalasan').style.display = 'block';
        document.getElementById('teksBalasan').placeholder = 'Caption (opsional)...';
    }

    function pilihMediaBalasan(e) {
        const file = e.target.files && e.target.files[0];
        terapkanFileMediaBalasan(file);
    }

    function batalkanMediaBalasan() {
        fileMediaBalasan = null;
        document.getElementById('inputMediaBalasan').value = '';
        document.getElementById('previewMediaBalasan').style.display = 'none';
        document.getElementById('teksBalasan').placeholder = 'Ketik balasan...';
    }

    // --- Drag-and-drop file ke panel chat (mirip WhatsApp Web) ---------
    // dragCounter menghitung dragenter/dragleave bersarang (browser
    // memicu dragleave setiap kali kursor pindah ke elemen ANAK di
    // dalam panel, bukan cuma saat benar-benar keluar panel) -- tanpa
    // ini, overlay akan flicker hilang-muncul saat drag melintasi
    // pesan/tombol di dalam panel.
    let dragCounterInbox = 0;

    (function initDropZoneInbox() {
        const panel = document.getElementById('inboxThreadPanel');
        const overlay = document.getElementById('inboxDropOverlay');

        panel.addEventListener('dragenter', function(e) {
            if (!conversationAktif) return;
            e.preventDefault();
            dragCounterInbox++;
            overlay.classList.add('active');
        });

        panel.addEventListener('dragover', function(e) {
            if (!conversationAktif) return;
            e.preventDefault(); // wajib, supaya browser mengizinkan drop
        });

        panel.addEventListener('dragleave', function(e) {
            dragCounterInbox = Math.max(0, dragCounterInbox - 1);
            if (dragCounterInbox === 0) overlay.classList.remove('active');
        });

        panel.addEventListener('drop', function(e) {
            e.preventDefault();
            dragCounterInbox = 0;
            overlay.classList.remove('active');

            if (!conversationAktif) return;

            const file = e.dataTransfer.files && e.dataTransfer.files[0];
            terapkanFileMediaBalasan(file);
        });
    })();

    function tampilkanBubbleOutgoing(m) {
        const container = document.getElementById('threadMessages');
        const emptyState = container.querySelector('.inbox-thread-empty');
        if (emptyState) container.innerHTML = '';

        container.insertAdjacentHTML('beforeend',
            '<div class="inbox-bubble outgoing">' +
            '<div class="bubble-sender">' + escapeHtmlInbox(m.sender_name) + '</div>' +
            renderIsiPesan(m) +
            '<div class="bubble-meta">' + formatWaktuInbox(m.message_timestamp) + '</div>' +
            '</div>');
        container.scrollTop = container.scrollHeight;
    }

    // ================================================================
    // EDIT/HAPUS DARI ROW DAFTAR PERCAKAPAN (panel kiri)
    // ================================================================

    // Edit: percakapan dibuka dulu (nama, pesan, header ikut render),
    // BARU modal edit profil dibuka -- reuse pilihConversation() +
    // bukaModalEditProfil() apa adanya, tidak ada logic baru di sini.
    function editPercakapanDariList(id) {
        pilihConversation(id);
        bukaModalEditProfil();
    }

    // Hapus: SENGAJA TIDAK memanggil pilihConversation() -- klik Hapus
    // pada row yang sedang tidak aktif tidak boleh ikut membuka
    // percakapan itu (beda dari Edit). Target hapus disimpan terpisah
    // dari conversationAktif (conversationUntukHapus) supaya tidak
    // mengganggu percakapan yang sedang dibuka di panel kanan.
    function hapusPercakapanDariList(id) {
        conversationUntukHapus = id;

        const conv = cariConversation(id);
        document.getElementById('namaHapusPercakapan').textContent = formatIdentitasCustomer(conv);

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHapusPercakapan')).show();
    }

    function konfirmasiHapusPercakapan() {
        if (!conversationUntukHapus) return;

        const idDihapus = conversationUntukHapus;
        const btn = document.getElementById('btnKonfirmasiHapusPercakapan');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menghapus...';

        fetch('<?= base_url('/inbox/percakapan/') ?>' + idDihapus + '/hapus', { method: 'POST' })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHapusPercakapan')).hide();
                    showToast('Percakapan berhasil dihapus.', 'success');

                    daftarConversation = daftarConversation.filter(function(c) { return String(c.id) !== String(idDihapus); });
                    renderDaftarConversation();

                    // Panel kanan HANYA direset kalau yang dihapus memang
                    // conversation yang sedang aktif/terbuka -- kalau kasir
                    // menghapus row lain, percakapan yang sedang dibuka
                    // tetap tampil apa adanya.
                    if (String(idDihapus) === String(conversationAktif)) {
                        conversationAktif = null;
                        document.getElementById('threadHeader').innerHTML = '<span class="text-muted">Pilih percakapan di sebelah kiri untuk mulai.</span>';
                        document.getElementById('threadMessages').innerHTML = '<div class="inbox-thread-empty"><i class="fas fa-comments fa-2x me-2"></i> Belum ada percakapan dipilih.</div>';
                        document.getElementById('teksBalasan').disabled = true;
                        document.getElementById('teksBalasan').placeholder = 'Pilih percakapan dulu...';
                        document.getElementById('btnKirimBalasan').disabled = true;
                        document.getElementById('btnLampirkanMedia').disabled = true;
                        batalkanMediaBalasan();
                        // conversationAktif sudah null -> panel riwayat
                        // percakapan yang dihapus ikut disembunyikan.
                        muatUlangRiwayatHandoff();
                    }

                    conversationUntukHapus = null;
                } else {
                    showToast(json.message || 'Gagal menghapus percakapan.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash-alt"></i> Ya, Hapus Permanen';
            });
    }

    // ================================================================
    // EDIT PROFIL PELANGGAN (Task Group 1.5 -- nama & nomor manual)
    // ================================================================
    function bukaModalEditProfil() {
        if (!conversationAktif) return;

        // Prefill dari data yang SUDAH ADA (sumber sama dengan yang dipakai
        // formatIdentitasCustomer()/daftar kiri) -- nama manual (contact_name)
        // menang, fallback ke whatsapp_name; nomor manual (manual_phone)
        // menang, fallback ke phone ter-verifikasi. Jangan biarkan kosong
        // kalau salah satu sumber itu sudah terisi.
        const conv = cariConversation(conversationAktif);
        document.getElementById('editProfilNama').value = (conv && (conv.contact_name || conv.whatsapp_name)) || '';
        document.getElementById('editProfilTelepon').value = (conv && (conv.manual_phone || conv.phone)) || '';

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditProfil')).show();
    }

    function simpanProfilPelanggan(e) {
        e.preventDefault();
        if (!conversationAktif) return false;

        const nama = document.getElementById('editProfilNama').value.trim();
        const telepon = document.getElementById('editProfilTelepon').value.trim();
        const btn = document.getElementById('btnSimpanProfil');

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyimpan...';

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/profil', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'customer_name=' + encodeURIComponent(nama) + '&phone=' + encodeURIComponent(telepon)
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditProfil')).hide();
                    showToast('Profil pelanggan disimpan.', 'success');

                    // Perbarui entri lokal langsung, tidak perlu menunggu polling.
                    const idx = daftarConversation.findIndex(function(c) { return String(c.id) === String(conversationAktif); });
                    if (idx !== -1) {
                        daftarConversation[idx] = json.conversation;
                    }
                    renderDaftarConversation();
                    renderThreadHeader();
                } else {
                    showToast(json.message || 'Gagal menyimpan profil.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Simpan';
            });

        return false;
    }

    // ================================================================
    // KONFIRMASI NOMOR WHATSAPP (revisi LID-FIRST -> PN-LATER)
    // ================================================================
    function bukaModalKonfirmasiNomor() {
        if (!conversationAktif) return;

        document.getElementById('konfirmasiNomorInput').value = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKonfirmasiNomor')).show();
    }

    function simpanKonfirmasiNomor(e) {
        e.preventDefault();
        if (!conversationAktif) return false;

        const nomor = document.getElementById('konfirmasiNomorInput').value.trim();
        const btn = document.getElementById('btnKonfirmasiNomor');

        if (!nomor) {
            showToast('Nomor wajib diisi.', 'warning');
            return false;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyimpan...';

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/konfirmasi-nomor', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'phone=' + encodeURIComponent(nomor)
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKonfirmasiNomor')).hide();
                    showToast('Nomor WhatsApp dikonfirmasi. Pesan berikutnya dari nomor ini akan otomatis masuk ke sini.', 'success');

                    const idx = daftarConversation.findIndex(function(c) { return String(c.id) === String(conversationAktif); });
                    if (idx !== -1) {
                        daftarConversation[idx] = json.conversation;
                    }
                    renderDaftarConversation();
                    renderThreadHeader();
                } else {
                    showToast(json.message || 'Gagal konfirmasi nomor.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-shield-alt"></i> Ya, Konfirmasi';
            });

        return false;
    }

    // ================================================================
    // KIRIM BALASAN (teks, atau media kalau ada lampiran dipilih)
    // ================================================================
    function kirimBalasan(e) {
        e.preventDefault();

        if (!gatewayTerhubung) {
            alert('Gateway terputus -- pesan belum bisa dikirim sekarang. Draft Anda tetap tersimpan, coba lagi begitu status kembali "Terhubung".');
            return false;
        }

        if (!conversationAktif) {
            showToast('Pilih percakapan dulu.', 'warning');
            return false;
        }

        if (fileMediaBalasan) {
            return kirimMediaBalasan();
        }

        const textarea = document.getElementById('teksBalasan');
        const btn = document.getElementById('btnKirimBalasan');
        const text = textarea.value.trim();

        if (!text) {
            showToast('Pesan tidak boleh kosong.', 'warning');
            return false;
        }

        btn.disabled = true;
        textarea.disabled = true;

        fetch('<?= base_url('/inbox/kirim') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'conversation_id=' + encodeURIComponent(conversationAktif) + '&text=' + encodeURIComponent(text)
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    textarea.value = '';
                    // Langsung tampilkan pesan baru tanpa nunggu polling
                    // (sesuai spec: outgoing langsung terlihat setelah sukses).
                    tampilkanBubbleOutgoing(json.message);
                    muatUlangDaftarConversation();
                } else {
                    showToast(json.message || 'Gagal mengirim pesan.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            })
            .finally(function() {
                btn.disabled = false;
                textarea.disabled = false;
                textarea.focus();
            });

        return false;
    }

    function kirimMediaBalasan() {
        if (!gatewayTerhubung) {
            alert('Gateway terputus -- pesan belum bisa dikirim sekarang. Draft Anda tetap tersimpan, coba lagi begitu status kembali "Terhubung".');
            return false;
        }

        const textarea = document.getElementById('teksBalasan');
        const btn = document.getElementById('btnKirimBalasan');
        const caption = textarea.value.trim();
        const file = fileMediaBalasan;

        btn.disabled = true;
        textarea.disabled = true;
        document.getElementById('btnLampirkanMedia').disabled = true;

        const formData = new FormData();
        formData.append('conversation_id', conversationAktif);
        formData.append('caption', caption);
        formData.append('media', file);

        fetch('<?= base_url('/inbox/kirim-media') ?>', {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    textarea.value = '';
                    batalkanMediaBalasan();
                    tampilkanBubbleOutgoing(json.message);
                    muatUlangDaftarConversation();
                } else {
                    showToast(json.message || 'Gagal mengirim media.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            })
            .finally(function() {
                btn.disabled = false;
                textarea.disabled = false;
                document.getElementById('btnLampirkanMedia').disabled = false;
                textarea.focus();
            });

        return false;
    }

    // Enter (tanpa Shift) langsung kirim, Shift+Enter baris baru.
    document.getElementById('teksBalasan').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            kirimBalasan(e);
        }
    });

    // ================================================================
    // STATUS GATEWAY
    // ================================================================
    const STATUS_GATEWAY_LABEL = {
        connected: { text: 'Terhubung', kelas: 'bg-success', icon: 'fa-check-circle' },
        connecting: { text: 'Menghubungkan...', kelas: 'bg-warning', icon: 'fa-circle-notch fa-spin' },
        reconnecting: { text: 'Menghubungkan...', kelas: 'bg-warning', icon: 'fa-circle-notch fa-spin' },
        disconnected: { text: 'Terputus', kelas: 'bg-secondary', icon: 'fa-times-circle' },
        logged_out: { text: 'Logout', kelas: 'bg-secondary', icon: 'fa-sign-out-alt' },
    };

    function renderStatusGateway(gateway) {
        const badge = document.getElementById('gatewayStatusBadge');
        const info = STATUS_GATEWAY_LABEL[gateway.effective_status] || STATUS_GATEWAY_LABEL.disconnected;

        badge.className = 'badge ' + info.kelas;
        badge.innerHTML = '<i class="fas ' + info.icon + '"></i> ' + info.text +
            (gateway.phone ? ' (' + escapeHtmlInbox(gateway.phone) + ')' : '');

        // Tahap F
        const sebelumnya = gatewayTerhubung;
        gatewayTerhubung = gateway.effective_status === 'connected';

        perbaruiUIGateway();

        // Baru saja RECONNECT (bukan pertama kali load) -- muat ulang pesan
        // supaya gambar yang tadinya diblokir otomatis dicoba lagi tanpa
        // kasir harus pindah-balik conversation manual.
        if (!sebelumnya && gatewayTerhubung && conversationAktif) {
            muatUlangPesan(false);
        }
    }

    function perbaruiUIGateway() {
        const btnKirim = document.getElementById('btnKirimBalasan');
        const btnChatBaru = document.getElementById('btnChatBaru');

        // btnKirimBalasan SUDAH punya logic disabled lain (belum pilih
        // conversation / teks kosong) -- JANGAN timpa logic itu, cuma
        // tambah 1 syarat lagi. Lihat kirimBalasan(e) di bawah untuk guard
        // yang sesungguhnya menahan submit -- disabled di sini murni sinyal
        // visual, bukan satu-satunya proteksi.
        if (btnKirim) {
            btnKirim.title = gatewayTerhubung ? '' : 'Gateway terputus -- belum bisa kirim pesan';
        }
        if (btnChatBaru) {
            btnChatBaru.disabled = !gatewayTerhubung;
            btnChatBaru.title = gatewayTerhubung ? '' : 'Gateway terputus -- belum bisa kirim pesan';
        }
        // teksBalasan SENGAJA TIDAK di-disable/dikosongkan -- kasir tetap
        // boleh ketik draft sambil Gateway terputus, supaya begitu
        // tersambung lagi tinggal klik Kirim tanpa ngetik ulang.
    }

    function muatUlangStatusGateway() {
        fetch('<?= base_url('/inbox/api/gateway-status') ?>')
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    renderStatusGateway(json.gateway);
                }
            })
            .catch(function() {
                // Diamkan, siklus polling berikutnya coba lagi.
            });
    }

    // ================================================================
    // POLLING SEDERHANA (bukan WebSocket, sesuai spec)
    // ================================================================
    renderStatusGateway(<?= json_encode($gatewayStatus) ?>);
    // Render ulang daftar sekali di awal (walau PHP sudah render first-
    // paint) -- supaya badge assignment ("Dipegang: .../Belum diambil",
    // lihat Tahap 2) & filter langsung konsisten tanpa menunggu polling
    // pertama (6 detik). Satu sumber logic render (JS), tidak
    // menduplikasi template di PHP.
    renderDaftarConversation();

    setInterval(muatUlangDaftarConversation, 6000);
    setInterval(function() { muatUlangPesan(false); }, 4000);
    setInterval(muatUlangStatusGateway, 15000);
</script>

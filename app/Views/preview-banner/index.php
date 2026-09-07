<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-image"></i> Preview Banner</h5>
        <small class="opacity-75">Buat gambar preview untuk approval customer via WhatsApp</small>
    </div>
    <div class="card-body p-0">
        <!--
            Iframe SENGAJA dipakai supaya CSS/JS alat ini (yang standalone,
            punya nama class sendiri seperti .btn) tidak bentrok dengan
            Bootstrap yang dipakai di seluruh halaman AULIA lainnya.
            Alat aslinya tidak diubah sama sekali, murni client-side,
            tidak ada data yang dikirim ke server AULIA.
        -->
        <iframe
            src="<?= base_url('tools/preview-banner.html') ?>"
            title="Preview Banner"
            style="width: 100%; height: calc(100vh - 220px); min-height: 640px; border: 0;"
            loading="lazy">
        </iframe>
    </div>
</div>

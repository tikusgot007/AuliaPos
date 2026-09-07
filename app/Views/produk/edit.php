<div class="card">
    <div class="card-header bg-warning text-white">
        <h5 class="mb-0"><i class="fas fa-edit"></i> Edit Produk</h5>
    </div>
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach (session()->getFlashdata('errors') as $error): ?>
                        <li><?= $error ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form action="<?= base_url('/produk/update/' . $produk['id']) ?>" method="post">
            <?= csrf_field() ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="barcode" class="form-label">Barcode</label>
                        <input type="text" class="form-control" id="barcode" name="barcode"
                            value="<?= old('barcode') ?? $produk['barcode'] ?>">
                    </div>
                    <div class="mb-3">
                        <label for="nama" class="form-label">Nama Produk *</label>
                        <input type="text" class="form-control" id="nama" name="nama"
                            value="<?= old('nama') ?? $produk['nama'] ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="kategori_id" class="form-label">Kategori *</label>
                        <select class="form-control" id="kategori_id" name="kategori_id" required>
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($kategori as $k): ?>
                                <option value="<?= $k['id'] ?>"
                                    <?= (old('kategori_id') ?? $produk['kategori_id']) == $k['id'] ? 'selected' : '' ?>>
                                    <?= $k['nama'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="satuan" class="form-label">Satuan</label>
                        <select class="form-control" id="satuan" name="satuan">
                            <option value="pcs" <?= (old('satuan') ?? $produk['satuan']) == 'pcs' ? 'selected' : '' ?>>PCS</option>
                            <option value="meter" <?= (old('satuan') ?? $produk['satuan']) == 'meter' ? 'selected' : '' ?>>Meter</option>
                            <option value="lembar" <?= (old('satuan') ?? $produk['satuan']) == 'lembar' ? 'selected' : '' ?>>Lembar</option>
                            <option value="botol" <?= (old('satuan') ?? $produk['satuan']) == 'botol' ? 'selected' : '' ?>>Botol</option>
                            <option value="gelas" <?= (old('satuan') ?? $produk['satuan']) == 'gelas' ? 'selected' : '' ?>>Gelas</option>
                            <option value="sachet" <?= (old('satuan') ?? $produk['satuan']) == 'sachet' ? 'selected' : '' ?>>Sachet</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="harga_jual" class="form-label">Harga Jual *</label>
                        <input type="number" class="form-control" id="harga_jual" name="harga_jual"
                            value="<?= old('harga_jual') ?? $produk['harga_jual'] ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="harga_beli" class="form-label">Harga Beli</label>
                        <input type="number" class="form-control" id="harga_beli" name="harga_beli"
                            value="<?= old('harga_beli') ?? $produk['harga_beli'] ?>">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-warning">Update</button>
            <a href="<?= base_url('/produk') ?>" class="btn btn-secondary">Batal</a>
        </form>
    </div>
</div>
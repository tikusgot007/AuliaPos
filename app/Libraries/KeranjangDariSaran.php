<?php

namespace App\Libraries;

use App\Models\MaraSuggestionModel;
use App\Models\ProdukModel;

/**
 * Builds the initial Kasir cart from an M.A.R.A suggestion
 * (docs/design/2026-10-18-isi-keranjang-dari-saran-mara.md).
 *
 * Trust model: both `saranId` (comes from the URL) and the stored
 * `cart_items` (comes from M.A.R.A) are untrusted. Only product ids and
 * quantities are taken from the suggestion; name, unit, category and price
 * always come from `produk`. A product that is missing, inactive or priced 0
 * (Banner, manual, custom, "type the price" items) is counted as failed and
 * never auto-filled.
 */
class KeranjangDariSaran
{
    /**
     * @return array{items: array<int, array<string, mixed>>, jumlah_gagal: int}
     */
    public function bangun(int $saranId, int $pelangganId): array
    {
        $kosong = ['items' => [], 'jumlah_gagal' => 0];

        if ($saranId <= 0 || $pelangganId <= 0) {
            return $kosong;
        }

        try {
            $baris = $this->bacaBarisSaran($saranId, $pelangganId);
        } catch (\Throwable $e) {
            // Kasir must open even if the inbox DB / column is unavailable.
            log_message('error', 'KeranjangDariSaran gagal membaca saran_id=' . $saranId . ': ' . $e->getMessage());

            return $kosong;
        }

        if ($baris === []) {
            return $kosong;
        }

        $produkModel = new ProdukModel();
        $items       = [];
        $gagal       = 0;

        foreach ($baris as $b) {
            $produk = $produkModel->find($b['produk_id']);

            if (!$produk || (int) $produk['is_active'] !== 1 || (float) $produk['harga_jual'] <= 0) {
                $gagal++;

                continue;
            }

            $harga      = (int) round((float) $produk['harga_jual']);
            $kategoriId = (int) $produk['kategori_id'];

            $items[] = [
                'id'          => 'saran_' . $saranId . '_' . (int) $produk['id'],
                'produk_id'   => (int) $produk['id'],
                'kategori_id' => $kategoriId,
                'nama'        => (string) $produk['nama'],
                'harga'       => $harga,
                'satuan'      => (string) ($produk['satuan'] ?: 'pcs'),
                'jumlah'      => $b['jumlah'],
                'subtotal'    => $harga * $b['jumlah'],
                'is_custom'   => false,
                'is_banner'   => false,
                'is_manual'   => false,
                'is_edit'     => false,
                'is_cetak'    => $kategoriId === 16,
                'detail'      => null,
            ];
        }

        return ['items' => $items, 'jumlah_gagal' => $gagal];
    }

    /**
     * Valid {produk_id, jumlah} lines of an ACTIVE, unexpired suggestion that
     * belongs to a conversation linked to `$pelangganId`. Anything else -> [].
     *
     * @return array<int, array{produk_id:int, jumlah:int}>
     */
    private function bacaBarisSaran(int $saranId, int $pelangganId): array
    {
        $model = new MaraSuggestionModel();
        $now   = MaraSuggestionModel::nowWib();

        $saran = $model->db->table('mara_suggestions s')
            ->select('s.cart_items')
            ->join('conversations c', 'c.id = s.conversation_id')
            ->where('s.id', $saranId)
            ->where('s.status', 'active')
            ->where('c.pelanggan_id', $pelangganId)
            ->groupStart()
                ->where('s.expires_at', null)
                ->orWhere('s.expires_at >', $now)
            ->groupEnd()
            ->get()
            ->getRowArray();

        if (!$saran || empty($saran['cart_items'])) {
            return [];
        }

        $decoded = json_decode((string) $saran['cart_items'], true);
        if (!is_array($decoded)) {
            return [];
        }

        $hasil = [];
        foreach ($decoded as $row) {
            $id  = is_array($row) ? ($row['produk_id'] ?? null) : null;
            $qty = is_array($row) ? ($row['jumlah'] ?? null) : null;

            if (is_int($id) && $id > 0 && is_int($qty) && $qty >= 1 && $qty <= 999) {
                $hasil[] = ['produk_id' => $id, 'jumlah' => $qty];
            }
        }

        return $hasil;
    }
}

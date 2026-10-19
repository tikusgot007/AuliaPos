<?php

namespace App\Libraries;

use App\Models\PelangganModel;

/**
 * Tentukan pelanggan sebuah transaksi dari input Kasir. SATU sumber untuk
 * Api::simpanTransaksi() dan Transaksi::update().
 *
 * Aturan (docs/design/2026-10-19-perbaikan-alur-identitas-pelanggan-kasir-inbox.md):
 *  - Nomor HP = identitas; nama ketikan hanya label.
 *  - Nama tidak pernah dipakai untuk menebak pelanggan lama.
 *  - 4 digit nama SELALU dari nomor di server, bukan dari browser.
 *  - Pelanggan terpilih yang sudah punya nomor tidak diubah oleh ketikan.
 *  - `atas_nama` hanya disimpan bila beda dari nama dasar pelanggan akhir.
 */
class PelangganResolver
{
    /**
     * @param int|string|null $pelangganId id numerik (terpilih), 'new', atau kosong
     *
     * @return array{error:?string, pelanggan_id:?int, atas_nama:?string, baru_id:?int}
     */
    public static function resolve(PelangganModel $model, $pelangganId, string $nama, string $telp, string $atasNama = ''): array
    {
        $namaAsli = mb_substr(trim(preg_replace('/\s+/u', ' ', $nama) ?? ''), 0, 100);
        $nama     = self::bersihkan($nama);
        $telp     = trim($telp);
        $atasNama = mb_substr(trim(preg_replace('/\s+/u', ' ', $atasNama) ?? ''), 0, 100);
        $hasil    = ['error' => null, 'pelanggan_id' => null, 'atas_nama' => null, 'baru_id' => null];

        $nomor = null;
        if ($telp !== '') {
            $nomor = PhoneNumber::normalize($telp);
            if ($nomor === null) {
                $hasil['error'] = 'Format nomor telepon tidak valid.';

                return $hasil;
            }
        }

        $pelanggan = null;

        if ($pelangganId !== null && $pelangganId !== '' && $pelangganId !== 'new' && is_numeric($pelangganId)) {
            $pelanggan = $model->find((int) $pelangganId);
            if (!$pelanggan) {
                $hasil['error'] = 'Pelanggan yang dipilih tidak ditemukan.';

                return $hasil;
            }

            // Pelanggan terpilih tanpa nomor + nomor valid diketik: nomor itu
            // dulu dicek pemiliknya (nomor = identitas), kalau bebas diisikan.
            if (trim((string) ($pelanggan['no_hp'] ?? '')) === '' && $nomor !== null) {
                $pemilik = $model->findByNomor($nomor);
                if ($pemilik) {
                    $pelanggan = $pemilik;
                } else {
                    $ubah = ['no_hp' => '0' . substr($nomor, 2)];
                    $dasar = self::namaDasar((string) $pelanggan['nama']);
                    if ($dasar !== '') {
                        $ubah['nama'] = $dasar . ' ' . substr($nomor, -4);
                    }
                    if ($model->update((int) $pelanggan['id'], $ubah) === false) {
                        $hasil['error'] = 'Gagal memperbarui nomor pelanggan.';

                        return $hasil;
                    }
                    $pelanggan = $model->find((int) $pelanggan['id']);
                }
            }
        } elseif ($pelangganId === 'new') {
            if (mb_strlen($nama) < 2) {
                $hasil['error'] = 'Nama pelanggan minimal 2 karakter.';

                return $hasil;
            }

            $pemilik = $nomor !== null ? $model->findByNomor($nomor) : null;

            if ($pemilik) {
                $pelanggan = $pemilik;
                if ($atasNama === '') {
                    $atasNama = $namaAsli;
                }
            } else {
                $dasar = self::namaDasar($nama);
                $data  = [
                    'nama'   => $nomor !== null ? $dasar . ' ' . substr($nomor, -4) : $nama,
                    'no_hp'  => $nomor !== null ? '0' . substr($nomor, 2) : ($telp !== '' ? $telp : null),
                    'diskon' => 0,
                ];
                $baruId = $model->insert($data);
                if (!$baruId) {
                    $hasil['error'] = 'Gagal membuat pelanggan baru.';

                    return $hasil;
                }
                $hasil['baru_id'] = (int) $baruId;
                $pelanggan        = $model->find((int) $baruId);
            }
        }

        if ($pelanggan) {
            $hasil['pelanggan_id'] = (int) $pelanggan['id'];

            if ($atasNama !== '' && self::namaDasar($atasNama) !== self::namaDasar((string) $pelanggan['nama'])) {
                $hasil['atas_nama'] = $atasNama;
            }
        }

        return $hasil;
    }

    /** Huruf besar, spasi dirapikan, maksimal 95 karakter (ruang untuk " 1234"). */
    public static function bersihkan(string $nama): string
    {
        $nama = preg_replace('/\s+/u', ' ', trim($nama)) ?? '';

        return mb_strtoupper(mb_substr($nama, 0, 95));
    }

    /** Nama tanpa 4 digit di belakang: "SUHERMAN 1234" -> "SUHERMAN". */
    public static function namaDasar(string $nama): string
    {
        $nama = self::bersihkan($nama);

        return trim(preg_replace('/[\s.\-\']+\d{4}$/u', '', $nama) ?? $nama);
    }
}

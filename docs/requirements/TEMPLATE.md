# Requirements: <judul singkat>

Salin file ini menjadi `docs/requirements/YYYY-MM-DD-<slug>.md`. Bahasa Indonesia (dokumen bisnis).

- **Tanggal**: YYYY-MM-DD
- **Status**: draf | disetujui | dibatalkan
- **Tier SDLC**: A | B | C
- **Penanggung jawab**:

## 1. Tujuan

Satu atau dua kalimat: masalah apa yang diselesaikan dan untuk siapa (kasir, admin, pelanggan, ...).

## 2. Kondisi saat ini (terverifikasi)

Perilaku/aturan bisnis yang berlaku sekarang, dengan rujukan `file:line` atau `docs/CHANGELOG.md`.
Tandai jelas mana yang fakta terverifikasi dan mana yang belum diverifikasi.

## 3. User story

- Sebagai <peran>, saya ingin <kemampuan>, supaya <manfaat>.

## 4. Acceptance criteria

Setiap kriteria harus bisa diuji. Nomor ini dirujuk oleh test (`AC-n`).

- **AC-1**: Given <kondisi>, when <aksi>, then <hasil yang terukur>.
- **AC-2**: ...

## 5. Batasan dan di luar cakupan

- Batasan teknis/bisnis:
- Tidak termasuk:

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway (perubahan lintas repositori)

## 7. Asumsi dan pertanyaan terbuka

- Asumsi:
- Pertanyaan (maks. 3, hanya yang mengubah hasil):

## 8. Persetujuan (Gate 1)

- [ ] Disetujui oleh: <nama>, tanggal: <YYYY-MM-DD>

# Checkpoint: Batch Fix Cash/Tagihan/Laporan (BL09-BL17)

**Sesi:** 2026-10-07  
**Commit:** 08f32e1  
**Status:** Selesai (5 item)

## Items Selesai

### ✓ BL09 — DIHAPUS
- **Alasan:** Konteks tidak jelas, tidak ada bukti bug nyata
- **Akar masalah:** Ternyata BL11 (duplikat kas awal)
- **Action:** Removed from TODO.md

### ✓ BL11 — Transaction + forUpdate() lock
- **Masalah:** Kas awal bisa di-insert dua kali (race condition, select-then-insert tanpa lock)
- **Root cause:** `CashBalanceService::saveOpeningCash()` line 48-73
- **Fix:** Wrap select-insert dalam transaction + `forUpdate()` lock
- **File:** `app/Services/CashBalanceService.php:48-74`
- **Impact:** Cegah duplikat kas awal per hari

### ✓ BL12 — Validasi alasan selisih
- **Masalah:** Label UI bilang "Opsional" tapi validasi controller wajib diisi
- **Root cause:** `Cash.php:199-204` validasi wajib vs `opname.php:55` label opsional
- **Fix:** 
  1. Hapus validasi mandatory di Cash.php:199-204
  2. Ubah input text menjadi textarea di opname.php:55-57
- **Files:** `app/Controllers/Cash.php`, `app/Views/cash/opname.php`
- **Impact:** Alasan selisih truly optional, kasir tidak perlu isi jika tidak ada selisih

### ✓ BL13 — Clamp sisa_tagihan
- **Masalah:** `sisa_tagihan` tidak di-clamp, bisa negatif (overpayment)
- **Root causes:**
  1. `Tagihan.php:258` detail tagihan
  2. `Laporan.php:1085` laporan piutang
- **Fix:** `max(0, grand_total - total_dibayar)` di kedua lokasi
- **Files:** `app/Controllers/Tagihan.php:258`, `app/Controllers/Laporan.php:1085`
- **Impact:** Sisa tagihan tidak distorsi, total piutang akurat

### ✓ BL17 — Exclude mangkrak dari laporan
- **Masalah:** Daftar tagihan exclude `mangkrak` (Tagihan.php:72) tapi laporan include (Laporan.php)
- **Inkonsistensi:** Kasir lihat piutang Rp 100jt di daftar, tapi laporan Rp 150jt (include macet)
- **Fix:** Exclude `mangkrak` di query laporan
  - Line 126: `->where('status !=', 'mangkrak')`
  - Line 137: `->where('transaksi.status !=', 'mangkrak')`
- **File:** `app/Controllers/Laporan.php`
- **Impact:** Laporan dan daftar tagihan konsisten, piutang aktif saja

## Pending Items

- **BL14:** Dua sumber `total_dibayar` — **SKIP** (risky, perlu query besar)
- **BL22:** CSRF global mati — **PENDING** (kompleks, butuh telusur semua GET routes)

## Notes

1. **BL09 & BL11 terhubung:** Duplikat kas awal (BL11) menyebabkan `pemasukan_tunai` opname terlihat dobel (BL09), jadi fix BL11 = fix BL09.

2. **BL13 & BL17 straightforward:** Clamp dan exclude, impact jelas.

3. **Opsi C (dropdown) untuk BL12 ditunda:** Masih perlu tentukan daftar alasan standart dengan user.

## Diff Summary

- 6 files changed, 42 insertions(+), 38 deletions(-)
- Perubahan: transaction lock, validasi dihapus, clamp/exclude filters, textarea UI

## Next Steps

1. Test batch ini di dev/staging
2. Lanjut Medium items lain (BL14 jika urgent, atau BL22/BL23/dst)
3. Persiapan opsi C BL12 (dropdown alasan) — tunggu keputusan user daftar alasan

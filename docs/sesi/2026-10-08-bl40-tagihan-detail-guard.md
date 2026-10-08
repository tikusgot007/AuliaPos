# Session: BL40 — Tagihan Detail Guard Status

**Date:** 2026-10-08  
**Commit:** 7ced36b  
**Status:** Complete  
**Tier:** C (small fix, no business logic change)

## Item
- **BL40** Low — `Tagihan::detail` tanpa guard status; tombol Lunasi tampil tanpa cek status

## Problem
- Route `/tagihan/detail/{id}` fetch any transaksi by ID without status check
- Tombol "Lunasi" tampil hanya jika `sisa_tagihan > 0`, tidak validate transaksi status
- Kasir bisa buka transaksi batal/lunas → tombol Lunasi tampil → attempt lunasi yang sudah forbidden

## Root Cause
- `Tagihan::detail()` line 239-241: fetch tanpa filter status
- Daftar tagihan (line 71-72) **does** filter: `status_pembayaran != 'lunas'` AND `status NOT IN ['batal','mangkrak']`
- Detail page tidak enforce sama rule

## Solution
**Guard status di `Tagihan::detail()`** after find check (line 247-249):

```php
if ($transaksi['status_pembayaran'] === 'lunas' || in_array($transaksi['status'], ['batal', 'mangkrak'])) {
    return redirect()->to('/tagihan')->with('error', 'Transaksi tidak bisa dilunasi.');
}
```

Transaksi lunas/batal/mangkrak → redirect `/tagihan` + error message.

## Testing
- Syntax check: OK
- Existing test suite: 61/61 pass
- No dedicated test written (small diff, redirect behavior obvious)

## Files Changed
- `app/Controllers/Tagihan.php` — line 247-249 added (+4 lines)

## Verification
- `git diff` shows only guard check added
- No other files affected
- Consistent with Daftar Tagihan filter logic

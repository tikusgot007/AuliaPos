# Laporan Keputusan — TODO-F8: Menampilkan Teks Pesan yang Diedit Pelanggan di Inbox

- **Tanggal**: 2026-10-04
- **Status**: Menunggu keputusan tim
- **Terkait**: TODO-F7 (selesai), TODO-F8, PR #5 (`WA-Gateway`/`evolution-gateway`), PR #49 (`AuliaPos`)
- **Sifat dokumen**: laporan keputusan (bukan spesifikasi/rencana implementasi)

---

## 1. Ringkasan eksekutif

Tujuan TODO-F8 adalah menampilkan **teks hasil edit** pesan pelanggan di Inbox,
bukan hanya penanda "diedit" (TODO-F7 yang sudah jalan).

Audit independen menemukan dua fakta penting:

1. **Algoritma dekripsi yang diusulkan benar dan sudah terbukti bekerja** pada
   pesan WhatsApp nyata — yaitu HKDF-SHA256 + AES-256-GCM + decode `WAProto.Message`,
   dengan syarat memakai **LID** (Linked ID) customer sebagai identitas pengirim.
2. **Namun implementasi saat ini tidak akan bekerja di produksi** karena
   **Evolution API (bawaan upstream v2.3.7)** mengganti `remoteJid` (LID)
   menjadi nomor PN **sebelum** webhook dikirim
   (`whatsapp.baileys.service.ts:1478-1481`). Akibatnya gateway hanya menerima
   nomor PN dan kehilangan LID yang dibutuhkan untuk menurunkan kunci dekripsi.

Semua unit test dan feature test pada PR #5/#49 **lulus**, tetapi test tersebut
memakai data sintetis / fixture yang tidak menangkap masalah LID ini. Validasi
offline memakai fixture WhatsApp nyata **gagal** dengan representasi yang
sekarang diterima gateway, dan **berhasil** begitu LID yang benar dipakai.

**Yang diminta:** keputusan arah perbaikan (Opsi A / B / C pada §6).

---

## 2. Latar belakang

Alur pesan:

```
WhatsApp → Evolution API → WA-Gateway (adapter) → AuliaPos (CI4) → Inbox
```

- **Edit pelanggan** datang sebagai `message.secretEncryptedMessage` dengan
  `secretEncType = 2` (`MESSAGE_EDIT`); pesan asli ditentukan oleh
  `targetMessageKey.id`.
- **TODO-F7** (selesai) hanya menandai pesan asli `edited_at` / `revoked_at`
  dan menampilkan badge "diedit"/"dihapus". Teks hasil edit **tidak** ditampilkan.
- **TODO-F8** bertujuan membaca `encPayload` sehingga teks terbaru bisa
  ditampilkan.

Kunci dekripsi `MESSAGE_EDIT` (referensi `whatsmeow` — `msgsecret.go`):

- KDF: HKDF-SHA256, salt kosong, info = `origMsgId || origMsgSender || modificationSender || "Message Edit"`, output 32 byte.
- Dekripsi: AES-256-GCM, IV = `encIv`, AAD kosong, tag 16 byte di akhir ciphertext.
- Validasi: hasil harus decode sebagai `WAProto.Message`.

**Catatan penting:** identitas pengirim (`origMsgSender` & `modificationSender`)
dalam KDF ini menggunakan **representasi JID yang dipakai WhatsApp saat
enkripsi**, yaitu **LID** (`…@lid`) ketika sesi memakai pengalamatan LID.

---

## 3. Status implementasi saat ini

| Repo | PR/Branch | Isi | Status |
|---|---|---|---|
| `evolution-gateway` (WA-Gateway) | PR #5 / `todo-f8-capture-fixture` | decryptor, resolver kandidat sender PN/LID, validasi protobuf, capture fixture (off-by-default) | Draft |
| `AuliaPos` | PR #49 / `todo-f8-message-text` | endpoint `message-event` menerima `edited_text` tervalidasi, `edited_at` first-seen | Draft |

Branch default (`evolution`, `v2.4`) **tidak berubah**; kedua PR masih **Draft**.

Hasil test yang sudah dijalankan (independen):

- Gateway (`npm test`, branch F8): **lulus** (crypto/fixture/lifecycle/adapter/boot/maintenance).
- AuliaPos (feature test, branch F8): **66 test lulus**, termasuk 10 test F8 `edited_text`.
- **Validasi offline terhadap fixture WhatsApp nyata: GAGAL** dengan representasi
  JID yang diterima gateway sekarang (lihat §4).

---

## 4. Temuan kritis (akar masalah) + bukti

### 4.1 Dekripsi terbukti bekerja — jika memakai LID

Fixture nyata ditangkap dari instance test `aulia-test` pada
2026-10-04 21:36:56 WIB (pesan asli `A51FA5A95782D996F05815F660DFF0D0`,
edit `A522C2DC638155F91365230C7AFF4893`, chat `628563324637`).

| Pasangan pengirim (origMsgSender, modificationSender) | Hasil |
|---|---|
| (`628563324637@s.whatsapp.net` PN, sesuai yang diterima gateway) | GCM auth gagal |
| (`255490491736112@lid`, dari `targetMessageKey.remoteJid`) | GCM auth gagal |
| **(`149701252890753@lid` — LID customer, dari data mentah Evolution)** | **BERHASIL** |

Hasil dekripsi berhasil: decode `WAProto.Message` dengan bentuk
`protocolMessage.editedMessage.extendedTextMessage`, teks = **"Test a002"**.

Kesimpulan: **kripto benar; yang salah adalah identitas pengirim yang dipakai.**

### 4.2 Penyebab: konversi LID→PN BAWAAN Evolution API (upstream)

Konversi ini **bukan patch lokal** — ia ada di **sumber resmi Evolution API
v2.3.7 di GitHub**. Verifikasi terhadap tag `2.3.7`
(`EvolutionAPI/evolution-api`, `src/api/integrations/channel/whatsapp/whatsapp.baileys.service.ts`)
baris **1478–1481** (di instalasi test tampak di baris 1486–1489; selisih offset
karena patch lain seperti view-once):

```ts
if (messageRaw.key.remoteJid?.includes('@lid') && messageRaw.key.remoteJidAlt) {
  messageRaw.key.remoteJid = messageRaw.key.remoteJidAlt;   // LID ditimpa menjadi PN
}
console.log(messageRaw);
this.sendDataWebhook(Events.MESSAGES_UPSERT, messageRaw);
```

Efeknya, pada saat yang sama:

| Sumber | `remoteJid` | `remoteJidAlt` |
|---|---|---|
| Data mentah (Baileys / DB Evolution) | `149701252890753@lid` | `628563324637@s.whatsapp.net` |
| **Webhook yang diterima gateway** | `628563324637@s.whatsapp.net` | `628563324637@s.whatsapp.net` |

LID hilang total di webhook. Adapter lalu menyimpan/menangkap hanya PN, sehingga
KDF dibangun dengan JID yang salah.

**Implikasi:** karena ini perilaku upstream (bawaan setiap instalasi Evolution
v2.3.7), kemungkinan besar **produksi `aulia3` juga mengalami hal yang sama**,
kecuali versinya berbeda. Jadi penyediaan LID adalah kebutuhan **lintas-sistem**,
bukan sekadar perbaikan setup test.

### 4.3 Catatan keamanan

`console.log(messageRaw)` (upstream baris 1481) juga **bawaan Evolution API
v2.3.7**, bukan sisa debug lokal. Ia mencetak objek pesan utuh ke log Evolution —
berisiko membocorkan `messageContextInfo.messageSecret` dan
`secretEncryptedMessage.encPayload`. Ini masalah upstream; menghentikannya
memerlukan patch lokal.

---

## 5. Dampak

- **Fungsional:** PR #5/#49, meski semua test lulus, **tidak akan menampilkan
  teks edit** di produksi selama LID dibuang di webhook. Test synthetic/offline
  tidak dapat menangkap kelas masalah ini.
- **Rilis:** TODO-F8 belum layak dianggap selesai/di-merge sebelum identitas
  LID tersedia.
- **Stabilitas F7:** tidak terganggu — penanda "diedit" tetap berjalan
  (terverifikasi pada log: `edited` marker diteruskan, `matched: true`).
- **Keamanan:** `console.log(messageRaw)` bawaan upstream Evolution v2.3.7
  berpotensi membocorkan secret/ciphertext ke log.

---

## 6. Opsi keputusan

| Opsi | Perubahan | Kelebihan | Kekurangan / Risiko |
|---|---|---|---|
| **A. Patch Evolution agar LID dipertahankan (disarankan)** | Modifikasi **lokal** (di luar upstream) pada `whatsapp.baileys.service.ts`: `remoteJid` tetap = PN (untuk Inbox) **tetapi** LID disimpan, mis. `remoteJidAlt = LID`. Hapus `console.log`. Gateway sudah memakai `remoteJidAlt` di daftar kandidat sender → kemungkinan tanpa ubah kode gateway. | Perubahan kecil; Inbox tetap pakai nomor; F8 dapat bekerja | Patch lokal pada dependency (harus di-reapply tiap upgrade Evolution — pola sama seperti patch view-once / TODO-F3); perlu verifikasi `remoteJidAlt` tidak mengganggu konsumen lain |
| **B. Gateway resolve LID sendiri** | Gateway memetakan PN→LID lewat API Evolution saat menerima edit | Tidak menyentuh Evolution | Kode gateway lebih kompleks; bergantung API/stabilitas pemetaan; lebih lambat |
| **C. Batalkan F8** | Pertahankan hanya TODO-F7 (badge "diedit", tanpa teks terbaru) | Nol risiko baru | Pelanggan/gateway tetap tidak menampilkan teks terbaru; fitur "nice-to-have" tidak tercapai |

**Rekomendasi: Opsi A**, karena paling kecil dan sesuai arsitektur yang ada
(gateway sudah mengumpulkan kandidat PN/LID). Sertakan penghapusan `console.log`
dan verifikasi end-to-end sebelum merge.

**Dependensi keputusan:** karena konversi LID→PN adalah **bawaan upstream
Evolution v2.3.7**, produksi (`aulia3`) hampir pasti terdampak (belum
diverifikasi versinya). Bila Opsi A dipilih, patch lokal perlu diterapkan di
semua instalasi Evolution yang dipakai gateway.

---

## 7. Yang belum diverifikasi / asumsi

1. **Belum diverifikasi**: versi Evolution di produksi (`aulia3`). Karena
   konversi LID→PN adalah bawaan upstream v2.3.7, produksi hampir pasti
   terdampak; yang perlu dikonfirmasi adalah versi persisnya dan apakah ada
   patch lokal lain.
2. **Belum diverifikasi end-to-end**: alur penuh
   gateway (dengan `EVOLUTION_DECRYPT_MESSAGE_EDIT=1`) → `message-event` CI4 →
   tampilan Inbox. Baru terbukti sampai tahap dekripsi offline memakai LID.
3. **Asumsi**: menambahkan `remoteJidAlt = LID` tidak mengganggu konsumen lain
   (mis. penanganan `messages.delete`, kutipan, Inbox). Perlu diuji.
4. **Asumsi**: LID customer stabil per percakapan (tidak berubah antar pesan).

---

## 8. Keputusan yang diminta

- [ ] Pilih arah: **A / B / C**.
- [ ] Jika A: setujui patch lokal pada Evolution (di luar upstream) di instalasi
      test untuk verifikasi end-to-end, dan tentukan apakah produksi (`aulia3`) ikut.
- [ ] Setujui penghapusan `console.log(messageRaw)` (perbaikan keamanan).
- [ ] Tetapkan syarat "selesai": F8 baru boleh di-merge setelah dekripsi
      tervalidasi pada fixture WhatsApp nyata **melalui jalur gateway** (bukan
      hanya test offline), dan TODO-F7 tetap tidak berubah.

---

## Lampiran — Bukti teknis

- Fixture: `data/message-edit-fixture.json` (gitignored, tidak di-commit).
- Log Evolution (instalasi test): `C:\AuliaGateway-test\logs\evolution.out.log`
  — memperlihatkan objek pesan mentah (`remoteJid = …@lid`) lalu objek webhook
  (`remoteJid = …@s.whatsapp.net`).
- DB Evolution (Postgres test, port 5433, `Message`): `key->>'remoteJid' = 149701252890753@lid`,
  `key->>'remoteJidAlt' = 628563324637@s.whatsapp.net`.
- Konversi LID→PN: **upstream** Evolution API v2.3.7, `whatsapp.baileys.service.ts:1478-1481`
  (diverifikasi dari tag `2.3.7` di GitHub `EvolutionAPI/evolution-api`; di
  instalasi test tampak di baris 1486-1489).
- Referensi algoritma: `whatsmeow` `msgsecret.go` (`generateMsgSecretKey`, `decryptMsgSecret`).
- **Patch lokal yang benar-benar ada (vs upstream):** hanya **satu** —
  `PATCH-ADAPTER (2026-10-01)` rekonstruksi view-once
  (`whatsapp.baileys.service.ts:1166-1173`, sudah tercatat di TODO-F3).
  Konversi LID→PN dan `console.log(messageRaw)` **bukan** patch lokal (keduanya
  upstream v2.3.7). Sisa perbedaan diff hanyalah encoding karakter (mojibake),
  bukan perubahan logika.

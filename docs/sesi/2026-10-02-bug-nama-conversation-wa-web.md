# Checkpoint Sesi

- **Tanggal**: 2026-10-02
- **Status**: selesai (fix + regression test hijau)
- **Tier**: B (bug fix) — `app/Controllers/InboxGatewayApi.php`
- **Sumber laporan**: `X:\Penjelasan masalah untuk tim 01.pdf` (4 halaman, screenshot; diekstrak lewat render PDF → gambar).
- **Repo / branch**: `aulia-app` `v2.4`.

## Masalah

Saat staff membalas chat customer langsung dari **WhatsApp Web/HP** (bukan dari
POS), nama kontak di Inbox POS berubah dari nama customer menjadi nama staff.

Contoh: Customer "Ahmad" mengirim pesan → Inbox menampilkan "Ahmad". Staff
"James" membalas dari WA Web/HP → Inbox list berubah jadi "James".

## Root cause

`app/Controllers/InboxGatewayApi.php:341-342` (`messages()`) menulis
`conversations.whatsapp_name` dari `payload['contact_name']` untuk **semua**
pesan (incoming maupun outgoing), hanya dengan syarat nilainya berbeda.

Untuk pesan yang disinkronkan dari WA Web/HP, Gateway mengirim
`direction='outgoing'` dengan `fromMe=true`; `contact_name` pada event itu
adalah **push name staff** yang membalas, bukan customer. Jadi nama
percakapan tertimpa nama staff.

Alur:

```text
WhatsApp Web/HP (Staff)
  → Evolution webhook (fromMe=true, pushName='James')
  → POST /api/inbox/gateway/messages
  → InboxGatewayApi::messages()  (app/Controllers/InboxGatewayApi.php:53)
  → UPDATE conversations SET whatsapp_name='James'  (line 341-342)
```

Alur POS tidak bermasalah karena tidak melewati endpoint ini (menyimpan
langsung ke database, tanpa menyentuh `whatsapp_name`). Verifikasi: tidak ada
jalur lain di `app/` yang menulis `whatsapp_name` selain
`ConversationModel::insert()` (conversation baru) dan blok ini.

## Fix

Menambahkan syarat `$direction === 'incoming'` sebelum update `whatsapp_name`,
konsisten dengan pola pengecekan `direction` yang sudah dipakai untuk status
percakapan di baris ~412:

```php
if ($direction === 'incoming'
    && $whatsappNameFromPayload !== null
    && $whatsappNameFromPayload !== $conversation['whatsapp_name']) {
    $update['whatsapp_name'] = $whatsappNameFromPayload;
}
```

`$direction` sudah dihitung di baris 74; tidak ada variabel/kolom/skema baru.

**Lanjutan (temuan review, jalur create).** Guard di atas hanya menutup jalur
UPDATE. `resolveConversationId()` Langkah 4 (conversation BARU) tetap menulis
`whatsapp_name` dari argumen keempatnya, dan pemanggil selalu mengoper
`$whatsappNameFromPayload`. Akibatnya, pesan outgoing dari WA Web/HP untuk
chat_id yang belum dikenal (mis. staff memulai chat baru dari HP) tetap
membuat conversation baru bernama staff. Fix dilengkapi dengan tidak mengoper
nama untuk outgoing:

```php
$whatsappNameForResolve = $direction === 'incoming' ? $whatsappNameFromPayload : null;
$resolved = $conversationModel->resolveConversationId($chatId, $jidType, $canonicalPhone, $whatsappNameForResolve, $knownLid);
```

Jalur reconciliation (`attachAliasToConversation()`) sudah diperiksa: tidak
menyentuh `whatsapp_name`, jadi tidak ada celah di sana.

## Dampak

- Hanya mengubah perilaku pesan outgoing dari WA Web/HP (`direction='outgoing'`,
  `sent_by_user_id=NULL`).
- Pesan incoming tetap memutakhirkan `whatsapp_name` dengan nama customer
  (tidak ada regresi).
- Alur kirim dari POS tidak lewat endpoint ini → tidak terpengaruh.
- `contact_name` (nama manual customer profile) tetap tidak pernah disentuh di
  sini.

## Verifikasi

- **Infrastruktur test DB**: `Config\Database::__construct()` sudah
  mengalihkan grup `inbox` ke `aulia_inboxdb_test` saat `ENVIRONMENT=testing`
  (`app/Config/Database.php:297-310`). Ditambah fail-closed guard baru di
  `tests/_support/bootstrap-feature.php` (config + koneksi live harus
  menunjuk ke `aulia_inboxdb_test`, kalau tidak proses test dihentikan).
- **Config baru**: `phpunit.feature.xml` (terpisah dari `phpunit.xml`
  pure-logic) — hanya menjalankan `tests/feature`.
- **Test**: `tests/feature/InboxGatewayApiWhatsappNameTest.php` (4 test):
  - `testOutgoingMessageFromWaWebDoesNotOverwriteWhatsappName` — **gagal
    (merah) sebelum fix**: `assertSame('Ahmad', ...)` dapat `'James'`;
    **hijau setelah fix** (jalur update).
  - `testOutgoingMessageForUnknownChatIdCreatesConversationWithoutStaffName` —
    **gagal (merah) sebelum guard create**: `assertNull($row['whatsapp_name'])`
    dapat `'James'`; **hijau setelah fix** (jalur create).
  - `testIncomingMessageStillUpdatesWhatsappName` — hijau (regression guard).
  - `testDefaultDirectionIsIncomingAndStillUpdatesWhatsappName` — hijau
    (kontrak `direction` opsional/default `incoming`).
- **Hasil**:
  - `php vendor/phpunit/phpunit/phpunit --configuration phpunit.feature.xml`
    → `OK (4 tests, 11 assertions)`.
  - `php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml`
    → `OK (32 tests, 1028 assertions)` (tidak ada regresi).
  - `php -l` pada file yang diubah/dibuat → tidak ada syntax error.
- **Cara menjalankan ulang**: `phpunit.feature.xml` butuh kredensial
  `database.inbox.hostname/username/password` dari `.env` (nama database
  dipaksa `aulia_inboxdb_test` oleh config saat testing). Database test dan
  tabelnya (`conversations`, `conversation_identities`, `messages`, dst) sudah
  ada di mesin dev.

## Belum diverifikasi / catatan

- Belum diuji end-to-end lewat Gateway + WhatsApp asli; verifikasi dilakukan
  pada level HTTP endpoint (feature test) dengan payload yang meniru kontrak
  Gateway (`fromMe=true`, `contact_name`=push name staff).
- Test mengasumsikan `contact_name` pada payload outgoing benar-benar berisi
  push name staff. Ini sesuai payload yang diamati pada laporan bug dan
  kontrak `InboxGatewayApi::messages()`; kalau Gateway mengubah semantik field
  ini, test perlu ditinjau.
- Entri `docs/CHANGELOG.md` ditambahkan karena perilaku yang terlihat user
  berubah (nama percakapan tidak lagi berubah jadi nama staff).

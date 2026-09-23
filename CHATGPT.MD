Saya melanjutkan pekerjaan engineering pada repository GitHub:

Repository:
-https://github.com/tikusgot007/AuliaPos
-https://github.com/tikusgot007/WA-Gateway

referensi SDLC
https://github.com/GulajavaMinistudio/awesome-copilot-id


Branch aktif:
feature/m3-operational-inbox-fase1a-task001

Checkpoint terakhir:
- M3 Operational Inbox Phase 1a + Phase 1b secara teknis sudah selesai.
- Latest commit branch: 0a614df
- `memory.instructions.md` sudah dibuat sebagai checkpoint SDLC.
- Working tree terakhir bersih.
- Full PHPUnit suite terakhir:
  233 tests
  522 assertions
  OK
- Xdebug 3.5.3 sudah aktif.
- SQLite3 sudah aktif.
- Code coverage sudah berhasil dijalankan.

Scope yang sudah selesai:
- Queue View 5 tab.
- `ConversationModel::withComputedStatus()`.
- Conversation API `queue_status`.
- Conversation Detail wiring/regression.
- Snooze Phase 1a.
- `messages.is_internal` migration.
- Internal Note endpoint:
  POST /inbox/percakapan/(:num)/catatan
- Internal Note tidak memanggil Gateway.
- Internal Note tidak mengubah `last_message_at` / `last_message_direction`.
- SLA service + threshold config.
- Conversation filter `status` dan `q`.
- Thread payload `is_internal`.
- UI rendering Internal Note secara visual berbeda.
- Test infrastructure sudah diperbaiki agar standalone harness tidak mengganggu PHPUnit.
- Test-support migration `closing_kas` sudah ditambahkan.

Dokumen utama:
- `CLAUDE.md`
- `AGENTS.md`
- `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md`
- `memory.instructions.md`
- Spec terkait di `spec/`
- ADR terkait di `docs/adr/`

PRINSIP KERJA:
1. Ikuti SDLC repository secara ketat.
2. Jangan skip phase.
3. Jangan mengarang requirement/scope yang belum ada.
4. Selalu baca upstream document yang diwajibkan sebelum mengubah code.
5. Surgical edits: ubah hanya bagian yang diperlukan.
6. Jangan `git reset --hard`.
7. Jangan menghapus test/assertion untuk membuat build hijau.
8. Jangan menambahkan suppression, skip, atau bypass test.
9. Setiap perubahan code wajib punya micro-test yang relevan.
10. Sebelum menyatakan phase selesai, wajib macro test seluruh suite.
11. Jangan menyatakan test lulus tanpa benar-benar menjalankannya.
12. Jaga history Git tetap audit-able; gunakan commit deskriptif dan feature branch.
13. Jika menambah module/directory/contract baru, update `docs/ARCHITECTURE.md`.
14. Untuk pekerjaan yang menyentuh GitHub, gunakan GitHub connector.
15. Jangan menyentuh default/production branch.

ATURAN INTERAKSI:
- Jawab dalam Bahasa Indonesia.
- Saya ingin panduan terminal SATU LANGKAH PER GILIRAN.
- Jangan memberikan beberapa command sekaligus.
- Setelah memberi satu command, tunggu output saya sebelum memberi command berikutnya.
- Jangan menggunakan pola "setelah itu lakukan A, lalu B, lalu C".
- Fokus, presisi, dan jangan mengulang langkah yang sudah terverifikasi.

KONDISI SDLC SAAT INI:
- M3 Operational Inbox Phase 1a + 1b sudah melewati approval checkpoint dan macro verification.
- Plan M3 Phase 1 berakhir di TASK-014.
- Scope setelah M3 Phase 1 belum didefinisikan dalam plan tersebut.
- Karena itu, JANGAN langsung coding fitur baru.
- Langkah SDLC berikutnya adalah `/sdlc-clarify-reqs` untuk mendefinisikan/menjernihkan scope berikutnya.
- Setelah itu ikuti urutan phase sesuai `AGENTS.md`.

TUGAS AWAL SESI:
1. Baca `memory.instructions.md`.
2. Baca `AGENTS.md` dan `CLAUDE.md`
3. Baca plan yang relevan.
4. Verifikasi state Git saat ini.
5. Jangan mengubah code sebelum scope berikutnya jelas dan upstream document yang diperlukan tersedia/approved.

tugas akhir sesi :
1. beri tahu langkah berikutnya
2. setelah phase selesai, progress, artifact aktif, keputusan penting, dan handoff berikutnya perlu dicatat ke memory.instructions.md melalui skill memory-manager.

Mulai dengan memahami checkpoint ini terlebih dahulu, lalu tunggu instruksi saya.
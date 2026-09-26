# Peta Kemajuan — Page Structure Contract

This file is the structural reference for `docs/peta-kemajuan-inbox.html`. Read it before any edit that adds or moves a section, and before rebuilding a missing page. For routine updates you only need the summary in `SKILL.md`.

## Hard rules

- **Never restyle.** Keep the `<style>` block, CSS classes, color tokens and fonts unchanged unless the page is genuinely broken in a browser. If a fix is needed, change the smallest possible rule.
- **Keep class names exact.** The legend and styling depend on these exact strings:
  - `step done`, `step partial`, `step todo`, `step gated`
  - swatches `sw done`, `sw partial`, `sw todo`, `sw gated`
  - boxes `changes`, `chain`, `lane`, `lane-head`, `track`, `notes`, `next`, `verdict`, `tag`, `date`, `where`, `id`, `eyebrow`, `lede`, `legend`.
- **Surgical edits only.** Anchor on a short unique string. Never rewrite the whole file.
- **Preserve language mix.** Page prose is simple Indonesian; identifiers (file names, hashes, function names, commands) stay verbatim.

## Top-level skeleton

```html
<div class="wrap">
  <header>
    <div class="eyebrow">Status per <tanggal> · GitHub tikusgot007/AuliaPos · v2.3 @ <hash></div>
    <h1>Peta Kemajuan M1, M2, M3</h1>
    <p class="lede">…apa halaman ini dan dari mana datanya…</p>
    <div class="legend" aria-label="Keterangan warna">
      <span><i class="sw done"></i>Selesai</span>
      <span><i class="sw partial"></i>Selesai sebagian / sedang berjalan</span>
      <span><i class="sw todo"></i>Belum mulai</span>
      <span><i class="sw gated"></i>Menunggu prasyarat / belum dijadwalkan</span>
    </div>
  </header>

  <div class="changes" aria-label="Perubahan sejak versi sebelumnya">
    <b>Berubah sejak versi <hash lama></b>
    <ul>
      <li>2–5 poin singkat, bahasa sederhana</li>
    </ul>
  </div>

  <div class="chain" aria-label="Urutan roadmap">
    <span class="node">Tahap 0</span><span class="arrow">→</span>
    <span class="node on">M1 Reliability</span><span class="arrow">→</span>
    <span class="node on">M2 State Consistency</span><span class="arrow">→</span>
    <span class="node on">M3 Operational Inbox</span><span class="arrow">→</span>
    <span class="node">M4 Customer Context</span><span class="arrow">→</span>
    <span class="node">M5 AI</span>
  </div>

  <!-- one lane per active milestone, plus Fix, plus out-of-chain feature lanes -->
  <section class="lane" aria-labelledby="<id>">
    <div class="lane-head">
      <h2 id="<id>"><span class="id"><KODE></span><Nama></h2>
      <span class="where">repo · status lokasi singkat</span>
    </div>
    <p class="verdict">1–2 kalimat: posisi milestone ini sekarang.</p>
    <div class="track">
      <div class="step done|partial|todo|gated">
        <span class="tag">label singkat</span>
        <h3>Judul langkah</h3>
        <p>satu kalimat, atau:</p>
        <ul>
          <li>bukti konkret</li>
        </ul>
        <span class="date">26 Sep</span>
      </div>
      <!-- more steps -->
    </div>
    <div class="notes">
      <div><b>Bukti</b><p>plan/spec/commit yang menopang klaim di lane ini.</p></div>
      <div><b>Risiko/Masih terbuka</b><p>yang masih terbuka, jujur.</p></div>
    </div>
  </section>

  <section class="next" aria-labelledby="next">
    <h2 id="next">Langkah berikutnya: <satu jalur utama></h2>
    <p>kenapa ini yang paling mendesak.</p>
    <ol>
      <li><span class="cmd">/command</span> … 1–3 langkah konkret</li>
    </ol>
    <p>pilihan lain yang menunggu (tidak mendesak), keputusan pemilik.</p>
  </section>

  <footer>Sumber: …commit/file per klaim… Tandai angka yang TIDAK dijalankan ulang sendiri.</footer>
</div>
```

## Lane inventory (as of the current page)

- `m1` — Reliability (repo WA-Gateway + sentuhan AuliaPos).
- `m2` — State Consistency (AuliaPos, program ditunda).
- `m3` — Operational Inbox (AuliaPos).
- `fix` — cross-milestone fix lane (contoh: isolasi database test).
- `gh011` — out-of-chain feature: Grup, Balas Pesan, Teruskan.

Add a new lane only for a genuinely new initiative that does not fit M1–M5. New lanes follow the same inner structure.

## Status semantics (which class to use)

- `done` — concrete evidence exists (plan `Completed`, review Merge / no blocker, or a written test/measurement). Optionally add a qualifier in the `<span class="tag">` (for example "Selesai · live", "Selesai · 2/8 nyata").
- `partial` — started, in progress, or a "done" claim without enough evidence.
- `todo` — not started, no blocking prerequisite.
- `gated` — deliberately waiting on a prerequisite or unscheduled.

Dates use short form (`26 Sep`). Leave the `<span class="date">` off when there is no meaningful date.

## Recovery / setup skeleton

If the page is missing and no seed and no git copy exist, create a minimal valid page with: the `<style>` block copied from any surviving sibling HTML artifact if available, else a small self-contained style block; `header` (eyebrow + h1 + lede + legend); `changes`; `chain`; one `lane` per milestone; and a `next` section. Populate only what the repository can actually source, and state in the footer that the page was rebuilt and may be incomplete.

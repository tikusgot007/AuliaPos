// Runs every K1-K11 measurement and writes results.json + screenshots/. See README.md.
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { open, waitMessages } = require('./harness');
const { count } = require('./count-loc');

const SHOTS = path.join(__dirname, 'screenshots');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures.json'), 'utf8'));
const results = {};
const only = process.argv[2] ? process.argv[2].split(',') : null;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const median = (a) => [...a].sort((x, y) => x - y)[Math.floor(a.length / 2)];
const round = (n) => Math.round(n * 10) / 10;

// Mutable payload served for fixtures.json so a scenario can change the "server" data between polls.
function payloadRoute(state) {
  return async (page) => {
    await page.route('**/fixtures.json*', (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 'success', conversation: fixtures.conversation, messages: state.messages }) }));
  };
}
const newMsg = (id, text, extra = {}) => ({ id, wa_message_id: 'WN-' + id, direction: 'incoming', message_type: 'text', text, sender_jid: fixtures.conversation.chat_id, sender_name: null, send_status: 'received',
  is_internal: false, is_forwarded: false, media_filename: null, media_local_filename: null, extra_json: null, quoted_wa_message_id: null, quoted_sender_label: null, quoted_snippet: null,
  quoted_media_available: null, quoted_source_message_id: null, quoted_media_type: null, message_timestamp: '2026-10-02 11:' + String(id % 60).padStart(2, '0') + ':00', ...extra });

// distance (px) from the newest message, whatever the scroll container's direction
const fromBottom = (page) => page.evaluate(() => {
  const el = document.getElementById('chat').shadowRoot.getElementById('messages-list');
  return getComputedStyle(el).flexDirection === 'column-reverse' ? -el.scrollTop : el.scrollHeight - el.clientHeight - el.scrollTop;
});
const setFromBottom = (page, px) => page.evaluate((px) => {
  const el = document.getElementById('chat').shadowRoot.getElementById('messages-list');
  el.scrollTop = getComputedStyle(el).flexDirection === 'column-reverse' ? -px : el.scrollHeight - el.clientHeight - px;
}, px);

const CASES = [ // id -> [label, strings that must be visible]
  [1, 'text-masuk', ['Halo kak, ready kain katun?']], [2, 'text-keluar-kasir', ['Rina (Kasir)', 'Mau berapa meter']], [3, 'image-tanpa-caption', []],
  [4, 'image-caption', ['Yang warna ini ya kak']], [5, 'sticker', []], [6, 'document-caption', ['Invoice-0912.pdf', 'Tolong dicek invoicenya']],
  [7, 'audio', ['Customer mengirim audio — cek WhatsApp Web.']], [8, 'video-caption', ['Customer mengirim video — cek WhatsApp Web.', 'Ini contoh bahannya']],
  [9, 'lokasi-biasa', ['Toko Aulia', '-7.2575, 112.7521', 'Jl. Pasar Besar 12']], [10, 'lokasi-live', ['(langsung)']], [11, 'kontak', ['Budi Santoso', '+6281234567890', 'Tanpa Nomor']],
  [12, 'unsupported-lihat-sekali', ['lihat-sekali']], [13, 'unsupported-polling', ['polling']], [14, 'unsupported-video-singkat', ['video singkat']],
  [15, 'unsupported-file-besar', ['file besar']], [16, 'unsupported-tombol', ['tombol']], [17, 'catatan-internal', ['Internal', 'Catatan internal:']], [18, 'diteruskan', ['Diteruskan']],
  [19, 'kutipan-teks-cabang-e', ['Anda', 'Halo kak, ready kain katun?']], [20, 'kutipan-cabang-a', ['[Media tidak tersedia]']], [21, 'kutipan-cabang-b-image', ['Pelanggan']],
  [22, 'kutipan-cabang-b-sticker', ['Pelanggan']], [23, 'kutipan-cabang-c-dokumen', ['Invoice-0912.pdf']], [24, 'kutipan-cabang-d-audio', ['[Audio]']],
  [25, 'kutipan-cabang-d-video', ['[Video]']], [26, 'kutipan-cabang-e-sumber-kosong', ['[Foto] lama']], [27, 'kutipan-tidak-ditemukan', ['Pesan tidak ditemukan']],
  [28, 'kutipan-gambar-404', ['[Media tidak tersedia]']], [29, 'image-kadaluarsa-404', ['Gambar tidak tersedia']], [30, 'send-status-failed', ['Pesan ini gagal terkirim']],
  [31, 'xss-script', ['<script>alert(1)</script>']], [32, 'xss-img-onerror', ['<img src=x onerror=alert(1)>']], [33, 'xss-caption-sender', ['<b onmouseover=alert(1)>Budi</b>', 'Caption XSS']],
  [34, 'xss-dokumen', ['<svg onload=alert(1)>']], [35, 'xss-kutipan', ['<i>Anda</i>', '<img src=x onerror=alert(1)>']], [36, 'xss-lokasi', ['<img src=x onerror=alert(1)>', '<script>alert(1)</script>']],
  [37, 'xss-kontak', ['<img src=x onerror=alert(1)>']], [38, 'format-teks', ['Format:']], [39, 'teks-keluar-url', ['https://example.com/cek?x=1']],
];

async function k1k7k11() {
  const lib = await open('index.html?interval=999999', { viewport: { width: 900, height: 3800 } });
  await waitMessages(lib.page, 39);
  await sleep(2500); // images load, 404 probes settle
  const out = { cases: [], libNetErrors: lib.errors.length };
  fs.mkdirSync(path.join(SHOTS, 'k1'), { recursive: true });
  const base = await open('baseline.html', { viewport: { width: 900, height: 3800 } });
  await base.page.waitForFunction(() => window.__done === true);
  await sleep(2500);
  const baseHtml = await base.page.evaluate(() => [...document.querySelectorAll('#threadMessages .inbox-bubble')].map((b) => {
    const c = b.cloneNode(true);
    c.querySelectorAll('.bubble-aksi,.bubble-meta').forEach((n) => n.remove());
    return { html: c.outerHTML, buttons: [...b.querySelectorAll('.bubble-aksi button')].map((x) => x.textContent.trim() + (x.disabled ? '[disabled]' : '')), text: c.innerText };
  }));
  const norm = (h) => h.replace(/media\/(\d+)\.png/g, 'media/$1').replace(/ data-media-pesan="\d+"/g, '').replace(/\s+/g, ' ').trim();
  for (const [id, label, expect] of CASES) {
    const msg = fixtures.messages.find((m) => m.id === id);
    const slot = await lib.page.evaluate((m) => Adapter.needsSlot(m), msg);
    const wrapper = lib.page.locator('#chat').locator(`[id="${id}"]`);
    await wrapper.scrollIntoViewIfNeeded();
    const text = slot ? await lib.page.locator(`[slot="message_${id}"]`).innerText() : await wrapper.innerText();
    const missing = expect.filter((s) => !text.includes(s));
    const row = { id, label, mode: slot ? 'slot' : 'native', missing, ok: missing.length === 0 };
    if (slot) {
      const libInfo = await lib.page.evaluate((id) => {
        const b = document.querySelector(`[slot="message_${id}"] .inbox-bubble`).cloneNode(true);
        const buttons = [...b.querySelectorAll('.bubble-aksi button')].map((x) => x.textContent.trim() + (x.disabled ? '[disabled]' : ''));
        b.querySelectorAll('.bubble-aksi,.bubble-meta').forEach((n) => n.remove());
        return { html: b.outerHTML, buttons };
      }, id);
      const bh = baseHtml[id - 1];
      row.sameHtmlAsBaseline = norm(libInfo.html) === norm(bh.html);
      row.sameButtonsAsBaseline = JSON.stringify(libInfo.buttons) === JSON.stringify(bh.buttons);
      if (!row.sameHtmlAsBaseline) row.diff = { lib: norm(libInfo.html).slice(0, 600), baseline: norm(bh.html).slice(0, 600) };
    }
    await wrapper.screenshot({ path: path.join(SHOTS, 'k1', `${String(id).padStart(2, '0')}-${label}.png`) });
    out.cases.push(row);
  }
  out.nativeCount = out.cases.filter((c) => c.mode === 'native').length;
  out.slotCount = out.cases.filter((c) => c.mode === 'slot').length;
  out.allExpectedTextPresent = out.cases.every((c) => c.ok);
  out.slotCasesIdenticalToBaselineHtml = out.cases.filter((c) => c.mode === 'slot').every((c) => c.sameHtmlAsBaseline);
  out.slotCasesIdenticalButtons = out.cases.filter((c) => c.mode === 'slot').every((c) => c.sameButtonsAsBaseline);
  // specific branch checks
  out.branches = await lib.page.evaluate(() => {
    const q = (id, sel) => document.querySelector(`[slot="message_${id}"] ${sel}`);
    return {
      cabangA: !!q(20, '.inbox-kutipan-tak-ada') && !q(20, 'img'),
      cabangB_image: !!q(21, 'img.inbox-kutipan-media') && q(21, 'img').complete && q(21, 'img').naturalWidth > 0,
      cabangB_sticker: !!q(22, 'img.inbox-kutipan-sticker'),
      cabangC_dokumen: !!q(23, 'a.inbox-kutipan-dokumen') && !q(23, 'img'),
      cabangD_audio: q(24, '.inbox-kutipan-snippet')?.textContent === '[Audio]',
      cabangD_video: q(25, '.inbox-kutipan-snippet')?.textContent === '[Video]',
      cabangE_sumberKosong: q(26, '.inbox-kutipan-snippet')?.textContent === '[Foto] lama' && !q(26, 'img'),
      tidakDitemukan: q(27, '.inbox-kutipan-pengirim')?.textContent === 'Pesan tidak ditemukan',
      gambarKutipan404JatuhKePlaceholder: q(28, '.inbox-kutipan-tak-ada')?.textContent === '[Media tidak tersedia]' && !q(28, 'img'),
      gambar404JatuhKePlaceholder: /Gambar tidak tersedia/.test(q(29, '.inbox-media-unavailable')?.textContent || ''),
      internalBedaWarna: (() => { const c = (id) => getComputedStyle(document.querySelector(`[slot="message_${id}"] .inbox-bubble`)).backgroundColor; return { internal: c(17), diteruskanKeluar: c(18) }; })(),
      labelDiteruskan: !!q(18, '.inbox-forward-label'),
      lokasiTautan: q(9, 'a')?.href,
      kontakNomor: q(11, 'div')?.textContent.includes('+6281234567890'),
    };
  });
  // K7: nothing executed, nothing injected
  out.k7 = await lib.page.evaluate(() => {
    const roots = [document, document.getElementById('chat').shadowRoot];
    const sel = (s) => roots.reduce((n, r) => n + r.querySelectorAll(s).length, 0);
    return { scriptEl: sel('script:not([src])') - document.querySelectorAll('script:not([src])').length + 0, imgSrcX: sel('img[src="x"]'), svgOnload: sel('svg[onload]'), bOnmouseover: sel('b[onmouseover]'), iEl: sel('[slot] i:not([class])'), imgWithOnerrorXss: sel('img[onerror*="alert"]') };
  });
  out.k7.dialogs = lib.dialogs;
  out.k7.pass = lib.dialogs.length === 0 && out.k7.imgSrcX === 0 && out.k7.svgOnload === 0 && out.k7.bOnmouseover === 0 && out.k7.imgWithOnerrorXss === 0 && out.k7.iEl === 0;
  await lib.page.screenshot({ path: path.join(SHOTS, 'k11-library-full.png') });
  await base.page.screenshot({ path: path.join(SHOTS, 'k11-baseline-full.png') });
  out.baselineDialogs = base.dialogs;
  results.k1 = out;
  await lib.browser.close();
  await base.browser.close();
}

async function k2() {
  const files = ['adapter.js', 'slots.js', 'media.js', 'main.js'];
  const loc = Object.fromEntries(files.map((f) => [f, count(fs.readFileSync(path.join(__dirname, f), 'utf8'))]));
  const idx = fs.readFileSync(path.join(__dirname, '../../app/Views/inbox/index.php'), 'utf8').split('\n');
  const ranges = { renderPesan: [2910, 2963], renderIsiPesan: [2760, 2908], renderKotakKutipan: [2167, 2243], renderLabelDiteruskan: [2369, 2379], aksi: [2244, 2285], aksiPesanTersedia: [2357, 2368], mediaGagal: [2602, 2742], tampilkanBubbleOutgoing: [3127, 3149] };
  const baseline = Object.fromEntries(Object.entries(ranges).map(([k, [a, b]]) => [k, count(idx.slice(a - 1, b).join('\n'))]));
  results.k2 = { loc, baseline, baselineTotal: Object.values(baseline).reduce((a, b) => a + b, 0), adapterPlusSlots: loc['adapter.js'] + loc['slots.js'], adapterSlotsMedia: loc['adapter.js'] + loc['slots.js'] + loc['media.js'], withGlue: Object.values(loc).reduce((a, b) => a + b, 0) };
}

async function k3() {
  const variants = { reassignSetiapPoll: 'index.html', lewatiBilaTidakBerubah: 'index.html?skip=1' };
  const out = {};
  await Promise.all(Object.entries(variants).map(async ([name, url]) => {
    const state = { messages: fixtures.messages };
    const t = await open(url, { viewport: { width: 900, height: 800 }, routes: payloadRoute(state) });
    await waitMessages(t.page, 39);
    await sleep(3000);
    await setFromBottom(t.page, 600);
    await sleep(300);
    await t.page.evaluate(() => {
      const root = document.getElementById('chat');
      const imgsIn = (n) => (n.nodeType === 1 ? (n.tagName === 'IMG' ? [n] : [...n.querySelectorAll('img')]) : []);
      window.__m = { imgAdded: 0, imgRemoved: 0, shadowRecords: 0, lightRecords: 0, scrollSamples: [], appliesAtStart: window.__spike.applies };
      const watch = (el, key) => new MutationObserver((recs) => recs.forEach((r) => { __m[key]++; r.addedNodes.forEach((n) => { __m.imgAdded += imgsIn(n).length; }); r.removedNodes.forEach((n) => { __m.imgRemoved += imgsIn(n).length; }); })).observe(el, { childList: true, subtree: true });
      watch(root, 'lightRecords');
      watch(root.shadowRoot, 'shadowRecords');
      window.__tagged = [...root.querySelectorAll('img'), ...root.shadowRoot.querySelectorAll('img')];
      window.__tagged.forEach((img, i) => { img.__tag = i; });
      const list = root.shadowRoot.getElementById('messages-list');
      window.__scroll0 = list.scrollTop;
      setInterval(() => __m.scrollSamples.push(list.scrollTop), 1000);
    });
    const before = await t.page.evaluate(() => ({ imgs: window.__tagged.length, scroll: window.__scroll0 }));
    await sleep(60000);
    out[name] = await t.page.evaluate(() => ({
      polls: window.__spike.applies - window.__m.appliesAtStart, imgsTagged: window.__tagged.length, taggedStillSameNode: window.__tagged.filter((i) => i.isConnected).length,
      imgAdded: window.__m.imgAdded, imgRemoved: window.__m.imgRemoved, shadowDomMutationRecords: window.__m.shadowRecords, lightDomMutationRecords: window.__m.lightRecords,
      scrollTopStart: window.__scroll0, scrollTopMin: Math.min(...window.__m.scrollSamples), scrollTopMax: Math.max(...window.__m.scrollSamples),
    }));
    out[name].before = before;
    out[name].errors = t.errors.length;
    await t.browser.close();
  }));
  // data-change scenario (new message + one changed message), default variant
  const state = { messages: fixtures.messages };
  const t = await open('index.html?interval=999999', { routes: payloadRoute(state) });
  await waitMessages(t.page, 39);
  await sleep(3000);
  await t.page.evaluate(() => { const r = document.getElementById('chat'); window.__tagged = [...r.querySelectorAll('img')]; });
  const changed = fixtures.messages.map((m) => (m.id === 2 ? { ...m, send_status: 'failed' } : m));
  state.messages = [...changed, newMsg(40, 'Pesan baru dari polling')];
  await t.page.evaluate(() => window.__spike.poll());
  await waitMessages(t.page, 40);
  await sleep(800);
  out.skenarioDataBerubah = await t.page.evaluate(() => {
    const r = document.getElementById('chat').shadowRoot;
    return { taggedStillSameNode: window.__tagged.filter((i) => i.isConnected).length, imgsTagged: window.__tagged.length, msg40Rendered: !!r.getElementById('40'), msg2ShowsFailure: !!r.querySelector('[id="2"] .vac-failure-container') };
  });
  await t.page.screenshot({ path: path.join(SHOTS, 'k3-data-berubah.png') });
  await t.browser.close();
  results.k3 = out;
}

async function k4() {
  const state = { messages: fixtures.messages };
  const t = await open('index.html?interval=999999', { routes: payloadRoute(state) });
  await waitMessages(t.page, 39);
  await sleep(2500);
  const out = {};
  let nextId = 100;
  const push = async (label) => {
    state.messages = [...state.messages, newMsg(nextId, 'Pesan baru ' + label)];
    nextId++;
    await t.page.evaluate(() => window.__spike.poll());
    await sleep(900);
    return fromBottom(t.page);
  };
  out.dasar = { jarakSetelah: await push('di dasar'), diharapkan: '<= 5px (menggulung ke bawah)' };
  await setFromBottom(t.page, 600); await sleep(300);
  const sebelum600 = await fromBottom(t.page);
  out.gulungKeAtas600 = { jarakSebelum: sebelum600, jarakSetelah: await push('saat baca ke atas 600px') };
  await setFromBottom(t.page, 50); await sleep(300);
  out.dekatDasar50 = { jarakSebelum: await fromBottom(t.page), jarakSetelah: await push('50px dari dasar') };
  await setFromBottom(t.page, 120); await sleep(300);
  out.jarak120 = { jarakSebelum: await fromBottom(t.page), jarakSetelah: await push('120px dari dasar') };
  out.ambangLibrary = {};
  for (const d of [60, 70, 80, 90, 100, 110]) {
    await setFromBottom(t.page, d); await sleep(300);
    const before = await fromBottom(t.page);
    out.ambangLibrary[d + 'px'] = (await push('ambang ' + d)) <= 5 ? 'menggulung ke bawah' : 'tidak menggulung';
  }
  await t.page.screenshot({ path: path.join(SHOTS, 'k4-setelah-skenario.png') });
  await t.browser.close();
  results.k4 = out;
}

async function k5() {
  const out = {};
  const REPEAT = Number(process.env.K5_REPEAT || 5);
  for (const rate of (process.env.K5_RATES || '1,4').split(',').map(Number)) {
    const t = await open('index.html?data=fixtures-500.json&interval=999999&expect=500', {
      viewport: { width: 900, height: 800 },
      routes: async (page) => {
        await page.addInitScript(() => {
          const expect = Number(new URLSearchParams(location.search).get('expect'));
          window.__onApply = (phase) => { if (phase === 'start' && !window.__tApply) window.__tApply = performance.now(); if (phase === 'end' && !window.__tApplyEnd) window.__tApplyEnd = performance.now(); };
          const tick = () => { const r = document.getElementById('chat') && document.getElementById('chat').shadowRoot; if (r && r.querySelectorAll('.vac-message-wrapper').length >= expect) { window.__tDone = performance.now(); return; } requestAnimationFrame(tick); };
          requestAnimationFrame(tick);
        });
        if (rate !== 1) { const c = await page.context().newCDPSession(page); await c.send('Emulation.setCPUThrottlingRate', { rate }); }
      },
    });
    await t.page.waitForFunction(() => window.__tDone, null, { timeout: 60000 });
    await sleep(2500);
    const first = await t.page.evaluate(() => ({ renderMs: window.__tDone - window.__tApply, applySyncMs: window.__tApplyEnd - window.__tApply, wrappers: document.getElementById('chat').shadowRoot.querySelectorAll('.vac-message-wrapper').length, slotChildren: document.getElementById('chat').children.length, domNodesInShadow: document.getElementById('chat').shadowRoot.querySelectorAll('*').length }));
    const upd = async (mode) => {
      const runs = [];
      for (let i = 0; i < REPEAT; i++) {
        runs.push(await t.page.evaluate(async ({ mode, i }) => {
          const list = (await (await fetch('fixtures-500.json')).json()).messages;
          if (mode === 'baru') list.push({ ...list[0], id: 5000 + i, wa_message_id: 'WZ-' + i, message_timestamp: '2026-10-02 11:5' + i + ':00', text: 'baru ' + i });
          if (mode === 'berubah') list[10] = { ...list[10], send_status: i % 2 ? 'failed' : 'sent' };
          const t0 = performance.now(); window.__spike.apply(list); const t1 = performance.now();
          await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
          return { sync: t1 - t0, total: performance.now() - t0 };
        }, { mode, i }));
        await sleep(300);
      }
      return { syncMedianMs: round(median(runs.map((r) => r.sync))), totalMedianMs: round(median(runs.map((r) => r.total))), totalMaxMs: round(Math.max(...runs.map((r) => r.total))) };
    };
    out['cpu' + rate + 'x'] = { first: { ...first, renderMs: round(first.renderMs), applySyncMs: round(first.applySyncMs) }, pollTidakBerubah: await upd('sama'), pollPesanBaru: await upd('baru'), pollSatuPesanBerubah: await upd('berubah') };
    if (rate === 1) await t.page.screenshot({ path: path.join(SHOTS, 'k5-500-pesan.png') });
    await t.browser.close();
  }
  results.k5 = out;
}

async function k5b() {
  const t = await open('index.html?interval=999999', { viewport: { width: 900, height: 800 } });
  await waitMessages(t.page, 39);
  await sleep(2000);
  results.k5b = await t.page.evaluate(async () => {
    const chat = document.getElementById('chat');
    const mk = (n) => Array.from({ length: n }, (_, i) => ({ _id: 'n' + i, senderId: i % 2 ? 'kasir' : 'customer', username: '', content: 'Pesan teks biasa nomor ' + i, date: 'Hari ini', timestamp: '10.00', saved: i % 2 === 1, disableReactions: true }));
    const frame = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
    const res = {};
    for (const n of [50, 100, 250, 500]) {
      let t0 = performance.now(); chat.messages = mk(n); const a = performance.now() - t0; await frame(); const aTotal = performance.now() - t0;
      t0 = performance.now(); chat.messages = mk(n); const b = performance.now() - t0; await frame(); const bTotal = performance.now() - t0;
      res['nativeSaja_' + n] = { assignPertamaMs: Math.round(a), totalPertamaMs: Math.round(aTotal), assignUlangSamaMs: Math.round(b), totalUlangMs: Math.round(bTotal) };
    }
    return res;
  });
  await t.browser.close();
  // current implementation (innerHTML of the whole thread), same 500 messages
  const b = await open('baseline.html', { viewport: { width: 900, height: 800 } });
  await b.page.waitForFunction(() => window.__done === true);
  results.k5b.baselineInnerHtml500 = await b.page.evaluate(async () => {
    const list = (await (await fetch('fixtures-500.json')).json()).messages;
    const runs = [];
    for (let i = 0; i < 5; i++) { const t0 = performance.now(); renderPesan(list, false); runs.push(performance.now() - t0); await new Promise((r) => setTimeout(r, 200)); }
    runs.sort((a, b) => a - b);
    return { medianMs: Math.round(runs[2]), maxMs: Math.round(runs[4]), minMs: Math.round(runs[0]) };
  });
  await b.browser.close();
}

async function k6() {
  const t = await open('index.html?interval=999999', { viewport: { width: 900, height: 800 } });
  await waitMessages(t.page, 39);
  await sleep(2000);
  const out = {};
  const w = (id) => t.page.locator('#chat').locator(`[id="${id}"]`);
  const lastAction = () => t.page.evaluate(() => window.__spike.actions.slice(-1)[0]);
  // native message: dropdown from message-actions
  for (const name of ['Balas', 'Teruskan']) {
    await w(2).scrollIntoViewIfNeeded(); await w(2).locator('.vac-message-card').hover();
    await w(2).locator('.vac-message-actions-wrapper .vac-svg-button').first().click();
    await t.page.locator('#chat').locator('.vac-menu-options').getByText(name, { exact: true }).click();
    await sleep(400);
    out['native_' + name] = await lastAction();
  }
  await w(30).scrollIntoViewIfNeeded(); await w(30).locator('.vac-message-card').hover();
  out.nativeKirimGagal_adaTombolAksi = (await w(30).locator('.vac-message-actions-wrapper .vac-svg-button').count()) > 0;
  await w(1).scrollIntoViewIfNeeded(); await w(1).locator('.vac-message-card').hover();
  out.nativeTeksMasuk_adaTombolAksi = (await w(1).locator('.vac-message-actions-wrapper .vac-svg-button').count()) > 0;
  // slot messages: in-bubble buttons
  const slotBtns = (id) => t.page.evaluate((id) => [...document.querySelectorAll(`[slot="message_${id}"] .bubble-aksi button`)].map((b) => b.textContent.trim() + (b.disabled ? '[disabled]' : '')), id);
  out.slot_audio_7 = await slotBtns(7); out.slot_lokasi_9 = await slotBtns(9); out.slot_kontak_11 = await slotBtns(11);
  out.slot_internal_17 = await slotBtns(17); out.slot_kutipanKeluar_21 = await slotBtns(21); out.slot_diteruskan_18 = await slotBtns(18);
  await t.page.locator('[slot="message_21"]').scrollIntoViewIfNeeded(); await t.page.locator('[slot="message_21"] .inbox-bubble').hover();
  await t.page.locator('[slot="message_21"] [data-act="teruskan"]').click(); await sleep(300); out.slot_Teruskan_21 = await lastAction();
  await t.page.locator('[slot="message_21"] [data-act="balas"]').click(); await sleep(300); out.slot_Balas_21 = await lastAction();
  await t.browser.close();
  results.k6 = out;
}

async function k8k9() {
  const t = await open('index.html?interval=999999', { viewport: { width: 900, height: 900 } });
  await waitMessages(t.page, 39);
  await sleep(2000);
  const out = { pemisahTanggal: await t.page.evaluate(() => {
    const r = document.getElementById('chat').shadowRoot;
    return { mulai: r.querySelector('.vac-text-started')?.textContent, pemisah: [...r.querySelectorAll('.vac-card-date')].map((e) => e.textContent.trim()), cssUppercase: getComputedStyle(r.querySelector('.vac-card-date')).textTransform };
  }) };
  const fmt = () => t.page.evaluate(() => document.getElementById('chat').shadowRoot.querySelector('[id="38"] .markdown')?.innerHTML);
  out.formatDefault = await fmt();
  await t.page.locator('#chat').locator('[id="38"]').scrollIntoViewIfNeeded();
  await t.page.locator('#chat').locator('[id="38"]').screenshot({ path: path.join(SHOTS, 'k8-format-default.png') });
  await t.page.evaluate(() => { document.getElementById('chat').textFormatting = { disabled: false, italic: '_', bold: '*', strike: '~', underline: '°', multilineCode: '```', inlineCode: '`' }; });
  await sleep(500);
  out.formatSetelahTextFormattingEksplisit = await fmt();
  await t.page.evaluate(() => { document.getElementById('chat').textFormatting = { disabled: false, italic: '_', bold: '*', strike: '~', underline: '°', multilineCode: '```', inlineCode: '`' }; });
  // K9: native replyMessage
  const r = await t.page.evaluate(() => {
    const chat = document.getElementById('chat');
    const msgs = chat.messages.map((m) => ({ ...m }));
    const last = msgs[msgs.length - 1];
    last.replyMessage = { content: 'Halo kak, ready kain katun?', senderId: 'customer', files: [{ name: 'foto', type: 'png', url: 'media/3.png' }] };
    chat.messages = msgs;
    return last._id;
  });
  await sleep(800);
  const wr = t.page.locator('#chat').locator(`[id="${r}"]`);
  await wr.scrollIntoViewIfNeeded();
  await wr.screenshot({ path: path.join(SHOTS, 'k9-replyMessage-native.png') });
  const before = await t.page.evaluate(() => document.getElementById('chat').shadowRoot.getElementById('messages-list').scrollTop);
  await t.page.evaluate(() => { window.__ev = []; const c = document.getElementById('chat'); ['go-to-reply', 'open-file', 'message-action-handler', 'open-user-tag'].forEach((n) => c.addEventListener(n, () => window.__ev.push(n))); });
  await wr.locator('.vac-reply-message').click();
  await sleep(800);
  out.k9 = { scrollTopSebelum: before, scrollTopSesudah: await t.page.evaluate(() => document.getElementById('chat').shadowRoot.getElementById('messages-list').scrollTop), eventsSetelahKlikKutipan: await t.page.evaluate(() => window.__ev),
    replyHtml: await t.page.evaluate((id) => document.getElementById('chat').shadowRoot.querySelector(`[id="${id}"] .vac-reply-message`)?.innerText, r),
    // feasibility of a custom jump (not part of the library): element for the quoted message exists by id in the shadow root
    elemenTujuanAdaDenganId: await t.page.evaluate(() => !!document.getElementById('chat').shadowRoot.getElementById('19')) };
  await t.browser.close();
  results.k8k9 = out;
}

async function k10() {
  const out = {};
  const hostOf = (u) => new URL(u).host;
  const summarise = (t) => ({ https: [...new Set(t.net.map((n) => hostOf(n.url) + ' ' + new URL(n.url).pathname.split('/').slice(0, 5).join('/')))], local: [...new Set(t.reqs.filter((u) => u.startsWith('http://localhost')).map((u) => u.replace(/^http:\/\/localhost:\d+/, '').split('?')[0]).map((p) => (/^\/media\//.test(p) ? '/media/*' : p)))] });
  let t = await open('index.html?interval=999999');
  await waitMessages(t.page, 39); await sleep(2500);
  out.footerMati = summarise(t);
  await t.browser.close();
  t = await open('index.html?interval=999999&footer=1');
  await waitMessages(t.page, 39); await sleep(2500);
  out.footerNyala = summarise(t);
  out.footerNyala.permintaanEmoji = t.net.filter((n) => n.url.includes('emoji')).length;
  await t.browser.close();
  t = await open('index.html?interval=999999&footer=1', { blockHosts: ['emoji-picker-element-data'] });
  await waitMessages(t.page, 39); await sleep(1500);
  try { await t.page.locator('#chat').locator('#vac-icon-emoji').first().click({ timeout: 3000 }); await sleep(1500); } catch (e) { out.klikEmojiError = e.message.split('\n')[0]; }
  out.emojiDiblokir = { requestGagal: t.failed, panelTetapTampil: await t.page.evaluate(() => document.getElementById('chat').shadowRoot.querySelectorAll('.vac-message-wrapper').length), errors: t.errors.slice(0, 5), pickerAda: await t.page.evaluate(() => !!document.getElementById('chat').shadowRoot.querySelector('emoji-picker')) };
  await t.page.screenshot({ path: path.join(SHOTS, 'k10-emoji-diblokir.png') });
  await t.browser.close();
  t = await open('index.html?interval=999999', { blockHosts: ['vue-advanced-chat'] });
  await sleep(1500);
  out.libraryCdnDiblokir = { errors: t.errors.slice(0, 3), pesanTampil: await t.page.evaluate(() => (document.getElementById('chat').shadowRoot ? document.getElementById('chat').shadowRoot.querySelectorAll('.vac-message-wrapper').length : 0)) };
  await t.page.screenshot({ path: path.join(SHOTS, 'k10-library-cdn-diblokir.png') });
  await t.browser.close();
  const cache = process.env.CDN_CACHE;
  const umd = fs.readFileSync(path.join(cache, 'vac.umd.js'));
  out.ukuranUmd = { mentahByte: umd.length, gzipByte: zlib.gzipSync(umd, { level: 9 }).length, brotliByte: zlib.brotliCompressSync(umd).length };
  results.k10 = out;
}

(async () => {
  const steps = { k1: k1k7k11, k2, k3, k4, k5, k5b, k6, k8: k8k9, k10 };
  for (const [name, fn] of Object.entries(steps)) {
    if (only && !only.includes(name)) continue;
    const t0 = Date.now();
    try { await fn(); console.log(name, 'ok', ((Date.now() - t0) / 1000).toFixed(1) + 's'); } catch (e) { results[name + '_error'] = e.message; console.log(name, 'ERROR', e.message.split('\n')[0]); }
  }
  const file = path.join(__dirname, only ? `results-${only.join('-')}.json` : 'results.json');
  fs.writeFileSync(file, JSON.stringify(results, null, 1));
  console.log('written', file);
})();

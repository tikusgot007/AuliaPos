// Builds baseline.html: the CURRENT render functions from app/Views/inbox/index.php, sliced by line range and run
// against the same fixture. Used for the differential check in K1/K11. Fails loudly if the line ranges drift.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../../app/Views/inbox/index.php', 'utf8').split('\n');
const slices = [
  [1012, 1029, 'function escapeHtmlInbox'], [2153, 2155, 'function adaKutipan'], [2167, 2237, 'function renderKotakKutipan'],
  [2244, 2281, 'function renderAksiBalas'], [2357, 2362, 'function aksiPesanTersedia'], [2369, 2375, 'function renderLabelDiteruskan'],
  [2600, 2963, null],
];
let code = '';
for (const [a, b, mustStart] of slices) {
  if (mustStart && !src[a - 1].includes(mustStart)) throw new Error(`line ${a} drifted, expected "${mustStart}" got "${src[a - 1]}"`);
  code += src.slice(a - 1, b).join('\n') + '\n';
}
code = code.replace(/<\?= base_url\('\/inbox\/media\/'\) \?>/g, 'media/');
if (code.includes('<?')) throw new Error('unreplaced PHP tag in extracted code');
fs.writeFileSync(__dirname + '/baseline.html', `<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Baseline v2.4</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="spike.css">
<style>.inbox-thread-messages{height:100vh;overflow-y:auto;padding:16px;background:#e5ddd5}.inbox-thread-empty{display:flex}</style></head>
<body><div class="inbox-thread-messages" id="threadMessages"></div>
<script>
const mediaGagal = new Set(), mediaSementara = new Map(), MEDIA_COBAAN_MAKS = 3, MEDIA_JEDA_COBAAN_MS = 30000, BATAS_MEDIA_UNDUH_MB = 20;
let gatewayTerhubung = true, pesanCached = {};
const pesanTerkirimTanpaKutipan = new Set();
function showToast() {}
${code}
fetch('fixtures.json').then(r => r.json()).then(d => { renderPesan(d.messages, true); window.__done = true; });
</script></body></html>
`);
console.log('baseline.html written,', code.split('\n').length, 'lines extracted');

// Shared Playwright helpers. The sandbox proxy CA is not trusted by Chromium, so CDN files are served from a
// local cache (downloaded with curl, TLS verified). Set CDN_CACHE to the cache dir; see README.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const path = require('path');
const CACHE = process.env.CDN_CACHE;
const BASE = process.env.SPIKE_URL || 'http://localhost:8765';

const cdnFile = (u) => u.includes('vue-advanced-chat') ? 'vac.umd.js' : u.includes('bootstrap') ? 'bootstrap.css'
  : u.includes('webfonts/') ? 'webfonts/' + u.split('/').pop() : u.includes('font-awesome') ? 'fa.css'
  : u.includes('emoji-picker-element-data') ? 'emoji.json' : null;

async function open(url, opts = {}) {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
  const ctx = await browser.newContext({ viewport: opts.viewport || { width: 900, height: 800 }, timezoneId: 'Asia/Jakarta', locale: 'id-ID' });
  const page = await ctx.newPage();
  const net = [], errors = [], dialogs = [], reqs = [], failed = [];
  page.on('requestfailed', (r) => failed.push(r.url().slice(0, 120) + ' ' + (r.failure() || {}).errorText));
  page.on('request', (r) => reqs.push(r.url()));
  page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss(); });
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await page.clock.setFixedTime(new Date('2026-10-02T12:00:00+07:00'));
  await page.route(/^https:\/\//, (r) => {
    const u = r.request().url(), f = cdnFile(u);
    const blocked = !f || !!(opts.blockHosts && opts.blockHosts.some((h) => u.includes(h)));
    net.push({ url: u, blocked });
    if (blocked) return r.abort();
    r.fulfill({ path: path.join(CACHE, f), headers: { 'access-control-allow-origin': '*' } });
  });
  if (opts.routes) await opts.routes(page);
  await page.goto(BASE + '/' + url);
  return { browser, page, net, errors, dialogs, reqs, failed };
}

const waitMessages = (page, n) => page.waitForFunction((n) => {
  const r = document.getElementById('chat').shadowRoot;
  return r && r.querySelectorAll('.vac-message-wrapper').length >= n;
}, n, { timeout: 15000 });

module.exports = { open, waitMessages, BASE };

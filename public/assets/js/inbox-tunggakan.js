// Banner tunggakan di Inbox -- transaksi belum lunas / mangkrak untuk
// nomor pelanggan pada percakapan yang sedang dibuka kasir.
//
// Hanya informasi (tanpa aksi tulis). Dipanggil SEKALI dari
// pilihConversation() di inbox-page.js; tidak ikut loop polling.
// Gagal fetch = banner disembunyikan, alur inbox tidak terganggu.
//
// Dependensi global: escapeHtmlInbox (inbox-thread.js),
// window.INBOX_PAGE_CONFIG.url.{suggestionBaseUrl,transaksiDetailUrl}.
// Tiap baris invoice membuka halaman detail transaksinya (tab baru).

// Penanda request terakhir: hasil lama (percakapan sudah berganti)
// tidak boleh menimpa banner percakapan yang baru.
var tunggakanRequestSeq = 0;

function formatRupiahTunggakan(angka) {
    return 'Rp ' + Math.round(Number(angka) || 0).toLocaleString('id-ID');
}

function sembunyikanBannerTunggakan() {
    var el = document.getElementById('bannerTunggakan');
    if (!el) return;
    el.style.display = 'none';
    el.innerHTML = '';
}

function kosongkanTunggakan() {
    tunggakanRequestSeq++;
    sembunyikanBannerTunggakan();
}

function muatTunggakan(conversationId) {
    var el = document.getElementById('bannerTunggakan');
    if (!el) return;

    sembunyikanBannerTunggakan();
    if (!conversationId) return;

    var base = (window.INBOX_PAGE_CONFIG && window.INBOX_PAGE_CONFIG.url
        && window.INBOX_PAGE_CONFIG.url.suggestionBaseUrl) || '';
    var seq = ++tunggakanRequestSeq;

    fetch(base + conversationId + '/tunggakan')
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (seq !== tunggakanRequestSeq) return;
            if (!json || json.status !== 'success' || !json.ada) {
                sembunyikanBannerTunggakan();
                return;
            }
            renderBannerTunggakan(json);
        })
        .catch(function() {
            if (seq !== tunggakanRequestSeq) return;
            sembunyikanBannerTunggakan();
        });
}

function renderBannerTunggakan(data) {
    var el = document.getElementById('bannerTunggakan');
    if (!el) return;

    var daftar = Array.isArray(data.transaksi) ? data.transaksi : [];
    var jumlahMangkrak = daftar.filter(function(t) { return t.kategori === 'mangkrak'; }).length;
    var jumlahBelumLunas = daftar.length - jumlahMangkrak;

    var bagian = [];
    if (jumlahBelumLunas > 0) bagian.push(jumlahBelumLunas + ' belum lunas');
    if (jumlahMangkrak > 0) bagian.push(jumlahMangkrak + ' mangkrak');

    var detailUrl = (window.INBOX_PAGE_CONFIG && window.INBOX_PAGE_CONFIG.url
        && window.INBOX_PAGE_CONFIG.url.transaksiDetailUrl) || '';

    var baris = daftar.map(function(t) {
        var kelas = t.kategori === 'mangkrak' ? ' class="inbox-tunggakan-mangkrak"' : '';
        var label = t.kategori === 'mangkrak' ? ' (mangkrak)' : '';

        var teksInvoice = escapeHtmlInbox(String(t.kode_invoice));
        var tautan = (detailUrl && Number(t.id) > 0)
            ? '<a href="' + escapeHtmlInbox(detailUrl + Number(t.id)) + '" target="_blank" rel="noopener">' + teksInvoice + '</a>'
            : teksInvoice;

        return '<li' + kelas + '>' +
            '<span>' + tautan + label +
            ' &middot; ' + Number(t.umur_hari) + ' hari</span>' +
            '<span>' + escapeHtmlInbox(formatRupiahTunggakan(t.sisa)) + '</span>' +
            '</li>';
    }).join('');

    el.innerHTML =
        '<details>' +
        '<summary class="inbox-tunggakan-judul">&#9888; Tunggakan: ' +
        escapeHtmlInbox(bagian.join(', ')) + ' &middot; sisa ' +
        escapeHtmlInbox(formatRupiahTunggakan(data.total_sisa)) + '</summary>' +
        '<ul class="inbox-tunggakan-daftar">' + baris + '</ul>' +
        '</details>';
    el.style.display = '';
}

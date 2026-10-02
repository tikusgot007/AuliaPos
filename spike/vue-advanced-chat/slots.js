// Content of the message_{id} slot: ports renderPesan / renderIsiPesan / renderKotakKutipan (spike).
(function (g) {
  const { isTrue, blank, esc, parseExtra, phoneFromVcard, clock, actionable, hasQuote } = g.Adapter;
  const NOT_AVAILABLE = '<div class="inbox-kutipan-snippet inbox-kutipan-tak-ada">[Media tidak tersedia]</div>';
  const placeholder = (icon, text, plain) => '<div class="inbox-media-unavailable"' + (plain ? ' style="font-style:normal;"' : '') + '><i class="fas ' + icon + '"></i> ' + text + '</div>';
  const caption = (m) => (m.text ? '<div class="inbox-media-caption">' + esc(m.text) + '</div>' : '');

  function quoteBox(m, ctx) {
    const notFound = blank(m.quoted_sender_label);
    const type = blank(m.quoted_media_type) ? null : String(m.quoted_media_type);
    const available = blank(m.quoted_media_available) ? null : String(m.quoted_media_available);
    const hasSource = available === '1' && !blank(m.quoted_source_message_id) && type !== null;
    const url = ctx.mediaUrl(m.quoted_source_message_id);
    let body;
    if (available === '0') {
      body = NOT_AVAILABLE;
    } else if (hasSource && (type === 'image' || type === 'sticker')) {
      body = mediaGagal.has('kutipan:' + m.id) ? NOT_AVAILABLE
        : '<img src="' + url + '" alt="Media kutipan" class="inbox-kutipan-media' + (type === 'sticker' ? ' inbox-kutipan-sticker' : '') + '" ' +
          'onerror="mediaGagal.add(\'kutipan:' + m.id + '\'); this.outerHTML=\'<div class=&quot;inbox-kutipan-snippet inbox-kutipan-tak-ada&quot;>[Media tidak tersedia]</div>\'">';
    } else if (hasSource && type === 'document') {
      body = '<a href="' + url + '" target="_blank" class="inbox-kutipan-dokumen"><i class="fas fa-file-alt"></i> ' + esc(m.quoted_snippet || '[Dokumen]') + '</a>';
    } else if (hasSource && (type === 'audio' || type === 'video')) {
      body = '<div class="inbox-kutipan-snippet">' + (type === 'audio' ? '[Audio]' : '[Video]') + '</div>';
    } else {
      body = '<div class="inbox-kutipan-snippet">' + esc(m.quoted_snippet || 'Pesan tidak ditemukan') + '</div>';
    }
    const title = notFound ? 'Pesan tidak ditemukan' : esc(m.quoted_sender_label);
    return '<div class="inbox-kutipan"><i class="fas fa-reply align-self-center text-success"></i>' +
      '<div class="inbox-kutipan-isi"><div class="inbox-kutipan-pengirim">' + title + '</div>' + body + '</div></div>';
  }

  // Same branches as the image/sticker blocks of renderIsiPesan(), unified (sticker has no caption).
  function imageOrSticker(m, ctx, kind) {
    const key = String(m.id);
    const label = kind === 'image' ? 'Gambar' : 'Sticker';
    const cap = kind === 'image' ? caption(m) : '';
    const tmp = mediaSementara.get(key);
    if (mediaGagal.has(key)) return htmlMediaTidakTersedia('kadaluarsa', kind) + cap;
    if (tmp && !bolehCobaLagiMedia(tmp)) return htmlMediaTidakTersedia(tmp.kategori, kind) + cap;
    if (!gatewayTerhubung && !m.media_local_filename) return placeholder('fa-wifi', 'Gateway terputus -- ' + label.toLowerCase() + ' belum bisa dimuat, coba lagi nanti') + cap;
    if (tmp) { tmp.cobaan += 1; tmp.terakhirMs = Date.now(); }
    return '<img src="' + ctx.mediaUrl(m.id) + '" alt="' + label + '" class="inbox-media-' + kind + '" data-media-jenis="' + kind + '" ' +
      'onload="mediaSementara.delete(\'' + key + '\')" onerror="tanganiMediaGagal(this, \'' + key + '\')">' + cap;
  }

  function location(m) {
    const x = parseExtra(m);
    if (!x || x.kind !== 'location') return placeholder('fa-map-marker-alt', 'Lokasi', true);
    const coords = x.latitude + ', ' + x.longitude;
    return '<a href="https://www.google.com/maps?q=' + encodeURIComponent(coords) + '" target="_blank" rel="noopener" style="display:block;text-decoration:none;color:inherit;">' +
      '<i class="fas fa-map-marker-alt" style="color:#e11d48;"></i> <strong>' + (x.name ? esc(x.name) : 'Lokasi') + '</strong>' +
      (x.live ? ' <span style="font-size:11px;opacity:.75;">(langsung)</span>' : '') +
      '<div style="font-size:12px;opacity:.75;">' + esc(coords) + '</div>' +
      (x.address ? '<div style="font-size:12px;opacity:.75;">' + esc(x.address) + '</div>' : '') + '</a>';
  }

  function contact(m) {
    const x = parseExtra(m);
    const list = x && Array.isArray(x.contacts) ? x.contacts : [];
    if (!list.length) return placeholder('fa-address-card', 'Kontak', true);
    return '<div style="font-style:normal;">' + list.map(function (c) {
      const phone = c ? phoneFromVcard(c.vcard) : null;
      return '<div style="font-size:13px;"><i class="fas fa-user"></i> ' + (c && c.display_name ? esc(c.display_name) : 'Kontak') +
        (phone ? ' <span style="opacity:.75;">+' + esc(phone) + '</span>' : '') + '</div>';
    }).join('') + '</div>';
  }

  const UNSUPPORTED_ICONS = [[/lihat-sekali/i, 'fa-eye-slash'], [/video singkat/i, 'fa-video'], [/polling/i, 'fa-poll'],
    [/undangan acara/i, 'fa-calendar-day'], [/katalog produk/i, 'fa-box-open'], [/file besar/i, 'fa-file'], [/album/i, 'fa-images'], [/tombol|daftar/i, 'fa-reply']];

  function unsupported(m) {
    const text = (m.text || '').trim();
    const hit = UNSUPPORTED_ICONS.find((p) => p[0].test(text));
    return placeholder(hit ? hit[1] : 'fa-comment-slash', esc(text || '[Pesan belum didukung]'), true);
  }

  function body(m, ctx) {
    switch (m.message_type) {
      case 'image': case 'sticker': return imageOrSticker(m, ctx, m.message_type);
      case 'document': return '<a href="' + ctx.mediaUrl(m.id) + '" target="_blank" class="inbox-media-document"><i class="fas fa-file-alt"></i> ' + esc(m.media_filename || 'Dokumen') + '</a>' + caption(m);
      case 'audio': case 'video': return placeholder(m.message_type === 'audio' ? 'fa-microphone' : 'fa-video', 'Customer mengirim ' + m.message_type + ' — cek WhatsApp Web.', true) + caption(m);
      case 'location': return location(m);
      case 'contact': return contact(m);
      case 'unsupported': return unsupported(m);
      default: return esc(m.text);
    }
  }

  // Buttons replace the native dropdown, which is not rendered inside a slot.
  function actions(m) {
    if (!actionable(m)) return '';
    const kind = { audio: 'audio/video', video: 'audio/video', location: 'lokasi/kontak', contact: 'lokasi/kontak' }[m.message_type];
    return '<div class="bubble-aksi"><button type="button" class="btn btn-outline-success btn-sm" data-act="balas" data-id="' + m.id + '"><i class="fas fa-reply"></i> Balas</button>' +
      (kind ? '<button type="button" class="btn btn-outline-secondary btn-sm" disabled title="Teruskan — ' + kind + ' tidak dapat diteruskan"><i class="fas fa-share"></i> Teruskan — ' + kind + ' tidak dapat diteruskan</button>'
        : '<button type="button" class="btn btn-outline-secondary btn-sm" data-act="teruskan" data-id="' + m.id + '"><i class="fas fa-share"></i> Teruskan</button>') + '</div>';
  }

  function renderSlot(m, ctx) {
    const internal = isTrue(m.is_internal);
    const dir = internal ? 'internal-note' : (m.direction === 'outgoing' ? 'outgoing' : 'incoming');
    return '<div class="inbox-bubble ' + dir + '">' +
      (internal ? '<div class="inbox-internal-label"><i class="fas fa-sticky-note"></i> Internal</div>' : '') +
      (m.sender_name ? '<div class="bubble-sender">' + esc(m.sender_name) + '</div>' : '') +
      (isTrue(m.is_forwarded) ? '<div class="inbox-forward-label"><i class="fas fa-share"></i> Diteruskan</div>' : '') +
      (hasQuote(m) ? quoteBox(m, ctx) : '') + body(m, ctx) + actions(m) +
      '<div class="bubble-meta">' + clock(m.message_timestamp) + '</div></div>';
  }

  g.Slots = { renderSlot };
})(window);

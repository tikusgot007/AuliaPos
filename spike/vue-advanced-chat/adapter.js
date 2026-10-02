// Maps one AuliaPos API message row to a vue-advanced-chat message (spike).
(function (g) {
  const isTrue = (v) => v === true || v === 1 || v === '1';
  const blank = (v) => v === null || v === undefined || v === '';

  function esc(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function parseExtra(m) {
    if (!m || !m.extra_json) return null;
    try { return JSON.parse(m.extra_json); } catch (e) { return null; }
  }

  function phoneFromVcard(vcard) {
    const hit = String(vcard || '').match(/TEL[^:]*:\s*([+0-9()\-.\s]+)/i);
    const digits = hit ? hit[1].replace(/[^0-9]/g, '') : '';
    return digits.length >= 8 ? digits : null;
  }

  const parseTs = (ts) => new Date(String(ts).replace(' ', 'T'));
  const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());

  function dayLabel(ts, now) {
    const d = parseTs(ts);
    const diff = Math.round((startOfDay(now) - startOfDay(d)) / 864e5);
    if (diff === 0) return 'Hari ini';
    if (diff === 1) return 'Kemarin';
    return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
  }

  const clock = (ts) => parseTs(ts).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });

  // Same predicate as aksiPesanTersedia(): no Balas/Teruskan for internal notes and unsent outgoing.
  function actionable(m) {
    return !isTrue(m.is_internal) && !(m.direction === 'outgoing' && m.send_status !== 'sent');
  }

  const hasQuote = (m) => !blank(m.quoted_wa_message_id);

  // Plain text and documents render natively; everything else needs the message_{id} slot.
  function needsSlot(m) {
    const nativeType = m.message_type === 'text' || m.message_type === 'document';
    return !nativeType || isTrue(m.is_internal) || isTrue(m.is_forwarded) || hasQuote(m);
  }

  function toLibMessage(m, ctx) {
    const lib = {
      _id: String(m.id),
      senderId: m.direction === 'outgoing' ? ctx.currentUserId : (m.sender_jid || 'customer'),
      username: m.sender_name || '',
      content: m.text || '',
      date: dayLabel(m.message_timestamp, ctx.now),
      timestamp: clock(m.message_timestamp),
      saved: m.send_status === 'sent',
      failure: m.send_status === 'failed',
      disableActions: !actionable(m),
      disableReactions: true,
    };
    if (m.message_type === 'document' && !needsSlot(m)) {
      const name = m.media_filename || 'Dokumen';
      lib.files = [{ name, type: name.split('.').pop(), url: ctx.mediaUrl(m.id) }];
    }
    return lib;
  }

  g.Adapter = { isTrue, blank, esc, parseExtra, phoneFromVcard, clock, actionable, hasQuote, needsSlot, toLibMessage };
})(window);

// Glue for the spike page: mount, polling, slot sync, actions. Query: ?data=fixtures-500.json &interval=4000 &skip=1 &footer=1
(function () {
  window['vue-advanced-chat'].register();
  const q = new URLSearchParams(location.search);
  const chat = document.getElementById('chat');
  const ctx = { currentUserId: 'kasir', now: new Date(), mediaUrl: (id) => 'media/' + id + '.png' };
  window.SPIKE_MEDIA_URL = ctx.mediaUrl;
  const slots = new Map();
  const spike = { ctx, chat, actions: [], applies: 0 };
  let lastText = '';
  let lastList = [];
  window.__spike = spike;

  chat.currentUserId = ctx.currentUserId;
  chat.singleRoom = true;
  chat.roomId = '1';
  chat.rooms = [{ roomId: '1', roomName: 'Pelanggan Uji', users: [{ _id: 'kasir', username: 'Kasir' }, { _id: 'customer', username: 'Pelanggan' }] }];
  chat.roomsLoaded = true;
  chat.messagesLoaded = true;
  chat.showAddRoom = false;
  chat.showSearch = false;
  chat.showAudio = false;
  chat.showEmojis = q.get('footer') === '1';
  chat.showFooter = q.get('footer') === '1';
  chat.showReactionEmojis = false;
  chat.usernameOptions = { minUsers: 0, currentUser: true };
  chat.styles = { container: { background: '#e5ddd5' }, content: { background: '#e5ddd5' }, message: { background: '#fff', backgroundMe: '#d9fdd3', color: '#212529', colorMe: '#212529' } };
  chat.messageActions = [{ name: 'balas', title: 'Balas' }, { name: 'teruskan', title: 'Teruskan' }];
  chat.textMessages = { CONVERSATION_STARTED: 'Percakapan dimulai pada:', NEW_MESSAGES: 'Pesan baru', MESSAGES_EMPTY: 'Belum ada pesan di percakapan ini.', TYPE_MESSAGE: 'Ketik pesan' };

  function syncSlots(list) {
    const keep = new Set();
    list.filter(Adapter.needsSlot).forEach(function (m) {
      const id = String(m.id);
      // client-side state that changes the markup must be part of the signature (requirements doc §7, risk 1)
      const sig = JSON.stringify(m) + mediaGagal.has(id) + mediaGagal.has('kutipan:' + id) + JSON.stringify(mediaSementara.get(id)) + gatewayTerhubung;
      let entry = slots.get(id);
      keep.add(id);
      if (!entry) {
        const el = document.createElement('div');
        el.slot = 'message_' + id;
        el.className = 'slot-row';
        chat.appendChild(el);
        entry = { el, sig: null };
        slots.set(id, entry);
      }
      if (entry.sig !== sig) {
        entry.el.innerHTML = Slots.renderSlot(m, ctx);
        entry.sig = sig;
      }
    });
    slots.forEach(function (entry, id) {
      if (!keep.has(id)) { entry.el.remove(); slots.delete(id); }
    });
  }

  function apply(list) {
    if (window.__onApply) window.__onApply('start');
    lastList = list;
    ctx.now = new Date();
    syncSlots(list);
    chat.messages = list.map((m) => Adapter.toLibMessage(m, ctx));
    spike.applies++;
    if (window.__onApply) window.__onApply('end');
  }

  async function poll() {
    const res = await fetch(q.get('data') || 'fixtures.json', { cache: 'no-store' });
    const text = await res.text();
    if (q.get('skip') === '1' && text === lastText) return;
    lastText = text;
    apply(JSON.parse(text).messages);
  }
  spike.poll = poll;
  spike.apply = apply;
  spike.setGateway = (up) => { gatewayTerhubung = up; syncSlots(lastList); };

  const act = (action, id) => spike.actions.push({ action, id: String(id) });
  chat.addEventListener('message-action-handler', (e) => act(e.detail[0].action.name, e.detail[0].message._id));
  chat.addEventListener('click', function (e) {
    const btn = e.target.closest && e.target.closest('[data-act]');
    if (btn) act(btn.dataset.act, btn.dataset.id);
  });
  poll().then(() => setInterval(poll, Number(q.get('interval')) || 4000));
})();

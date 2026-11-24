(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  const base = (document.querySelector('.a-brand')?.getAttribute('href') || '/admin/').replace(/admin\/?$/, '');

  /* ---------- toast ---------- */
  const toast = (msg, ms = 2400) => {
    const t = $('#toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('is-on');
    clearTimeout(t._h);
    t._h = setTimeout(() => t.classList.remove('is-on'), ms);
  };

  /* ---------- live notifications (badge · in-page card · browser pop-up · sound) ---------- */
  const B = document.body;
  if (B.dataset.notifLast !== undefined) {
    let last = +B.dataset.notifLast || 0;
    const badge = $('#notif-badge');
    const box = $('#ntoasts');
    const baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, '');
    const PREF = 'sp_notif_pref';
    const pref = () => { try { return JSON.parse(localStorage.getItem(PREF)) || {}; } catch { return {}; } };
    const setPref = (p) => { try { localStorage.setItem(PREF, JSON.stringify({ ...pref(), ...p })); } catch { /* private mode */ } };
    const canPop = 'Notification' in window;

    const setBadge = (n) => {
      if (badge) { badge.hidden = !n; badge.textContent = n > 99 ? '99+' : n; }
      document.title = (n ? `(${n > 99 ? '99+' : n}) ` : '') + baseTitle;
    };
    setBadge(badge && !badge.hidden ? parseInt(badge.textContent, 10) || 0 : 0);

    // When several admin tabs are open, only one announces each notification.
    const claim = (id) => {
      try {
        if (id <= (+localStorage.getItem('sp_notif_announced') || 0)) return false;
        localStorage.setItem('sp_notif_announced', String(id));
      } catch { /* ignore */ }
      return true;
    };

    let actx = null;
    const chime = () => {
      if (pref().sound === false) return;
      try {
        actx = actx || new (window.AudioContext || window.webkitAudioContext)();
        const t = actx.currentTime;
        [[880, 0], [1318.5, 0.13]].forEach(([f, d]) => {
          const o = actx.createOscillator();
          const g = actx.createGain();
          o.type = 'sine';
          o.frequency.value = f;
          g.gain.setValueAtTime(0.0001, t + d);
          g.gain.exponentialRampToValueAtTime(0.13, t + d + 0.02);
          g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.55);
          o.connect(g).connect(actx.destination);
          o.start(t + d);
          o.stop(t + d + 0.6);
        });
      } catch { /* audio not allowed yet */ }
    };

    const card = (n) => {
      if (!box) return;
      const el = document.createElement(n.link ? 'a' : 'div');
      el.className = 'ntoast ntoast--' + n.type;
      if (n.link) el.href = n.link;
      el.innerHTML = '<span class="ntoast__ic"></span><span class="ntoast__txt"><b></b><small></small><i></i></span><button type="button" class="ntoast__x" aria-label="ปิด">✕</button>';
      el.querySelector('.ntoast__ic').textContent = n.icon;
      el.querySelector('b').textContent = n.title;
      el.querySelector('small').textContent = (n.body || '').split('\n').slice(0, 2).join(' · ');
      el.querySelector('i').textContent = n.time ? n.time + ' น.' : '';
      el.querySelector('.ntoast__x').addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); el.remove(); });
      box.prepend(el);
      [...box.children].slice(4).forEach((x) => x.remove());
      setTimeout(() => { el.classList.add('is-out'); setTimeout(() => el.remove(), 400); }, 15000);
    };

    const pop = (n) => {
      if (!canPop || Notification.permission !== 'granted' || pref().popup === false) return;
      try {
        const nt = new Notification(n.icon + ' ' + n.title, { body: (n.body || '').split('\n').slice(0, 3).join('\n'), tag: 'sp-' + n.id });
        nt.onclick = () => { window.focus(); if (n.link) location.href = n.link; nt.close(); };
      } catch { /* some browsers only allow pop-ups from a service worker */ }
    };

    const announce = (list) => {
      let any = false;
      list.forEach((n) => {
        if (!claim(n.id)) return;
        any = true;
        card(n);
        if (document.hidden || !document.hasFocus()) pop(n);
      });
      if (any) chime();
    };

    let busy = false;
    const poll = async () => {
      if (busy) return;
      busy = true;
      try {
        const r = await fetch(base + 'admin/notify_poll.php?after=' + last, { headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store' });
        if (r.ok) {
          const j = await r.json();
          if (j.ok) {
            setBadge(j.unread);
            if (j.items.length) announce(j.items);
            last = Math.max(last, j.last);
          }
        }
      } catch { /* offline – try again next round */ }
      busy = false;
    };
    setInterval(poll, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    window.addEventListener('focus', poll);

    // Controls (notifications page + settings)
    const syncCtl = () => {
      const st = $('#np-state');
      const btn = $('#np-enable');
      if (!st) return;
      if (!canPop) { st.textContent = 'เบราว์เซอร์นี้ไม่รองรับป๊อปอัปแจ้งเตือน'; if (btn) btn.hidden = true; return; }
      const p = Notification.permission;
      const on = p === 'granted' && pref().popup !== false;
      st.textContent = p === 'denied' ? 'ถูกบล็อกไว้ — เปิดได้ที่ไอคอนแม่กุญแจข้างช่อง URL › การแจ้งเตือน › อนุญาต'
        : on ? 'เปิดอยู่ · จะเด้งที่มุมจอเมื่อคุณดูแท็บอื่นหรือโปรแกรมอื่น' : 'ยังไม่ได้เปิด';
      if (btn) { btn.hidden = p === 'denied'; btn.textContent = on ? 'ปิดป๊อปอัป' : 'เปิดป๊อปอัปบนเบราว์เซอร์'; }
      $('#np-box')?.classList.toggle('is-on', on);
    };
    $('#np-enable')?.addEventListener('click', async () => {
      if (Notification.permission === 'granted') {
        setPref({ popup: pref().popup === false });
      } else {
        const p = await Notification.requestPermission();
        if (p === 'granted') setPref({ popup: true });
      }
      syncCtl();
    });
    const snd = $('#np-sound');
    if (snd) {
      snd.checked = pref().sound !== false;
      snd.addEventListener('change', () => { setPref({ sound: snd.checked }); if (snd.checked) chime(); });
    }
    $('#np-test')?.addEventListener('click', () => {
      const demo = { id: 0, type: 'proof', icon: '♡', title: 'ตัวอย่าง: คุณมิ้นท์ ส่งรายการคัดรูปแล้ว', body: 'แกลเลอรี: งานแต่งงาน\nเลือก 30 ภาพ', link: '', time: '' };
      card(demo);
      chime();
      if (canPop && Notification.permission === 'granted' && pref().popup !== false) {
        try { new Notification(demo.icon + ' ' + demo.title, { body: demo.body, tag: 'sp-test' }); } catch { /* ignore */ }
      }
    });
    syncCtl();
  }

  /* ---------- Web Push (works with the admin closed) ---------- */
  const wpBtn = $('#wp-enable');
  if (wpBtn) {
    const wpState = $('#wp-state');
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    const keyBytes = (b64) => {
      const s = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((b64.length + 3) % 4));
      return Uint8Array.from(s, (c) => c.charCodeAt(0));
    };
    const wpPost = async (action, data) => {
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', action);
      Object.entries(data).forEach(([k, v]) => fd.append(k, v));
      return (await fetch(base + 'admin/push.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })).json();
    };
    let reg = null;
    let sub = null;
    const show = (txt, on) => {
      wpState.textContent = txt;
      wpBtn.textContent = on ? 'ปิด Web Push ในเครื่องนี้' : 'เปิด Web Push';
      $('#wp-test').hidden = !on;
      $('#wp-box').classList.toggle('is-on', on);
    };
    const refresh = async () => {
      if (!supported) { show('เบราว์เซอร์นี้ไม่รองรับ (iPhone/iPad ต้อง “เพิ่มไปยังหน้าจอโฮม” ก่อน)', false); wpBtn.hidden = true; return; }
      if (!window.isSecureContext) { show('ต้องเปิดเว็บผ่าน HTTPS จึงจะใช้ Web Push ได้', false); wpBtn.hidden = true; return; }
      reg = await navigator.serviceWorker.register(base + 'admin/sw.js', { scope: base + 'admin/' });
      sub = await reg.pushManager.getSubscription();
      show(sub ? 'เปิดอยู่ในเครื่องนี้ · แจ้งเตือนจะเด้งแม้ปิดหน้าเว็บ (ต้องเปิดเบราว์เซอร์ค้างไว้)'
        : (Notification.permission === 'denied' ? 'ถูกบล็อก — อนุญาตการแจ้งเตือนที่ไอคอนแม่กุญแจข้างช่อง URL' : 'ยังไม่ได้เปิดในเครื่องนี้'), !!sub);
    };
    wpBtn.addEventListener('click', async () => {
      wpBtn.disabled = true;
      try {
        if (sub) {
          await wpPost('unsubscribe', { endpoint: sub.endpoint });
          await sub.unsubscribe();
          sub = null;
        } else if (await Notification.requestPermission() === 'granted') {
          const k = await (await fetch(base + 'admin/push.php', { headers: { 'X-Requested-With': 'fetch' } })).json();
          sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(k.key) });
          const j = sub.toJSON();
          const r = await wpPost('subscribe', { endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth });
          if (!r.ok) throw new Error(r.error || 'บันทึกไม่สำเร็จ');
          toast('เปิด Web Push แล้ว ✓');
        }
      } catch (ex) {
        toast('เปิด Web Push ไม่สำเร็จ: ' + ex.message, 5000);
      }
      wpBtn.disabled = false;
      refresh();
    });
    $('#wp-test')?.addEventListener('click', async () => {
      const j = await wpPost('test', {});
      toast(j.result || 'ส่งแล้ว', 4000);
    });
    refresh().catch(() => show('เปิดไม่ได้ในเบราว์เซอร์นี้', false));
  }

  /* ---------- copy link ---------- */
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    const text = b.dataset.copy;
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      const ta = Object.assign(document.createElement('textarea'), { value: text });
      document.body.append(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
    toast(b.dataset.copyMsg || 'คัดลอกลิงก์แล้ว ✓', b.dataset.copyMsg ? 4500 : 2400);
  });

  /* ---------- notices, confirm, loading ---------- */
  $$('[data-autohide]').forEach((n) => setTimeout(() => n.classList.add('is-fading'), 7000));

  $$('form[data-confirm]').forEach((f) => f.addEventListener('submit', (e) => {
    if (!confirm(f.dataset.confirm)) e.preventDefault();
  }));
  $$('[form]').forEach((btn) => {
    const f = document.getElementById(btn.getAttribute('form'));
    if (f?.dataset.confirm) btn.addEventListener('click', (e) => { if (!confirm(f.dataset.confirm)) e.preventDefault(); else { e.preventDefault(); f.submit(); } });
  });

  $$('form[data-loading]').forEach((f) => f.addEventListener('submit', (e) => {
    if (e.defaultPrevented) return;
    const l = $('#loading');
    if (l) { $('#loading-text').textContent = f.dataset.loading; l.hidden = false; }
  }));

  /* ---------- countdowns ---------- */
  const cds = $$('[data-countdown]');
  const tick = () => {
    const now = Date.now();
    cds.forEach((el) => {
      let s = Math.max(0, Math.floor((new Date(el.dataset.countdown) - now) / 1000));
      const d = Math.floor(s / 86400); s %= 86400;
      const h = Math.floor(s / 3600); s %= 3600;
      const m = Math.floor(s / 60); s %= 60;
      el.textContent = el.hasAttribute('data-short')
        ? (d > 0 ? `${d} วัน ${h} ชม.` : `${h} ชม. ${m} นาที`)
        : `ใช้งานได้อีก ${d} วัน ${h} ชม. ${m} นาที ${s} วินาที`;
    });
  };
  if (cds.length) { tick(); setInterval(tick, 1000); }

  /* ---------- photo tiles: hide / cover ---------- */
  const grid = $('.a-grid');
  grid?.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const tile = btn.closest('.a-tile');
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', btn.dataset.act);
    fd.append('id', grid.dataset.album);
    fd.append('image', tile.dataset.id);
    btn.disabled = true;
    try {
      const r = await fetch(base + 'admin/action.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
      const j = await r.json();
      if (!j.ok) throw new Error();
      if (btn.dataset.act === 'toggle_hidden') {
        tile.classList.toggle('is-hidden', !!j.hidden);
        btn.textContent = j.hidden ? 'แสดง' : 'ซ่อน';
        const vc = $('#vis-count'); if (vc) vc.textContent = j.visible;
        toast(j.hidden ? 'ซ่อนภาพแล้ว' : 'แสดงภาพแล้ว');
      } else {
        $$('.a-tile.is-cover').forEach((t) => t.classList.remove('is-cover'));
        tile.classList.add('is-cover');
        if (!j.uploaded_cover) { const bg = $('#pv-bg'); if (bg) { bg.src = tile.querySelector('img').src.replace('s=thumb', 's=preview'); bg.hidden = false; } }
        toast(j.uploaded_cover ? 'ตั้งแล้ว (ตอนนี้ใช้ภาพปกที่อัปโหลดอยู่)' : 'ตั้งเป็นภาพปกแล้ว');
      }
    } catch { toast('ทำรายการไม่สำเร็จ ลองใหม่อีกครั้ง'); }
    btn.disabled = false;
  });

  /* ---------- pre-load thumbnails for the client ---------- */
  const warm = $('#warm');
  if (warm) {
    const total = +warm.dataset.total;
    const step = async () => {
      const fd = new FormData();
      fd.append('csrf', csrf); fd.append('action', 'warm'); fd.append('id', warm.dataset.album);
      try {
        const r = await fetch(base + 'admin/action.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
        const j = await r.json();
        $('#warm-n').textContent = j.cached;
        $('#warm-bar').style.width = (total ? Math.round(j.cached / total * 100) : 100) + '%';
        if (j.left > 0 && j.done > 0) return step();
        warm.classList.add('is-done');
        warm.querySelector('.warm__txt').innerHTML = j.left > 0
          ? `<span>เตรียมภาพตัวอย่างแล้ว ${j.cached} / ${total} ภาพ (มี ${j.left} ภาพที่ดึงไม่ได้ จะลองใหม่เมื่อลูกค้าเปิด)</span>`
          : `<span>✓ เตรียมภาพตัวอย่างครบ ${total} ภาพแล้ว ลูกค้าเปิดลิงก์จะเห็นภาพทันที</span>`;
        setTimeout(() => warm.classList.add('is-fading'), 6000);
      } catch {
        setTimeout(step, 3000);
      }
    };
    step();
  }

  /* ---------- album form: live preview & link check ---------- */
  const form = $('#album-form');
  if (!form) return;

  const pvTitle = $('#pv-title'), pvTag = $('#pv-tag'), pvMeta = $('#pv-meta'), pvBg = $('#pv-bg'), pvLogo = $('#pv-logo');
  let uploadedCover = false;

  $('#f-title')?.addEventListener('input', (e) => { pvTitle.textContent = e.target.value || 'ชื่ออัลบั้ม'; });
  $('#f-tagline')?.addEventListener('input', (e) => { pvTag.textContent = e.target.value; });

  // Cover style + date in the live preview
  const thMonths = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  $('#f-date')?.addEventListener('input', (e) => {
    const [y, m, d] = (e.target.value || '').split('-').map(Number);
    $('#pv-date').textContent = y ? `${d} ${thMonths[m - 1]} ${y + 543}` : '';
  });
  $$('input[name="cover_style"]').forEach((r) => r.addEventListener('change', () => {
    const pv = $('#pv');
    pv.classList.toggle('pv--full', r.value === 'full' && r.checked);
    pv.classList.toggle('pv--editorial', r.value !== 'full' && r.checked);
  }));

  // Proofing settings open / close with the switch
  $('#f-proof')?.addEventListener('change', (e) => $('#proof-set').classList.toggle('is-on', e.target.checked));

  const readImg = (file, cb) => { const r = new FileReader(); r.onload = () => cb(r.result); r.readAsDataURL(file); };

  const coverInput = $('#f-cover');
  coverInput?.addEventListener('change', () => {
    const f = coverInput.files[0];
    const txt = $('#cover-drop .drop__txt b');
    if (!f) return;
    txt.textContent = f.name;
    uploadedCover = true;
    readImg(f, (src) => { pvBg.src = src; pvBg.hidden = false; });
  });
  const drop = $('#cover-drop');
  if (drop) {
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', (e) => {
      if (!e.dataTransfer.files.length) return;
      coverInput.files = e.dataTransfer.files;
      coverInput.dispatchEvent(new Event('change'));
    });
  }

  const logoFile = $('#f-logo');
  const logoPv = $('#logo-pv');
  const setLogo = (src) => {
    pvLogo.hidden = !src; if (src) pvLogo.src = src;
    logoPv.innerHTML = src ? `<img src="${src}" alt="">` : '—';
  };
  const syncLogo = () => {
    const mode = $('input[name="logo_mode"]:checked')?.value;
    logoFile.classList.toggle('is-on', mode === 'custom');
    if (mode === 'none') setLogo('');
    else if (mode === 'default') setLogo(pvLogo.dataset.default);
    else if (logoFile.files[0]) readImg(logoFile.files[0], setLogo);
    else setLogo(pvLogo.dataset.current);
  };
  $$('input[name="logo_mode"]').forEach((r) => r.addEventListener('change', () => { syncLogo(); if (r.value === 'custom' && r.checked && !pvLogo.dataset.current) logoFile.click(); }));
  logoFile?.addEventListener('change', () => { $('input[name="logo_mode"][value="custom"]').checked = true; syncLogo(); });
  if (logoFile) logoFile.classList.toggle('is-on', $('input[name="logo_mode"]:checked')?.value === 'custom');

  const exp = $('#expiry-mode');
  exp?.addEventListener('change', () => { $('#expires-at').hidden = exp.value !== 'custom'; });

  const checkBtn = $('#check-btn');
  const out = $('#check-result');
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  checkBtn?.addEventListener('click', async () => {
    const sources = $('#sources').value.trim();
    if (!sources) { $('#sources').focus(); return; }
    checkBtn.disabled = true;
    checkBtn.textContent = 'กำลังตรวจ…';
    out.hidden = false;
    out.className = 'check is-loading';
    out.innerHTML = '<span class="spinner spinner--sm"></span> กำลังอ่านรายการภาพจากลิงก์…';
    try {
      const fd = new FormData();
      fd.append('csrf', csrf); fd.append('action', 'check'); fd.append('sources', sources);
      const r = await fetch(base + 'admin/action.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
      const j = await r.json();
      let html = '';
      if (j.count) {
        html += `<p class="check__ok">✓ พบ <b>${j.count}</b> ภาพ${j.size ? ' · ' + esc(j.size) : ''}</p>`;
        html += '<div class="check__thumbs">' + j.previews.map((u) => `<img src="${esc(u)}" alt="" referrerpolicy="no-referrer" loading="lazy">`).join('') + '</div>';
        pvMeta.textContent = j.count + ' ภาพ' + (j.size ? ' · ' + j.size : '');
        if (!uploadedCover && j.previews[0] && pvBg.hidden) { pvBg.src = j.previews[0]; pvBg.hidden = false; }
      }
      if (j.errors?.length) html += '<ul class="check__err">' + j.errors.map((x) => `<li>${esc(x)}</li>`).join('') + '</ul>';
      if (!j.count && !j.errors?.length) html = '<p class="check__err">ไม่พบรูปภาพในลิงก์นี้</p>';
      out.className = 'check ' + (j.count ? 'is-ok' : 'is-err');
      out.innerHTML = html;
    } catch {
      out.className = 'check is-err';
      out.textContent = 'ตรวจลิงก์ไม่สำเร็จ ลองใหม่อีกครั้ง';
    }
    checkBtn.disabled = false;
    checkBtn.textContent = 'ตรวจลิงก์';
  });
})();

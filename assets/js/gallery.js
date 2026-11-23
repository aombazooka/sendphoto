(() => {
  'use strict';
  const G = window.GALLERY;
  if (!G) return;
  const $ = (s) => document.querySelector(s);
  const $$ = (s) => [...document.querySelectorAll(s)];
  const store = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* private mode */ } },
  };
  const toast = (msg, ms = 2600) => {
    const t = $('#toast'); if (!t) return;
    t.textContent = msg; t.classList.add('is-on');
    clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('is-on'), ms);
  };
  const baht = (n) => '฿' + Number(n).toLocaleString('th-TH', { maximumFractionDigits: 2 });

  const imgs = G.images;
  const byId = new Map(imgs.map((im, i) => [im.id, { ...im, i }]));
  const media = (id, s) => `${G.base}media.php?t=${encodeURIComponent(G.token)}&i=${id}&s=${s}`;
  const original = (id, count) => `${G.base}download.php?t=${encodeURIComponent(G.token)}&i=${id}&c=${count ? 1 : 0}`;
  const P = G.proof;   // null when proofing is off

  /* ---------- in-app browsers (LINE / Facebook / Instagram) ---------- */
  const ua = navigator.userAgent;
  if (/\bLine\//i.test(ua) || /FBAN|FBAV|Instagram/i.test(ua)) {
    const bar = $('#inapp');
    bar.hidden = false;
    const a = $('#inapp-open');
    if (/\bLine\//i.test(ua)) {
      const u = new URL(location.href); u.searchParams.set('openExternalBrowser', '1'); a.href = u.toString();
    } else {
      a.textContent = 'กด ⋯ แล้วเลือก “เปิดในเบราว์เซอร์”';
      a.removeAttribute('href');
    }
  }

  /* ---------- photo size S / M / L (justified rows keep each photo's real shape) ---------- */
  const grid = $('#grid');
  const sizes = $('#sizes');
  const setSize = (s) => {
    if (!grid) return;
    grid.dataset.size = s;
    $$('#sizes button').forEach((b) => b.setAttribute('aria-checked', b.dataset.s === s));
    store.set('sp_size', s);
  };
  setSize(store.get('sp_size', 'm'));
  sizes?.addEventListener('click', (e) => { const b = e.target.closest('button[data-s]'); if (b) setSize(b.dataset.s); });

  // Section chips (Drive subfolders): highlight the section being viewed.
  const secnav = $('#secnav');
  if (secnav && 'IntersectionObserver' in window) {
    const links = new Map([...secnav.querySelectorAll('a')].map((a) => [a.dataset.sec, a]));
    const io = new IntersectionObserver((ents) => ents.forEach((en) => {
      if (!en.isIntersecting) return;
      links.forEach((a) => a.classList.remove('is-on'));
      const a = links.get(en.target.id.replace('sec-', ''));
      if (a) { a.classList.add('is-on'); secnav.scrollTo({ left: a.offsetLeft - 24, behavior: 'smooth' }); }
    }), { rootMargin: '-40% 0px -55% 0px' });
    $$('.sec').forEach((s) => io.observe(s));
  }

  // Learn a photo's shape from its thumbnail when the server didn't know it yet.
  $$('.tile img').forEach((img) => {
    const fix = () => {
      const t = img.closest('.tile');
      if (img.naturalWidth && !t.style.getPropertyValue('--r')) t.style.setProperty('--r', (img.naturalWidth / img.naturalHeight).toFixed(4));
    };
    if (img.complete) fix(); else img.addEventListener('load', fix, { once: true });
    img.addEventListener('error', () => {
      if (img.dataset.retry) { img.closest('.tile').classList.add('is-broken'); return; }
      img.dataset.retry = '1';
      setTimeout(() => { img.src = img.src + '&r=1'; }, 1200);
    });
  });

  /* ---------- state ---------- */
  const keyOf = (k) => `sp_${k}_${G.token}`;
  const saves = new Set(store.get(keyOf('sel'), []).filter((id) => byId.has(id)));
  const sent = new Set(store.get(keyOf('sent'), []).filter((id) => saves.has(id)));
  const picks = new Set(store.get(keyOf('pick'), []).filter((id) => byId.has(id)));
  let proof = null;            // what the server has for this browser
  let mode = P ? (G.allowDl ? store.get(keyOf('mode'), 'proof') : 'proof') : 'save';

  let clientKey = store.get('sp_ckey', '');
  if (!/^[a-f0-9]{32}$/.test(clientKey)) {
    const a = new Uint8Array(16);
    crypto.getRandomValues(a);
    clientKey = [...a].map((b) => b.toString(16).padStart(2, '0')).join('');
    store.set('sp_ckey', clientKey);
  }

  const order = (set) => imgs.filter((im) => set.has(im.id)).map((im) => im.id);
  const sig = (ids) => [...ids].sort((a, b) => a - b).join(',');
  const persist = () => {
    store.set(keyOf('sel'), [...saves]);
    store.set(keyOf('sent'), [...sent]);
    store.set(keyOf('pick'), [...picks]);
  };
  const locked = () => proof && proof.status === 'locked';
  const tileOf = (id) => grid?.querySelector(`.tile[data-id="${id}"]`);

  const paintTile = (id) => {
    const t = tileOf(id);
    if (!t) return;
    const on = mode === 'proof' ? picks.has(id) : saves.has(id);
    t.classList.toggle('is-on', on);
    t.classList.toggle('is-picked', picks.has(id));
    t.querySelector('.mark')?.setAttribute('aria-pressed', on);
  };
  const paintAll = () => imgs.forEach((im) => paintTile(im.id));

  const quote = (n) => {
    const extra = P && P.limit ? Math.max(0, n - P.limit) : 0;
    return { extra, amount: extra * (P ? P.price : 0) };
  };

  const togglePick = (id, force) => {
    if (!P) return;
    if (locked()) { toast('ช่างภาพล็อกรายการแล้ว หากต้องการแก้ไขกรุณาติดต่อช่างภาพ'); return; }
    if (!P.open) { toast('หมดเวลาคัดรูปแล้ว'); return; }
    const on = force ?? !picks.has(id);
    if (on && !picks.has(id) && P.limit && P.price <= 0 && picks.size >= P.limit) {
      toast(`เลือกได้สูงสุด ${P.limit} ภาพ — เอาภาพอื่นออกก่อนนะคะ`);
      return;
    }
    if (on) picks.add(id); else picks.delete(id);
    after(id);
  };
  const toggleSave = (id, force) => {
    const on = force ?? !saves.has(id);
    if (on) saves.add(id); else { saves.delete(id); sent.delete(id); }
    prepared = null;
    after(id);
  };
  const toggle = (id) => (mode === 'proof' ? togglePick(id) : toggleSave(id));
  const after = (id) => {
    paintTile(id);
    if (lbIndex >= 0 && imgs[lbIndex].id === id) syncLb();
    persist();
    render();
  };

  /* ---------- mode switch ---------- */
  const setMode = (m) => {
    if (m === 'save' && !G.allowDl) m = 'proof';
    if (m === 'proof' && !P) m = 'save';
    mode = m;
    document.body.classList.toggle('mode-proof', m === 'proof');
    document.body.classList.toggle('mode-save', m === 'save');
    $$('.modes button').forEach((b) => b.setAttribute('aria-selected', b.dataset.mode === m));
    if (P && G.allowDl) store.set(keyOf('mode'), m);
    paintAll();
    render();
  };
  $$('.modes button').forEach((b) => b.addEventListener('click', () => setMode(b.dataset.mode)));
  $$('[data-go]').forEach((a) => a.addEventListener('click', () => setMode(a.dataset.go)));

  /* ---------- download helpers (unchanged behaviour per device) ---------- */
  const coarse = window.matchMedia('(pointer: coarse)').matches;
  let canShareFiles = false;
  try {
    canShareFiles = !!(navigator.canShare && navigator.share &&
      navigator.canShare({ files: [new File([new Blob(['x'], { type: 'image/jpeg' })], 'x.jpg', { type: 'image/jpeg' })] }));
  } catch { canShareFiles = false; }
  // Android: plain downloads land in the Download folder, which gallery apps show.
  const isAndroid = /Android/i.test(ua);
  const directMode = isAndroid;
  // iPhone / iPad: the share sheet's "Save Image" puts photos straight into Photos.
  const shareMode = canShareFiles && coarse && !isAndroid;

  const batches = () => {
    const out = [];
    let cur = [], size = 0;
    const maxB = G.batchMb * 1024 * 1024;
    for (const id of order(saves)) {
      const b = byId.get(id).b || 0;
      if (cur.length && (cur.length >= G.batch || (size + b > maxB && size > 0))) { out.push(cur); cur = []; size = 0; }
      cur.push(id); size += b;
    }
    if (cur.length) out.push(cur);
    return out;
  };

  let prepared = null;   // { idx, files }
  let busy = false;

  /* ---------- dock ---------- */
  const dock = $('#dock');
  const saveBtn = $('#dock-save');
  let pastCover = true;
  const cover = $('.cover');
  if (cover && 'IntersectionObserver' in window) {
    pastCover = false;
    new IntersectionObserver(([en]) => { pastCover = !en.isIntersecting || en.intersectionRatio < 0.35; render(); },
      { threshold: [0, 0.35, 1] }).observe(cover);
  }

  const thumbs = (ids) => {
    const box = $('#dock-thumbs');
    const last = ids.slice(-3);
    const want = last.join(',');
    if (box.dataset.ids === want) return;
    box.dataset.ids = want;
    box.innerHTML = last.map((id) => `<img src="${media(id, 'thumb')}" alt="">`).join('');
  };

  const render = () => {
    if (!dock) return;
    const meter = $('#dock-meter');
    const note = $('#dock-note');
    const zip = $('#dock-zip');
    const clear = $('#dock-cancel');
    const selAll = $('#sel-all');
    if (selAll) selAll.textContent = saves.size === imgs.length ? 'ยกเลิกทั้งหมด' : 'เลือกทั้งหมด';
    const sp = $('#sel-picks'); if (sp) sp.hidden = !picks.size;
    const badge = $('#pick-badge'); if (badge) badge.textContent = picks.size ? (P.limit ? `${picks.size}/${P.limit}` : picks.size) : '';

    if (mode === 'proof') {
      const n = picks.size;
      const show = n > 0 || !!proof;
      dock.classList.toggle('is-on', show && pastCover);
      document.body.classList.toggle('has-dock', show);
      if (!show) return;
      dock.classList.add('dock--proof');
      thumbs(order(picks));
      $('#dock-count').innerHTML = P.limit ? `คัดแล้ว <b>${n}</b> / ${P.limit} ภาพ` : `คัดแล้ว <b>${n}</b> ภาพ`;
      meter.hidden = !P.limit;
      if (P.limit) {
        $('#dock-meter-bar').style.width = Math.min(100, (n / P.limit) * 100) + '%';
        meter.classList.toggle('is-over', n > P.limit);
      }
      zip.hidden = true;
      clear.hidden = locked() || !P.open || !n;
      const q = quote(n);
      const changed = !proof || sig(picks) !== sig(proof.ids);
      if (locked()) {
        note.textContent = `ช่างภาพได้รับรายการแล้ว และกำลังรีทัช ✓ (ส่งเมื่อ ${proof.submitted})`;
        saveBtn.hidden = true;
        return;
      }
      saveBtn.hidden = false;
      if (!P.open) {
        note.textContent = proof ? `ส่งรายการแล้วเมื่อ ${proof.submitted} · หมดเวลาแก้ไขแล้ว` : 'หมดเวลาส่งรายการคัดรูปแล้ว';
        saveBtn.disabled = true;
        saveBtn.textContent = proof ? 'ส่งแล้ว ✓' : 'ปิดรับรายการแล้ว';
        return;
      }
      const bits = [];
      if (q.extra) bits.push(`เกิน ${q.extra} ภาพ · เพิ่ม ${baht(q.amount)}`);
      if (proof && !changed) bits.push(`ส่งแล้วเมื่อ ${proof.submitted} · แก้ไขแล้วส่งใหม่ได้`);
      else if (proof) bits.push('มีการแก้ไขที่ยังไม่ได้ส่ง');
      else bits.push('เลือกครบแล้วกดส่งให้ช่างภาพได้เลย');
      note.textContent = bits.join(' · ');
      saveBtn.disabled = !n || (proof && !changed);
      saveBtn.textContent = proof && !changed ? 'ส่งแล้ว ✓' : (proof ? 'ส่งรายการที่แก้ไข' : 'ส่งรายการให้ช่างภาพ');
      return;
    }

    // save mode
    dock.classList.remove('dock--proof');
    saveBtn.hidden = false;
    meter.hidden = true;
    clear.hidden = false;
    const n = saves.size;
    dock.classList.toggle('is-on', n > 0 && pastCover);
    document.body.classList.toggle('has-dock', n > 0);
    if (!n) return;
    thumbs(order(saves));
    $('#dock-count').innerHTML = `เลือกแล้ว <b>${n}</b> ภาพ`;
    if (busy) return;
    if (directMode) {
      saveBtn.disabled = false;
      saveBtn.textContent = `บันทึก ${n} ภาพลงเครื่อง`;
      note.textContent = 'ไฟล์ต้นฉบับจะอยู่ในโฟลเดอร์ Download และแสดงในแอปแกลเลอรี / Google Photos';
      zip.hidden = false;
      return;
    }
    if (!shareMode) {
      saveBtn.disabled = false;
      saveBtn.textContent = `ดาวน์โหลด ${n} ภาพ (ZIP)`;
      note.textContent = 'ไฟล์ต้นฉบับความละเอียดเต็ม รวมเป็นไฟล์ ZIP ไฟล์เดียว';
      zip.hidden = true;
      return;
    }
    const bs = batches();
    const idx = bs.findIndex((b) => b.some((id) => !sent.has(id)));
    const sentCount = order(saves).filter((id) => sent.has(id)).length;
    zip.hidden = false;
    if (idx === -1) {
      saveBtn.textContent = 'บันทึกครบทุกชุดแล้ว ✓';
      saveBtn.disabled = true;
      note.textContent = `ส่งแล้ว ${sentCount} / ${n} ภาพ · กด “ล้าง” เพื่อเลือกชุดใหม่`;
      return;
    }
    saveBtn.disabled = false;
    const ready = prepared && prepared.idx === idx;
    saveBtn.textContent = ready
      ? `แตะเพื่อบันทึก ชุดที่ ${idx + 1}/${bs.length} (${bs[idx].length} ภาพ)`
      : `บันทึกภาพ ชุดที่ ${idx + 1}/${bs.length} (${bs[idx].length} ภาพ)`;
    note.textContent = `ส่งแล้ว ${sentCount} / ${n} ภาพ · เลือก “บันทึกภาพ” ในเมนูแชร์`;
  };

  const fetchBatch = async (ids, onProgress) => {
    const files = [];
    let done = 0;
    const one = async (id, k) => {
      const r = await fetch(original(id, k === 0));
      if (!r.ok) throw new Error('fetch');
      const blob = await r.blob();
      const im = byId.get(id);
      let name = im.n || `photo-${String(im.i + 1).padStart(3, '0')}`;
      if (!/\.[a-z0-9]{2,5}$/i.test(name)) name += '.' + ((blob.type.split('/')[1] || 'jpg').replace('jpeg', 'jpg'));
      files[k] = new File([blob], name, { type: blob.type || 'image/jpeg' });
      onProgress(++done, ids.length);
    };
    const queue = ids.map((id, k) => [id, k]);
    const workers = Array.from({ length: Math.min(3, queue.length) }, async () => {
      while (queue.length) { const [id, k] = queue.shift(); await one(id, k); }
    });
    await Promise.all(workers);
    return files;
  };

  const doShare = async (idx, ids) => {
    try {
      await navigator.share({ files: prepared.files });
      ids.forEach((id) => sent.add(id));
      prepared = null;
      persist();
      const left = batches().findIndex((b) => b.some((id) => !sent.has(id)));
      toast(left === -1 ? 'บันทึกครบแล้ว ขอบคุณค่ะ ♡' : `ส่งชุดที่ ${idx + 1} แล้ว แตะปุ่มเพื่อบันทึกชุดถัดไป`);
    } catch (err) {
      if (err && err.name === 'NotAllowedError') toast('ภาพพร้อมแล้ว แตะปุ่มอีกครั้งเพื่อบันทึก');
    }
    render();
  };

  const directDownload = async (ids) => {
    busy = true;
    saveBtn.disabled = true;
    if (ids.length > 1) toast('ถ้าเบราว์เซอร์ถามเรื่องดาวน์โหลดหลายไฟล์ ให้กด “อนุญาต”', 5000);
    for (let k = 0; k < ids.length; k++) {
      saveBtn.textContent = `กำลังบันทึก ${k + 1}/${ids.length}…`;
      const a = document.createElement('a');
      a.href = original(ids[k], k === 0);
      a.download = '';
      a.rel = 'noopener';
      document.body.appendChild(a);
      a.click();
      a.remove();
      await new Promise((r) => setTimeout(r, ids.length > 1 ? 1200 : 300));
    }
    busy = false;
    saveBtn.disabled = false;
    render();
    toast(`เริ่มบันทึก ${ids.length} ภาพแล้ว ดูได้ในแกลเลอรี (โฟลเดอร์ Download)`, 4000);
  };

  const submitZip = (ids, all = false) => {
    const f = $('#zip-form');
    if (!f) return;
    f.ids.value = all ? '' : ids.join(',');
    f.all.value = all ? '1' : '';
    f.submit();
    toast('กำลังเตรียมไฟล์ ZIP… การดาวน์โหลดจะเริ่มในไม่ช้า', 5000);
  };

  saveBtn?.addEventListener('click', async () => {
    if (mode === 'proof') { openSend(); return; }
    if (directMode) { if (!busy) directDownload(order(saves)); return; }
    if (!shareMode) { submitZip(order(saves)); return; }
    if (busy) return;
    const bs = batches();
    const idx = bs.findIndex((b) => b.some((id) => !sent.has(id)));
    if (idx === -1) return;
    const ids = bs[idx];
    if (prepared && prepared.idx === idx) { await doShare(idx, ids); return; }
    busy = true;
    saveBtn.disabled = true;
    try {
      const files = await fetchBatch(ids, (d, t) => { saveBtn.textContent = `กำลังเตรียมภาพ ${d}/${t}…`; });
      prepared = { idx, files };
      busy = false;
      saveBtn.disabled = false;
      render();
      await doShare(idx, ids);
    } catch {
      busy = false;
      saveBtn.disabled = false;
      toast('เตรียมภาพไม่สำเร็จ ลองใหม่อีกครั้ง หรือใช้การดาวน์โหลด ZIP');
      render();
    }
  });

  $('#dock-zip')?.addEventListener('click', () => submitZip(order(saves)));
  $('#zip-all')?.addEventListener('click', () => {
    if (confirm(`ดาวน์โหลดภาพทั้งหมด ${imgs.length} ภาพเป็นไฟล์ ZIP?`)) submitZip([], true);
  });
  $('#dock-cancel')?.addEventListener('click', () => {
    if (mode === 'proof') {
      if (!picks.size || !confirm('ล้างภาพที่คัดไว้ทั้งหมด?')) return;
      [...picks].forEach((id) => picks.delete(id));
    } else {
      saves.clear(); sent.clear(); prepared = null;
    }
    persist(); paintAll(); render();
  });
  $('#sel-all')?.addEventListener('click', () => {
    const all = saves.size !== imgs.length;
    imgs.forEach((im) => { if (all) saves.add(im.id); else saves.delete(im.id); });
    if (!all) sent.clear();
    prepared = null; persist(); paintAll(); render();
  });
  $('#sel-picks')?.addEventListener('click', () => {
    picks.forEach((id) => saves.add(id));
    prepared = null; persist(); paintAll(); render();
    toast(`เลือก ${picks.size} ภาพที่คัดไว้แล้ว กดบันทึกได้เลย`);
  });

  /* ---------- send the proofing list ---------- */
  const dlg = $('#send-dlg');
  const sendForm = $('#send-form');
  const openSend = () => {
    if (!dlg || !picks.size) return;
    const me = proof || store.get('sp_me', {});
    sendForm.name.value = sendForm.name.value || me.name || '';
    sendForm.contact.value = sendForm.contact.value || me.contact || '';
    if (proof && !sendForm.note.value) sendForm.note.value = proof.note || '';
    const q = quote(picks.size);
    $('#send-sum').innerHTML = `ภาพที่คัด <b>${picks.size} ภาพ</b>` +
      (q.extra ? ` · เกินโควตา ${q.extra} ภาพ <b>(${baht(q.amount)})</b>` : '');
    $('#send-err').hidden = true;
    dlg.showModal();
  };
  $('#send-cancel')?.addEventListener('click', () => dlg.close());
  sendForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const go = $('#send-go');
    const err = $('#send-err');
    go.disabled = true;
    go.textContent = 'กำลังส่ง…';
    try {
      const fd = new FormData(sendForm);
      fd.append('action', 'submit');
      fd.append('t', G.token);
      fd.append('key', clientKey);
      fd.append('ids', order(picks).join(','));
      const r = await fetch(G.base + 'proof.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'ส่งไม่สำเร็จ');
      proof = j.proof;
      store.set('sp_me', { name: proof.name, contact: proof.contact });
      dlg.close();
      render();
      toast('ส่งรายการให้ช่างภาพแล้ว ขอบคุณค่ะ ♡', 3500);
    } catch (ex) {
      err.textContent = ex.message || 'ส่งไม่สำเร็จ ลองใหม่อีกครั้ง';
      err.hidden = false;
    }
    go.disabled = false;
    go.textContent = 'ส่งรายการ';
  });

  const loadProof = async () => {
    if (!P) return;
    try {
      const fd = new FormData();
      fd.append('action', 'get'); fd.append('t', G.token); fd.append('key', clientKey);
      const j = await (await fetch(G.base + 'proof.php', { method: 'POST', body: fd })).json();
      if (j.ok && j.proof) {
        proof = j.proof;
        // Locked lists, or a fresh browser with nothing picked yet, show what was sent.
        if (locked() || !picks.size) {
          picks.clear();
          proof.ids.forEach((id) => { if (byId.has(id)) picks.add(id); });
          persist();
        }
        paintAll();
        render();
      }
    } catch { /* offline – local picks still work */ }
  };

  /* ---------- grid clicks ---------- */
  grid?.addEventListener('click', (e) => {
    const tile = e.target.closest('.tile');
    if (!tile) return;
    if (e.target.closest('.mark')) { toggle(+tile.dataset.id); return; }
    openLb(+tile.dataset.i);
  });

  /* ---------- lightbox ---------- */
  const lb = $('#lb');
  const lbImg = $('#lb-img');
  let lbIndex = -1;

  const syncLb = () => {
    const h = $('#lb-pick');
    if (!h || lbIndex < 0) return;
    const on = picks.has(imgs[lbIndex].id);
    h.setAttribute('aria-pressed', on);
    h.querySelector('span').textContent = on ? 'คัดแล้ว' : 'คัดรูปนี้';
  };

  const showLb = (i) => {
    lbIndex = (i + imgs.length) % imgs.length;
    const im = imgs[lbIndex];
    $('#lb-count').textContent = `${lbIndex + 1} / ${imgs.length}`;
    $('#lb-spin').hidden = false;
    lbImg.classList.remove('is-in');
    lbImg.onload = () => { $('#lb-spin').hidden = true; lbImg.classList.add('is-in'); };
    lbImg.src = media(im.id, 'preview');
    lbImg.alt = `ภาพที่ ${lbIndex + 1}`;
    const dl = $('#lb-dl');
    if (dl) {
      dl.href = original(im.id, true);
      dl.textContent = shareMode ? 'บันทึกลง Photos' : (directMode ? 'บันทึกภาพ' : 'ดาวน์โหลด');
      dl.classList.remove('is-busy');
    }
    syncLb();
    [1, -1].forEach((d) => { const n = imgs[(lbIndex + d + imgs.length) % imgs.length]; if (n) new Image().src = media(n.id, 'preview'); });
  };
  const openLb = (i) => { lb.hidden = false; document.body.classList.add('lb-open'); showLb(i); };
  const closeLb = () => { lb.hidden = true; document.body.classList.remove('lb-open'); lbIndex = -1; lbImg.removeAttribute('src'); };

  $('#lb-close').addEventListener('click', closeLb);
  $('#lb-prev').addEventListener('click', () => showLb(lbIndex - 1));
  $('#lb-next').addEventListener('click', () => showLb(lbIndex + 1));
  $('#lb-pick')?.addEventListener('click', () => togglePick(imgs[lbIndex].id));

  // iPhone / iPad: one photo → share sheet → "Save Image" goes straight into Photos.
  let lbPrepared = null;
  const lbDl = $('#lb-dl');
  const shareOne = async () => {
    try {
      await navigator.share({ files: [lbPrepared.file] });
      lbPrepared = null;
      lbDl.textContent = 'บันทึกลง Photos';
      toast('บันทึกแล้ว ♡');
    } catch (err) {
      if (err && err.name === 'NotAllowedError') { lbDl.textContent = 'แตะเพื่อบันทึก'; toast('ภาพพร้อมแล้ว แตะปุ่มอีกครั้งเพื่อบันทึก'); }
    }
  };
  lbDl?.addEventListener('click', async (e) => {
    if (!shareMode) return;
    e.preventDefault();
    if (lbDl.classList.contains('is-busy')) return;
    const id = imgs[lbIndex].id;
    if (lbPrepared && lbPrepared.id === id) { await shareOne(); return; }
    lbDl.classList.add('is-busy');
    lbDl.textContent = 'กำลังเตรียมภาพ…';
    try {
      const [file] = await fetchBatch([id], () => {});
      if (imgs[lbIndex]?.id !== id) return;
      lbPrepared = { id, file };
      lbDl.classList.remove('is-busy');
      lbDl.textContent = 'แตะเพื่อบันทึก';
      await shareOne();
    } catch {
      lbDl.classList.remove('is-busy');
      lbDl.textContent = 'บันทึกลง Photos';
      toast('เตรียมภาพไม่สำเร็จ ลองใหม่อีกครั้ง');
    }
  });
  $('#lb-stage').addEventListener('click', (e) => { if (e.target.id === 'lb-stage') closeLb(); });
  document.addEventListener('keydown', (e) => {
    if (lbIndex < 0) return;
    if (e.key === 'Escape') closeLb();
    else if (e.key === 'ArrowLeft') showLb(lbIndex - 1);
    else if (e.key === 'ArrowRight') showLb(lbIndex + 1);
    else if (e.key === ' ') { e.preventDefault(); toggle(imgs[lbIndex].id); }
  });

  let sx = 0, sy = 0;
  lb.addEventListener('touchstart', (e) => { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
  lb.addEventListener('touchend', (e) => {
    const dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) showLb(lbIndex + (dx < 0 ? 1 : -1));
    else if (dy > 90 && Math.abs(dy) > Math.abs(dx)) closeLb();
  }, { passive: true });

  setMode(mode);
  loadProof();
})();

/* KCA University e-Sign Sheet: single-page app. No frameworks, no innerHTML with data (XSS-safe by construction). */
(() => {
  'use strict';

  document.documentElement.lang = 'en';
  const app = document.getElementById('app');
  const S = { user: null, csrf: '', cfg: {}, sessions: [], sid: Number(sessionStorage.getItem('sid')) || 0, kiosk: false, pack: null, queue: [], rejected: [], ident: {}, net: true, syncedAt: 0 };
  const store = { save() { try { localStorage.setItem('kca_queue', JSON.stringify({ q: S.queue, r: S.rejected })); } catch (e) { /* storage unavailable */ } },
    load() { try { const d = JSON.parse(localStorage.getItem('kca_queue') || 'null'); if (d) { S.queue = d.q || []; S.rejected = d.r || []; } } catch (e) { /* ignore */ } } };
  store.load();
  const IDENT = { card_id: 'Exam card and student ID checked', card_only: 'Exam card checked, no student ID', photo_only: 'No exam card or ID: photo match only' };
  let idleTimer = null, searchTimer = null, emailTimer = null;

  // ---------------------------------------------------------------- helpers
  const h = (tag, attrs, ...kids) => {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === false || v == null) continue;
      if (k === 'class') el.className = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (k === 'value') el.value = v;
      else el.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of kids.flat()) if (kid != null && kid !== false) el.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
    return el;
  };
  const fmt = (iso, o) => iso ? new Intl.DateTimeFormat('en-KE', Object.assign({ timeZone: 'Africa/Nairobi', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }, o)).format(new Date(iso)) : '';
  const chip = (cls, text) => h('span', { class: 'k ' + cls }, text);
  const steps = (n) => h('ol', { class: 'steps', 'aria-label': 'Progress' }, ['Find you', 'ID check', 'Sign', 'Done'].map((t, i) =>
    h('li', { class: i + 1 < n ? 'done' : i + 1 === n ? 'now' : '', 'aria-current': i + 1 === n ? 'step' : false }, h('span', {}, i + 1 < n ? '\u2713' : String(i + 1)), t)));
  const skel = () => h('div', { class: 'skel-wrap', 'aria-busy': 'true', 'aria-label': 'Loading' }, h('div', { class: 'skel', style: 'height:110px' }), h('div', { class: 'skel', style: 'height:190px' }));
  const svgEl = (tag, attrs) => { const e = document.createElementNS('http://www.w3.org/2000/svg', tag); for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v); return e; };

  const ICONS = {
    sheet: 'M5 4h11l3 3v13H5zM8 10h8M8 14h8M8 18h5', roll: 'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01', console: 'M4 5h16v14H4zM4 9h16M8 13h4',
    records: 'M4 7a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z', director: 'M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6zM9 12l2 2 4-4', verify: 'M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6zM9 12l2 2 4-4',
    out: 'M9 4H5v16h4M16 8l4 4-4 4M20 12H9', close: 'M6 6l12 12M18 6L6 18', power: 'M12 3v8M7.2 6.6a7 7 0 1 0 9.6 0',
    units: 'M7 3h8l4 4v14H7zM15 3v4h4M10 12h6M10 16h6', pack: 'M3 8l9-5 9 5v8l-9 5-9-5zM3 8l9 5 9-5M12 13v8', flag: 'M6 3v18M6 4h11l-2 4 2 4H6', wifi: 'M3 9a14 14 0 0 1 18 0M6 12.5a9 9 0 0 1 12 0M9 16a4.5 4.5 0 0 1 6 0M12 19.5h.01',
    users: 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.3 3.3 0 0 1 0 6.3M18 14a6 6 0 0 1 3.5 6', stop: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM8 8l8 8', open: 'M5 12h14M13 6l6 6-6 6', cal: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4M8 14h3' };
  const ico = (name) => { const s = svgEl('svg', { viewBox: '0 0 24 24', 'aria-hidden': 'true', class: 'ico', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.9', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }); s.append(svgEl('path', { d: ICONS[name] })); return s; };
  // Connection status dot in the header: online / offline mode / waiting to sync / just synced.
  function paintNet() {
    const q = S.queue.length, fresh = S.syncedAt && Date.now() - S.syncedAt < 9000;
    const st = S.net === false ? (S.pack ? 'off' : 'down') : (q ? 'wait' : fresh ? 'ok' : 'on');
    const label = { on: 'Online', ok: 'Synced just now', wait: q + ' waiting to sync', off: 'Offline mode' + (q ? ' \u00b7 ' + q + ' saved' : ''), down: 'No connection' }[st];
    document.querySelectorAll('.net').forEach(e => { e.className = 'net ' + st; e.textContent = label; });
    if (fresh) { clearTimeout(paintNet.t); paintNet.t = setTimeout(paintNet, 9200); }
  }
  function showDone(title, msg, code) {
    const bar = h('div', { class: 'done-bar' });
    const tick = svgEl('svg', { viewBox: '0 0 52 52', width: 76, height: 76, class: 'tick', 'aria-hidden': 'true' });
    tick.append(svgEl('circle', { cx: 26, cy: 26, r: 24, fill: 'none', 'stroke-width': 3, class: 'tc' }), svgEl('path', { d: 'M14 27l8 8 16-17', fill: 'none', 'stroke-width': 4, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', class: 'tp' }));
    let t; const close = () => { clearTimeout(t); ov.remove(); };
    const ov = h('div', { class: 'okov', role: 'status', onclick: (e) => { if (!e.target.closest('button')) close(); } },
      h('div', { class: 'done-card' }, tick, h('h2', {}, title), h('p', { class: 'm' }, msg), steps(5),
        code && window.DEMO ? h('button', { class: 'btn sm', onclick: () => { close(); DEMO.showEmail(code); } }, 'See the email copy (demo)') : null,
        h('p', { class: 'm', style: 'margin:10px 0 0' }, 'Returning to the sign sheet...'), bar));
    document.body.append(ov); t = setTimeout(close, 3200);
  }

  function toast(msg, bad) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'show' + (bad ? ' bad' : '');
    clearTimeout(toast.t); toast.t = setTimeout(() => { t.className = ''; }, bad ? 7000 : 4500);
  }

  async function api(method, path, body, raw) {
    const opt = { method, credentials: 'same-origin', headers: { 'X-CSRF-Token': S.csrf } };
    if (body !== undefined) {
      if (body instanceof Blob) { opt.body = body; opt.headers['Content-Type'] = 'application/pdf'; }
      else { opt.body = JSON.stringify(body); opt.headers['Content-Type'] = 'application/json'; }
    }
    let res;
    try { res = await fetch('/api' + path, opt); const was = S.net; S.net = true; if (!was) setTimeout(syncQueue, 0); paintNet(); }
    catch {
      S.net = false; paintNet();
      if (raw !== 'sync') { const off = offlineApi(method, path, body); if (off) { paintNet(); return off; } }
      throw Object.assign(new Error('Cannot reach the server. Check the Wi-Fi connection.'), { network: true });
    }
    const data = await res.json().catch(() => ({}));
    if (res.status === 401 && S.user) { S.user = null; go('#/login'); throw Object.assign(new Error('Your session expired. Sign in again.'), { status: 401 }); }
    if (!res.ok) throw Object.assign(new Error(data.error || 'Request failed'), { status: res.status, data });
    return data;
  }

  // Offline mode: only works when the chief supervisor switched it on (the roster pack was downloaded while online).
  function offlineApi(method, path, body) {
    if (!S.pack) return null;
    let m;
    if (method === 'GET' && path === '/sessions') return { sessions: S.sessions };
    if (method === 'GET' && (m = path.match(/^\/sessions\/\d+\/students\?q=(.*)$/))) {
      const q = decodeURIComponent(m[1]).toUpperCase().replace(/[^A-Z0-9]/g, '');
      S.misses = (S.misses || []).filter(t => Date.now() - t < 120000);
      if (S.misses.length >= 5) throw Object.assign(new Error('Search is locked after several wrong admission numbers. Call the invigilator to unlock it.'), { status: 429, data: { locked: true } });
      if (q.length >= 6 && !S.pack.students.some(x => x.adm_no.toUpperCase().replace(/[^A-Z0-9]/g, '') === q)) S.misses.push(Date.now()); else if (q.length >= 6) S.misses = [];
      return { students: q.length < 6 ? [] : S.pack.students.filter(x => x.adm_no.toUpperCase().replace(/[^A-Z0-9]/g, '') === q).map(x => ({ adm_no: x.adm_no, full_name: x.full_name, programme: x.programme, photo: x.photo })) };
    }
    if (method === 'GET' && (m = path.match(/^\/sessions\/\d+\/students\/([^/]+)\/units$/))) {
      const st = S.pack.students.find(x => x.adm_no === decodeURIComponent(m[1])); if (!st) return null;
      const pub = { adm_no: st.adm_no, full_name: st.full_name, programme: st.programme, photo: st.photo };
      if (!st.cleared) return { student: pub, cleared: false, reason: st.reason, units: [] };
      return { student: pub, cleared: true, prefill: st.prefill, offline: true, units: st.units.map(u => { const q = S.queue.find(x => x.body.adm_no === st.adm_no && x.body.unit_code === u.code); return { code: u.code, name: u.name, in_session: u.in_session !== false, signed: !!q, queued: !!q, can_edit: false, signature_id: null, signed_at: q ? q.at : null, version: q ? 1 : null }; }) };
    }
    if (method === 'GET' && /\/signatures\//.test(path)) throw Object.assign(new Error('Not signed yet.'), { status: 404 });
    if (method === 'POST' && /^\/sessions\/\d+\/signatures$/.test(path)) {
      if (S.queue.some(x => x.body.adm_no === body.adm_no && x.body.unit_code === body.unit_code)) throw Object.assign(new Error('Already saved on this device for this unit.'), { status: 409 });
      const st = S.pack.students.find(x => x.adm_no === body.adm_no);
      const ref = 'OFF-' + String(S.queue.length + 1 + S.rejected.length).padStart(4, '0');
      S.queue.push({ ref, sid: Number(path.match(/sessions\/(\d+)/)[1]), at: new Date().toISOString(), body: { ...body, queued_at: new Date().toISOString() } }); store.save();
      return { ok: true, queued: true, ref, email: { status: 'queued', to: st ? st.prefill.email : '' } };
    }
    return null;
  }
  async function syncQueue() {
    if (syncing || !S.queue.length || !S.user) return; syncing = true;
    try {
      for (const q of [...S.queue]) {
        try { await api('POST', `/sessions/${q.sid || (cur() ? cur().id : S.sid)}/signatures`, q.body, 'sync'); S.queue = S.queue.filter(x => x !== q); store.save(); S.syncedAt = Date.now(); paintNet(); toast('Synced ' + q.ref + '. Sealed copy sent to the student.'); }
        catch (e) { if (e.network) break; S.queue = S.queue.filter(x => x !== q); S.rejected.push({ ref: q.ref, why: e.message, q }); store.save(); toast(q.ref + ' was rejected by the server: ' + e.message, true); }
      }
    } finally { syncing = false; paintNet(); if (location.hash.startsWith('#/sheet')) render(); }
  }
  let syncing = false, netBlocked = false;
  setInterval(syncQueue, 8000); window.addEventListener('online', syncQueue);

  const home = () => S.user && S.user.role === 'exam_director' ? '#/director' : S.user && S.user.role === 'coordinator' ? '#/timetable' : '#/console';
  const go = (hash) => { if (location.hash === hash) render(); else location.hash = hash; };
  const clearTimers = () => { clearTimeout(idleTimer); clearTimeout(searchTimer); clearTimeout(emailTimer); };

  // ----------------------------------------------------------------- shell
  function shell(active, ...content) {
    const nav = S.user.role === 'coordinator' ? [['#/timetable', 'Timetable', 'timetable', 'cal']] : [['#/sheet', 'Sign sheet', 'sheet', 'sheet'], ['#/rollcall', 'Roll call', 'rollcall', 'roll'], ['#/console', 'Console', 'console', 'console']];
    if (['supervisor', 'exam_director'].includes(S.user.role)) nav.push(['#/records', 'Records', 'records', 'records']);
    if (S.user.role === 'exam_director') nav.push(['#/timetable', 'Timetable', 'timetable', 'cal'], ['#/director', 'Exam director', 'director', 'director']);
    nav.push(['#/verify', 'Verify', 'verify', 'verify']);
    const kiosk = S.kiosk && Date.now() > (S.unlock || 0);
    const roleName = ({ supervisor: 'Chief supervisor', invigilator: 'Invigilator', exam_director: 'Exam director', coordinator: 'School exam coordinator' })[S.user.role];
    // Side menu: floats over the page (position:fixed), so it never takes space from the content.
    const scrim = h('div', { class: 'scrim', onclick: () => setMenu(false) });
    const badge = h('span', { class: 'badge', hidden: true });
    const items = nav.map(([href, label, id, ic]) => h('a', { href, class: 'mi', 'aria-current': id === active ? 'page' : false, onclick: () => setMenu(false) }, ico(ic), h('span', {}, label), id === 'rollcall' ? badge : null));
    const closeBtn = h('button', { class: 'xbtn', 'aria-label': 'Close menu', onclick: () => setMenu(false) }, ico('close'));
    const drawer = h('aside', { class: 'drawer', id: 'drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Main menu', 'aria-hidden': 'true', inert: true },
      h('div', { class: 'dr-head' }, h('div', {}, h('b', {}, S.user.name), h('div', { class: 'm' }, roleName)), closeBtn),
      h('nav', { 'aria-label': 'Main' }, items),
      h('div', { class: 'dr-foot' }, h('button', { class: 'mi out', onclick: () => { setMenu(false); logout(); } }, ico('out'), h('span', {}, 'Sign out'))));
    const burger = h('button', { class: 'menubtn', type: 'button', 'aria-label': 'Menu', 'aria-expanded': 'false', 'aria-controls': 'drawer', hidden: kiosk, onclick: () => setMenu(true) }, h('span', { class: 'bars' }, h('i'), h('i'), h('i')), h('span', { class: 'ml' }, 'Menu'));
    let opener = burger;
    function setMenu(open) {
      drawer.classList.toggle('open', open); scrim.classList.toggle('open', open);
      drawer.setAttribute('aria-hidden', String(!open)); open ? drawer.removeAttribute('inert') : drawer.setAttribute('inert', '');
      burger.setAttribute('aria-expanded', String(open)); document.body.classList.toggle('menu-open', open);
      if (open) { loadBadge(); setTimeout(() => (drawer.querySelector('a[aria-current=page]') || items[0]).focus(), 30); } else if (document.activeElement && drawer.contains(document.activeElement)) opener.focus();
    }
    async function loadBadge() {
      if (!S.sid) return;
      try { const r = await api('GET', `/sessions/${S.sid}/rollcall`); const n = new Set((r.units || []).flatMap(u => u.pending.map(x => x.adm_no))).size; badge.textContent = String(n); badge.hidden = !n; badge.setAttribute('aria-label', n + ' students have not signed'); } catch (e) { /* menu still works */ }
    }
    drawer.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { setMenu(false); return; }
      if (e.key !== 'Tab') return;
      const f = [...drawer.querySelectorAll('a,button')]; const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    const tag = h('span', { class: 'kiosk', hidden: !kiosk }, 'Exam device · hold the logo for 3 seconds for the staff menu');
    const net = h('span', { class: 'net on', role: 'status' }, 'Online');
    setTimeout(paintNet, 0);
    let hold = null; const lg = h('div', { class: 'logo' }, h('img', { src: window.KCA_LOGO, alt: 'KCA University' })); const stopHold = () => { clearTimeout(hold); lg.classList.remove('holding'); };
    const logo = h('div', { class: 'brand', style: 'touch-action:manipulation;user-select:none', onpointerdown: () => { lg.classList.add('holding'); hold = setTimeout(() => { lg.classList.remove('holding'); S.misses = []; if (S.sid) api('POST', `/sessions/${S.sid}/lookups/reset`).catch(() => {}); S.unlock = Date.now() + 90000; burger.hidden = false; tag.hidden = true; toast('Staff menu unlocked for 90 seconds.'); setTimeout(() => { if (S.kiosk && Date.now() > S.unlock) { setMenu(false); burger.hidden = true; tag.hidden = false; } }, 90500); }, 3000); },
      onpointerup: stopHold, onpointerleave: stopHold, onpointercancel: stopHold }, lg, h('span', { class: 'bt' }, 'KCA University', h('small', {}, 'Exam e-Sign Sheet')));
    return h('div', {},
      h('header', { class: 'top' }, h('div', { class: 'top-in' }, logo, tag, h('span', { class: 'grow' }), net, burger)),
      scrim, drawer,
      h('main', {}, ...content));
  }

  async function logout() {
    try { await api('POST', '/logout'); } catch { /* already gone */ }
    S.user = null; S.sessions = []; sessionStorage.removeItem('sid'); go('#/login');
  }

  // ----------------------------------------------------------------- login
  function viewLogin() {
    const err = h('div', { class: 'err', hidden: true, role: 'alert' });
    const email = h('input', { type: 'email', id: 'em', autocomplete: 'username', required: true, placeholder: 'name@kcau.ac.ke' });
    const pw = h('input', { type: 'password', id: 'pw', autocomplete: 'current-password', required: true });
    const btn = h('button', { class: 'btn gold', type: 'submit', style: 'width:100%;min-height:50px' }, 'Sign in with portal account');
    const form = h('form', { onsubmit: async (e) => {
      e.preventDefault(); err.hidden = true; btn.disabled = true;
      try {
        const r = await api('POST', '/login', { email: email.value, password: pw.value });
        S.user = r.user; S.csrf = r.csrf; pw.value = '';
        go(home());
      } catch (ex) { err.textContent = ex.message; err.hidden = false; btn.disabled = false; }
    } },
      err,
      h('div', { class: 'field' }, h('label', { for: 'em' }, 'Staff email'), email),
      h('div', { class: 'field' }, h('label', { for: 'pw' }, 'Password'), pw), btn);
    const dev = S.cfg.dev ? h('div', { class: 'inf', style: 'margin-top:16px' },
      h('b', {}, 'Development mode (mock portal). '), 'Demo accounts (email / password): ', h('br'), 'Chief supervisor: supervisor@kcau.ac.ke / Demo#Super1', h('br'), 'Invigilator: invigilator1@kcau.ac.ke / Demo#Invig1', h('br'), 'Exam director: director@kcau.ac.ke / Demo#Direct1', h('br'), 'School exam coordinator: coordinator@kcau.ac.ke / Demo#Coord1', h('br'), 'Other staff (timetable assignments), e.g. peter.otieno@kcau.ac.ke / Demo#Staff1') : null;
    app.replaceChildren(
      h('header', { class: 'top' }, h('div', { class: 'top-in' }, h('div', { class: 'brand' }, h('div', { class: 'logo' }, h('img', { src: window.KCA_LOGO, alt: 'KCA University' })), h('span', { class: 'bt' }, 'KCA University', h('small', {}, 'Exam e-Sign Sheet'))))),
      h('main', {}, h('div', { class: 'center' }, h('img', { src: window.KCA_LOGO, alt: 'KCA University', class: 'hero-logo' }), h('div', { class: 'card' }, h('h1', {}, 'Staff sign in'),
        h('p', { class: 'sub' }, 'Use your KCA school email (name@kcau.ac.ke) and portal password. Outside email addresses are refused. Students never sign in; they sign on the exam device.'), form), dev,
        h('p', { class: 'm', style: 'text-align:center' }, h('a', { href: '#/verify' }, 'Verify a sign sheet or receipt')))));
  }

  // ------------------------------------------------------------- timetable
  function sampleCsv(school) {
    const nb = (d) => { const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Nairobi', year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', hour12: false }).formatToParts(d).map(x => [x.type, x.value])); return p; };
    const t0 = new Date(Math.ceil((Date.now() + 15 * 60e3) / 300e3) * 300e3), t1 = new Date(t0.getTime() + 3 * 3600e3), tm = new Date(Date.now() + 86400e3);
    const hm = (d) => { const p = nb(d); return `${Number(p.hour)}:${p.minute.padStart(2, '0')}`; }, endOf = nb(t1).day === nb(t0).day ? hm(t1) : '23:59', dmy = (d) => { const p = nb(d); return `${p.day}/${p.month}/${p.year}`; };
    const long = (d) => new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Nairobi', day: 'numeric', month: 'short', year: 'numeric' }).format(d);
    const L = ['Unit Code,Exam Date,Start Time,End Time,Room,Lecturer,Candidates',
      `bsd4101,${dmy(t0)},${hm(t0)},${endOf},hall c,Dr G Wanjiru,120`, `BSD 4102,${dmy(t0)},${hm(t0)},${endOf},Hall C ,,95`, `BIT 32O1,${dmy(t0)},${hm(t0)},${endOf},Lab 2,Mr P. Otieno,45`,
      `BIT 3202,${long(tm)},9am,12pm,Lab 1,,40`];
    if (school === 'All schools') L.push(`BBM 3101,${long(tm)},09:00,12:00,Hall B,Dr Anne Njeri,150`, `bbm3102,${long(tm)},09.00,12.00,hall b,David Kamau,110`, `BED 2101,${long(tm)},2:00pm,5:00pm,Hall D,Joyce Wambui,80`, `BED 2102,${long(tm)},14:00,17:00,Hall D,,60`);
    return L.join('\n');
  }
  const SCHOOL_NAMES = ['All schools', 'School of Technology', 'School of Business', 'School of Education'];

  async function viewTimetable() {
    const cfg = await api('GET', '/timetable');
    const F = { school: cfg.role === 'coordinator' ? cfg.school : 'All schools', period: 'Sem 2 2026, Main exams', ratio: 40, late: 30, csv: '' };
    let imp = null;
    const host = h('div', {});
    app.replaceChildren(shell('timetable', h('h1', {}, 'Exam timetable'),
      h('p', { class: 'sub' }, cfg.role === 'coordinator' ? 'Upload your school\'s exam timetable. The system tidies it, checks every unit against the portal and proposes who supervises each sitting. The exam director approves it before sessions appear.' : 'Upload or review exam timetables. Sessions are created from the approved timetable, so nobody types units or venues by hand.'), host));
    const run = async (fn) => { try { return await fn(); } catch (ex) { toast(ex.message, true); } };
    const preview = () => run(async () => { imp = (await api('POST', '/timetable/preview', { school: F.school, period: F.period, ratio: F.ratio, late_after: F.late, csv: F.csv })).import; paint(); setTimeout(() => { const el = document.getElementById('ttprev'); if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 60); });

    function formCard() {
      const csv = h('textarea', { id: 'ttcsv', rows: 8, spellcheck: 'false', 'aria-label': 'Timetable CSV', placeholder: 'Unit Code,Exam Date,Start Time,End Time,Room,Lecturer,Candidates\nBSD 4101,03/11/2026,09:00,12:00,Hall C,Dr G Wanjiru,120', style: 'font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;min-height:150px', oninput: (e) => { F.csv = e.target.value; } }); csv.value = F.csv;
      const file = h('input', { type: 'file', id: 'ttfile', accept: '.csv,.txt,text/csv,text/plain', 'aria-label': 'Choose timetable file', onchange: (e) => { const f = e.target.files[0]; if (!f) return; const rd = new FileReader(); rd.onload = () => { F.csv = String(rd.result); csv.value = F.csv; toast('File loaded. Press Check timetable.'); }; rd.readAsText(f); } });
      const school = h('select', { id: 'ttsch', disabled: cfg.role === 'coordinator', onchange: (e) => { F.school = e.target.value; } }, SCHOOL_NAMES.map(n => h('option', { value: n, selected: n === F.school }, n)));
      return h('div', { class: 'card' }, h('h2', {}, '1 · Upload the timetable'),
        h('div', { class: 'cols' }, h('div', { class: 'field' }, h('label', { for: 'ttsch' }, 'School'), school), h('div', { class: 'field' }, h('label', { for: 'ttper' }, 'Exam period'), h('input', { id: 'ttper', type: 'text', maxlength: 80, value: F.period, oninput: (e) => { F.period = e.target.value; } }))),
        h('div', { class: 'cols' }, h('div', { class: 'field' }, h('label', { for: 'ttrat' }, 'Candidates per invigilator'), h('input', { id: 'ttrat', type: 'number', min: 10, max: 200, value: F.ratio, oninput: (e) => { F.ratio = Number(e.target.value); } })),
          h('div', { class: 'field' }, h('label', { for: 'ttlate' }, 'Count a signature as late after (minutes)'), h('input', { id: 'ttlate', type: 'number', min: 5, max: 180, value: F.late, oninput: (e) => { F.late = Number(e.target.value); } }))),
        h('div', { class: 'field' }, h('label', { for: 'ttfile' }, 'Choose a CSV file (in Excel: Save As, CSV)'), file),
        h('div', { class: 'field' }, h('label', { for: 'ttcsv' }, 'Or paste the rows here'), csv),
        h('p', { class: 'm' }, 'Columns: Unit, Date, Start, End, Venue (Lecturer and Candidates are optional). Capital letters, spacing and date or time styles do not matter.'),
        h('div', { class: 'acts' }, h('button', { class: 'btn gold', onclick: preview }, 'Check timetable'), h('button', { class: 'btn', onclick: () => { F.csv = sampleCsv(F.school); csv.value = F.csv; toast('Sample loaded. It contains a typo on purpose.'); } }, 'Load a sample timetable')));
    }

    function fixLine(e) {
      const lines = F.csv.split(/\r?\n/), idx = []; lines.forEach((l, i) => { if (l.trim()) idx.push(i); });
      const t = idx[e.line - 1]; if (t == null || !e.found) return; lines[t] = lines[t].replace(e.found, e.suggest); F.csv = lines.join('\n'); preview();
    }
    function sel(opts, value, onchange, label) {
      return h('select', { 'aria-label': label, onchange: (e) => onchange(e.target.value) }, h('option', { value: '' }, value ? 'Remove' : 'Choose…'), opts.map(u => h('option', { value: u.email, selected: u.email === value }, u.name + ' · ' + (u.school || '').replace('School of ', ''))));
    }
    const assign = (key, slot, index, email) => run(async () => { imp = (await api('POST', `/timetable/${imp.id}/assign`, { key, slot, index, email })).import; paint(); });

    function previewCard() {
      const p = imp.plan, canApprove = cfg.role === 'exam_director', editable = ['draft'].includes(imp.status) || (imp.status === 'pending' && canApprove);
      const blocked = p.errors.length > 0 || p.sessions.some(s => s.problems.length);
      const chiefs = imp.pool.filter(u => u.role === 'supervisor'), all = imp.pool;
      const note = h('input', { type: 'text', maxlength: 200, placeholder: 'Reason for sending back', 'aria-label': 'Reason for sending back', style: 'max-width:260px' });
      const actions = h('div', { class: 'acts', style: 'margin-top:14px' },
        imp.status === 'draft' && !canApprove ? h('button', { class: 'btn gold', disabled: blocked, onclick: () => run(async () => { await api('POST', `/timetable/${imp.id}/submit`); toast('Sent to the exam director for approval.'); imp = null; await reload(); }) }, 'Submit for approval') : null,
        canApprove && ['draft', 'pending'].includes(imp.status) ? h('button', { class: 'btn gold', disabled: blocked, onclick: () => run(async () => { const r = await api('POST', `/timetable/${imp.id}/approve`); toast(r.created + ' exam sessions created. Chief supervisors will see them when they sign in.'); imp = null; await reload(); }) }, 'Approve and create sessions') : null,
        canApprove && ['draft', 'pending'].includes(imp.status) ? [note, h('button', { class: 'btn danger', onclick: () => run(async () => { await api('POST', `/timetable/${imp.id}/reject`, { note: note.value }); toast('Sent back.'); imp = null; await reload(); }) }, 'Send back')] : null,
        blocked ? h('span', { class: 'm' }, 'Fix the items marked in red to continue.') : null);
      return h('div', { class: 'card gold', id: 'ttprev' }, h('h2', {}, '2 · Check and assign'),
        h('div', { class: 'acts' }, chip('ok', p.rows + ' rows read'), chip('ok', p.sessions.length + ' sittings'), p.tidy.length ? chip('ok', p.tidy.length + ' entries tidied') : null, p.errors.length ? chip('er', p.errors.length + ' to fix') : chip('ok', 'No errors')),
        p.errors.length ? h('div', { class: 'err', role: 'alert', style: 'margin-top:12px' }, h('b', {}, 'Fix these first'), p.errors.map(e => h('div', { class: 'tt-e' }, h('span', {}, (e.line ? 'Line ' + e.line + ': ' : '') + e.msg), e.suggest && e.found ? h('button', { class: 'btn sm', onclick: () => fixLine(e) }, 'Use ' + e.suggest) : null))) : null,
        p.warns.length ? h('div', { class: 'inf', style: 'margin-top:12px' }, h('b', {}, 'For your attention'), p.warns.map(w => h('div', { class: 'm' }, w))) : null,
        p.tidy.length ? h('details', { style: 'margin-top:12px' }, h('summary', {}, p.tidy.length + ' entries were tidied automatically'), p.tidy.map(t => h('div', { class: 'm' }, t))) : null,
        h('div', { class: 'tt-list' }, p.sessions.map(s => h('div', { class: 'tt-s' + (s.problems.length ? ' bad' : '') },
          h('div', { class: 'tt-h' }, h('b', {}, s.units.join(' + ')), h('span', { class: 'm' }, fmt(s.opens_at), ' to ', fmt(s.closes_at, { day: undefined, month: undefined }), ' · ', s.venue, ' · ', s.candidates, ' candidates')),
          h('div', { class: 'tt-a' }, h('label', {}, 'Chief supervisor'), editable ? sel(chiefs, s.chief, (v) => assign(s.key, 'chief', 0, v), 'Chief supervisor') : h('span', {}, s.chief_name || 'None')),
          h('div', { class: 'tt-a' }, h('label', {}, 'Invigilators (' + s.invs.length + ' of ' + s.need + ')'),
            h('div', { class: 'tt-i' }, s.invs.map((e, i) => editable ? sel(all, e, (v) => assign(s.key, 'inv', i, v), 'Invigilator ' + (i + 1)) : h('span', { class: 'k' }, s.inv_names[i])),
              editable ? sel(all.filter(u => !s.invs.includes(u.email) && u.email !== s.chief), '', (v) => v && assign(s.key, 'inv', s.invs.length, v), 'Add invigilator') : null)),
          s.problems.length ? h('div', { class: 'tt-p', role: 'alert' }, s.problems.map(x => h('div', {}, x))) : (s.notes || []).length ? h('div', { class: 'm' }, s.notes[0]) : null))),
        p.workload.length ? h('details', { style: 'margin-top:12px' }, h('summary', {}, 'Workload across staff'), p.workload.map(w => h('div', { class: 'm' }, w.name + ': ' + w.sittings + ' sitting' + (w.sittings > 1 ? 's' : '')))) : null,
        h('p', { class: 'm' }, 'Rules applied: nobody is in two places at once (30 minutes allowed to settle in), a lecturer never invigilates their own paper, work is spread evenly, staff of the same school are preferred, and the number of invigilators follows the hall size.'), actions);
    }

    function historyCard() {
      if (!cfg.imports.length) return null;
      return h('div', { class: 'card' }, h('h2', {}, '3 · Uploads'), cfg.imports.map(i => h('div', { class: 'row' },
        h('div', {}, h('b', {}, i.period), h('div', { class: 'm' }, i.school, ' · ', i.sessions, ' sittings · by ', i.by, ' · ', fmt(i.at), i.decided_by ? ' · decided by ' + i.decided_by : '', i.note ? ' · "' + i.note + '"' : '')),
        h('div', { class: 'acts' }, chip(i.status === 'approved' ? 'ok' : i.status === 'rejected' ? 'er' : 'wn', ({ draft: 'Draft', pending: 'Waiting for exam director', approved: 'Approved', rejected: 'Sent back' })[i.status]),
          ['draft', 'pending'].includes(i.status) ? h('button', { class: 'btn sm', onclick: () => run(async () => { imp = (await api('GET', `/timetable/${i.id}`)).import; paint(); document.getElementById('ttprev').scrollIntoView({ block: 'start' }); }) }, cfg.role === 'exam_director' ? 'Review' : 'Open') : null))));
    }

    function manualCard() {
      if (cfg.role !== 'exam_director') return null;
      return h('details', { class: 'card' }, h('summary', { style: 'cursor:pointer;font-weight:700' }, 'One-off session (emergency use only, recorded in the audit log)'), h('div', { id: 'manual' }, manualForm()));
    }
    function manualForm() {
      const err = h('div', { class: 'err', hidden: true, role: 'alert' });
      const now = new Date(), end = new Date(now.getTime() + 3 * 3600e3), loc = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
      const f = { period: h('input', { type: 'text', id: 'pe', maxlength: 80, placeholder: 'e.g. Sem 2 2026, Special exam' }), venue: h('input', { type: 'text', id: 've', maxlength: 80, placeholder: 'e.g. Room 12' }), o: h('input', { type: 'datetime-local', id: 'op', value: loc(now) }), c: h('input', { type: 'datetime-local', id: 'cl', value: loc(end) }) };
      const chief = h('select', { id: 'mch', 'aria-label': 'Chief supervisor' }, cfg.chiefs.map(u => h('option', { value: u.email }, u.name)));
      const chosen = new Set(), codes = h('fieldset', { class: 'papers' }, h('legend', {}, 'Papers'));
      api('GET', '/portal/units').then(r => codes.append(...r.units.map(p => h('label', { class: 'opt' }, h('input', { type: 'checkbox', value: p.code, onchange: (e) => { e.target.checked ? chosen.add(p.code) : chosen.delete(p.code); } }), ' ', h('b', {}, p.code), ' ', p.name))));
      return h('div', {}, err, h('div', { class: 'cols' }, h('div', { class: 'field' }, h('label', { for: 'pe' }, 'Exam period'), f.period), h('div', { class: 'field' }, h('label', { for: 've' }, 'Venue'), f.venue)),
        h('div', { class: 'cols' }, h('div', { class: 'field' }, h('label', { for: 'op' }, 'Opens'), f.o), h('div', { class: 'field' }, h('label', { for: 'cl' }, 'Closes'), f.c)),
        h('div', { class: 'field' }, h('label', { for: 'mch' }, 'Chief supervisor'), chief), codes,
        h('button', { class: 'btn gold', onclick: async (e) => { err.hidden = true; e.target.disabled = true; try { await api('POST', '/sessions', { period: f.period.value, venue: f.venue.value, opens_at: new Date(f.o.value).toISOString(), closes_at: new Date(f.c.value).toISOString(), units: [...chosen], supervisor_email: chief.value, late_after: 30 }); toast('One-off session created.'); e.target.disabled = false; } catch (ex) { err.textContent = ex.message; err.hidden = false; e.target.disabled = false; } } }, 'Create one-off session'));
    }

    async function reload() { Object.assign(cfg, await api('GET', '/timetable')); paint(); }
    function paint() { host.replaceChildren(...[formCard(), imp ? previewCard() : null, historyCard(), manualCard()].filter(Boolean)); }
    paint();
  }

  // --------------------------------------------------------------- console
  async function loadSessions() { S.sessions = (await api('GET', '/sessions')).sessions; return S.sessions; }
  const cur = () => S.sessions.find(s => s.id === S.sid);

  async function viewConsole() {
    await loadSessions();
    const wrap = h('div', {});
    app.replaceChildren(shell('console', h('h1', {}, 'Your exam sessions'), h('p', { class: 'sub' }, 'Sessions come from the exam timetable approved by the exam director. Nothing to type: open the one you are supervising.'), wrap));
    if (!S.sessions.length) { wrap.append(h('div', { class: 'card' }, h('p', { class: 'm' }, 'No exam sessions are assigned to you yet. They appear here automatically once the exam director approves the timetable.'))); return; }
    const day = (iso) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Nairobi' }).format(new Date(iso)), today = day(new Date().toISOString()), nowI = new Date().toISOString();
    const grp = { Today: [], Upcoming: [], 'Earlier or closed': [] };
    for (const s of S.sessions) (s.status === 'closed' || s.closes_at < nowI ? grp['Earlier or closed'] : day(s.opens_at) <= today ? grp.Today : grp.Upcoming).push(s);
    grp.Today.sort((a, b) => a.opens_at.localeCompare(b.opens_at)); grp.Upcoming.sort((a, b) => a.opens_at.localeCompare(b.opens_at)); grp['Earlier or closed'].sort((a, b) => b.opens_at.localeCompare(a.opens_at));
    for (const [title, list] of Object.entries(grp)) if (list.length) { wrap.append(h('h2', { style: 'margin-top:8px' }, title)); list.forEach(s => wrap.append(sessionCard(s))); }
  }

  function sessionCard(s) {
    const body = h('div', {});
    const A = (ic, label, fn, cls) => { const b = h('button', { class: 'btn sm tr-b ' + (cls || ''), type: 'button', onclick: fn }, ico(ic), h('span', {}, label)); return b; };
    const go2 = (hash) => () => { S.sid = s.id; sessionStorage.setItem('sid', s.id); go(hash); };
    const groups = [
      ['Work', [A('roll', 'Roll call', go2('#/rollcall')), A('flag', s.level === 'invigilator' ? 'Flag for supervisor' : 'Incidents & ID checks', () => toggle('inc'))]],
      ['Records', [A('units', 'Unit sheets (PDF)', () => toggle('units')), s.level !== 'invigilator' ? A('pack', 'Session pack', () => DEMO.openPack(s.id)) : null]],
      ['Admin', [
        s.level === 'supervisor' && s.status === 'active' ? A('wifi', s.offline ? 'Offline mode: ON (turn off)' : 'Turn on offline mode', async () => {
          try { await api('POST', `/sessions/${s.id}/offline`, { on: !s.offline }); if (!s.offline) { S.pack = null; } toast(s.offline ? 'Offline mode switched off.' : 'Offline mode is on. Invigilators with access can keep signing if Wi-Fi drops.'); render(); } catch (ex) { toast(ex.message, true); }
        }) : null,
        s.level !== 'invigilator' ? A('users', s.level === 'supervisor' ? 'Invigilator access' : 'View access', () => toggle('access')) : null]],
    ];
    const closeB = s.level !== 'invigilator' && s.status === 'active' ? A('stop', 'Close session', async () => {
      if (!(await DEMO.confirm('Close this exam session now? Signing stops. Students can only correct a form if the exam director reopens it.'))) return;
      try { await api('POST', `/sessions/${s.id}/close`); toast('Session closed.'); render(); } catch (ex) { toast(ex.message, true); }
    }, 'danger') : null;
    let idx = 0;
    const inner = h('div', { class: 'tray-in' }, groups.map(([t, bs]) => { bs = bs.filter(Boolean); return bs.length ? h('div', { class: 'tr-g' }, h('div', { class: 'tr-t' }, t), h('div', { class: 'tr-r' }, bs.map(b => { b.style.setProperty('--i', idx++); return b; }))) : null; }),
      closeB ? h('div', { class: 'tr-g tr-danger' }, (closeB.style.setProperty('--i', idx++), closeB)) : null);
    const tray = h('div', { class: 'tray', id: 'tray' + s.id, role: 'group', 'aria-label': 'Session actions', inert: true }, inner);
    const powerBtn = h('button', { class: 'power', type: 'button', 'aria-expanded': 'false', 'aria-controls': 'tray' + s.id, onclick: () => setTray(!tray.classList.contains('open')) }, ico('power'), h('span', {}, 'Session actions'));
    function setTray(open) {
      tray.classList.toggle('open', open); powerBtn.classList.toggle('on', open); powerBtn.setAttribute('aria-expanded', String(open));
      open ? tray.removeAttribute('inert') : tray.setAttribute('inert', '');
      if (open) { const off = (e) => { if (!card.contains(e.target)) { setTray(false); document.removeEventListener('pointerdown', off, true); } }; document.addEventListener('pointerdown', off, true); }
    }
    const nowIso = new Date().toISOString(), status = s.status === 'closed' ? chip('wn', 'Closed') : s.open ? chip('ok', 'Open for signing') : nowIso < s.opens_at ? chip('wn', 'Opens ' + fmt(s.opens_at, { day: undefined, month: undefined }) + ' on ' + fmt(s.opens_at, { hour: undefined, minute: undefined })) : chip('wn', 'Ended');
    const card = h('div', { class: 'card' + (s.id === S.sid ? ' gold' : '') },
      h('div', { class: 'row', style: 'padding:0 0 12px;border:0' },
        h('div', {}, h('b', {}, s.period), h('div', { class: 'm' }, s.venue, ' · ', fmt(s.opens_at), ' to ', fmt(s.closes_at, { day: undefined, month: undefined }), ' · Supervisor: ', s.supervisor, h('div', {}, 'Papers: ', s.units.join(', ')))),
        h('div', { class: 'acts' }, status, h('span', { class: 'm' }, s.signatures + ' signatures · ' + s.students + ' students'))),
      h('div', { class: 'acts' },
        h('button', { class: 'btn gold sm', onclick: () => { S.sid = s.id; sessionStorage.setItem('sid', s.id); go('#/sheet'); } }, ico('open'), 'Open sign sheet'),
        powerBtn, s.offline && s.level !== 'supervisor' ? chip('wn', 'Offline mode on') : null),
      tray,
      body);
    card.addEventListener('keydown', (e) => { if (e.key === 'Escape' && tray.classList.contains('open')) { setTray(false); powerBtn.focus(); } });
    let shown = null;
    async function toggle(which) {
      if (shown === which) { body.replaceChildren(); shown = null; return; }
      shown = which; body.replaceChildren(skel());
      try { body.replaceChildren(which === 'units' ? await unitsPanel(s) : which === 'inc' ? await incidentsPanel(s, () => toggle2(which)) : await accessPanel(s, () => toggle2(which))); }
      catch (ex) { body.replaceChildren(h('div', { class: 'err' }, ex.message)); }
    }
    async function toggle2(which) { shown = null; await toggle(which); }
    return card;
  }

  async function unitsPanel(s) {
    const { units } = await api('GET', `/sessions/${s.id}/units`);
    if (!units.length) return h('p', { class: 'm', style: 'margin-top:12px' }, 'No units registered or signed yet.');
    return h('div', { style: 'margin-top:12px' }, units.map(u => h('div', { class: 'row' },
      h('div', {}, h('b', {}, u.code), ' ', u.name, h('div', { class: 'm' }, u.signed + ' of ' + u.registered + ' registered signed', u.absent ? ' · ' + u.absent + ' did not sign' : '', Number(u.email_problems) ? ' · ' + u.email_problems + ' email copies need resending' : '', u.incidents ? ' · ' + u.incidents + ' incident case' + (u.incidents > 1 ? 's' : '') : '')),
      h('button', { class: 'btn sm gold', onclick: () => DEMO.openSheet(s.id, u.code) }, 'Preview sealed sheet'))),
      s.status === 'active' ? h('p', { class: 'm' }, 'When you close the session, a sealed sheet for every unit is stored automatically in Records.') : h('p', { class: 'm' }, 'Session closed: sealed sheets are stored in Records.'));
  }

  async function incidentsPanel(s, refresh) {
    const d = await api('GET', `/sessions/${s.id}/incidents`);
    const sup = d.level === 'supervisor', err = h('div', { class: 'err', hidden: true, role: 'alert' });
    const adm = h('input', { type: 'text', id: 'ia' + s.id, placeholder: '22/01001', autocomplete: 'off' }), unit = h('input', { type: 'text', id: 'iu' + s.id, placeholder: 'BSD 4101', autocomplete: 'off' });
    const cat = h('select', { id: 'ic' + s.id, 'aria-label': 'Category' }, (d.categories || []).map(c => h('option', { value: c }, c)));
    const txt = h('textarea', { id: 'it' + s.id, maxlength: 500, placeholder: sup ? 'What was seen, by whom, and what was done.' : 'What did you see? The chief supervisor will decide what to record.' });
    let flagId = null;
    const send = h('button', { class: 'btn gold sm', onclick: async () => {
      err.hidden = true;
      try {
        if (sup) await api('POST', `/sessions/${s.id}/incidents`, { adm_no: adm.value, unit_code: unit.value, category: cat.value, text: txt.value, flag_id: flagId });
        else await api('POST', `/sessions/${s.id}/flags`, { adm_no: adm.value, unit_code: unit.value, text: txt.value });
        toast(sup ? 'Incident recorded. It will be marked on the sealed sheet.' : 'Sent to the chief supervisor.'); refresh();
      } catch (ex) { err.textContent = ex.message; err.hidden = false; }
    } }, sup ? 'Record incident' : 'Flag for supervisor');
    const form = h('div', { class: 'inf', style: 'margin-top:12px' }, h('b', {}, sup ? 'Record a new incident' : 'Flag something for the chief supervisor'), err,
      h('div', { class: 'cols' }, h('div', { class: 'field' }, h('label', { for: 'ia' + s.id }, 'Admission number'), adm), h('div', { class: 'field' }, h('label', { for: 'iu' + s.id }, 'Unit code'), unit)),
      sup ? h('div', { class: 'field' }, h('label', { for: 'ic' + s.id }, 'Category'), cat) : null,
      h('div', { class: 'field' }, h('label', { for: 'it' + s.id }, sup ? 'Notes' : 'Your note'), txt), send,
      h('div', { class: 'm', style: 'margin-top:6px' }, sup ? 'Notes cannot be deleted. Later updates are added below them and the sheet shows the full history.' : 'Invigilators cannot write incident notes on the sheet. Only the chief supervisor can.'));
    const flags = d.flags.filter(f => f.status === 'open');
    const flagRows = flags.length ? flags.map(f => h('div', { class: 'row' }, h('div', {}, h('b', {}, f.full_name), ' · ', f.adm_no, ' · ', f.unit || 'unit not stated', h('div', {}, '“', f.text, '”'), h('div', { class: 'm' }, 'Flagged by ', f.by, ' at ', fmt(f.at))),
      sup ? h('button', { class: 'btn sm', onclick: () => { adm.value = f.adm_no; unit.value = f.unit; txt.value = f.text; flagId = f.id; form.scrollIntoView && form.scrollIntoView({ behavior: 'smooth', block: 'center' }); toast('Details copied into the form. Choose a category and record.'); } }, 'Record as incident') : chip('wn', 'Waiting'))) : [h('p', { class: 'm' }, 'No open flags.')];
    const cases = d.incidents.map(i => {
      const nt = h('textarea', { 'aria-label': 'Update note', maxlength: 500, placeholder: 'Update: what happened next?' });
      const st = h('select', { 'aria-label': 'Outcome' }, Object.entries(d.statuses).map(([k, v]) => h('option', { value: k, selected: k === i.status }, v)));
      return h('div', { class: 'card inc' }, h('div', { class: 'row', style: 'padding:0 0 8px;border:0' }, h('div', {}, h('b', {}, i.case_no), ' · ', i.full_name, ' · ', i.adm_no, h('div', { class: 'm' }, i.unit, ' · ', i.category)), chip(i.status === 'open' || i.status === 'resit_required' || i.status === 'referred' ? 'wn' : 'ok', d.statuses[i.status])),
        ...i.entries.map(e => h('div', { class: 'tl' }, h('span', { class: 'm' }, fmt(e.ts), ' · ', e.by), h('div', {}, e.text))),
        i.resit_sid ? h('div', { class: 'inf' }, 'A re-sit sheet has been issued and is linked to this case.') : null,
        sup ? h('div', { style: 'margin-top:8px' }, nt, h('div', { class: 'acts', style: 'margin-top:6px' }, st, h('button', { class: 'btn sm', onclick: async () => {
          try { await api('POST', `/incidents/${i.id}/update`, { text: nt.value, status: st.value }); toast('Case updated. The sheet will show the outcome.'); refresh(); } catch (ex) { toast(ex.message, true); }
        } }, 'Add update'))) : null);
    });
    const reviews = (d.reviews || []).filter(r => r.review === 'pending');
    return h('div', { style: 'margin-top:12px' },
      sup && d.reviews ? h('div', {}, h('h3', {}, 'Identity checks to review'), reviews.length ? reviews.map(r => h('div', { class: 'row' }, h('div', {}, h('b', {}, r.full_name), ' · ', r.adm_no, ' · ', r.unit_code, h('div', { class: 'm' }, 'Photo match only. ', r.note || '')),
        h('button', { class: 'btn sm', onclick: async () => { try { await api('POST', `/signatures/${r.id}/review`); toast('Marked as reviewed.'); refresh(); } catch (ex) { toast(ex.message, true); } } }, 'Mark reviewed'))) : h('p', { class: 'm' }, 'Nothing waiting. Students without an exam card or ID show here.')) : null,
      h('h3', {}, 'Flags from invigilators'), ...flagRows, form,
      sup || d.level === 'director' ? h('div', {}, h('h3', { style: 'margin-top:16px' }, 'Incident cases'), ...(cases.length ? cases : [h('p', { class: 'm' }, 'No incidents recorded for this session.')])) : null);
  }

  async function accessPanel(s, refresh) {
    const { access } = await api('GET', `/sessions/${s.id}/access`);
    const err = h('div', { class: 'err', hidden: true, role: 'alert' });
    const em = h('input', { type: 'email', id: 'gx' + s.id, placeholder: 'name@kcau.ac.ke' });
    const rows = access.length ? access.map(a => h('div', { class: 'row' },
      h('div', {}, h('b', {}, a.name), h('div', { class: 'm' }, a.email, ' · added ', fmt(a.granted_at))),
      a.revoked_at ? chip('wn', 'Revoked') : h('div', { class: 'acts' }, chip('ok', 'Active'), s.level === 'supervisor' ? h('button', { class: 'btn sm', onclick: async () => {
        try { await api('DELETE', `/sessions/${s.id}/access/${encodeURIComponent(a.email)}`); toast('Access revoked.'); refresh(); } catch (ex) { toast(ex.message, true); }
      } }, 'Revoke') : null))) : [h('p', { class: 'm' }, 'No other invigilators yet.')];
    return h('div', { style: 'margin-top:12px' },
      h('p', { class: 'm' }, 'Invigilators you add can load this sheet with their own portal account until the session closes.'), rows,
      s.level === 'supervisor' && s.status === 'active' ? h('div', {}, err, h('div', { class: 'field', style: 'margin-top:12px' }, h('label', { for: 'gx' + s.id }, 'Add invigilator (staff email)'), em),
        h('button', { class: 'btn gold sm', onclick: async () => {
          err.hidden = true;
          try { await api('POST', `/sessions/${s.id}/access`, { email: em.value }); toast('Access granted.'); refresh(); } catch (ex) { err.textContent = ex.message; err.hidden = false; }
        } }, 'Grant access')) : null);
  }

  // ------------------------------------------------------------ sign sheet
  async function viewSheet() {
    await loadSessions();
    const s = cur();
    if (!s) { return app.replaceChildren(shell('sheet', h('h1', {}, 'Sign sheet'), h('div', { class: 'card gold' }, h('p', {}, 'Choose an exam session first.'), h('button', { class: 'btn gold', onclick: () => go('#/console') }, 'Go to exam sessions')))); }
    const results = h('div', {});
    const q = h('input', { type: 'search', id: 'q', placeholder: 'Your admission number, e.g. 22/01001', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', inputmode: 'text', 'aria-describedby': 'qh' });
    const picker = S.sessions.length > 1 ? h('select', { 'aria-label': 'Exam session', onchange: (e) => { S.sid = Number(e.target.value); sessionStorage.setItem('sid', S.sid); render(); } },
      S.sessions.map(x => h('option', { value: x.id, selected: x.id === S.sid }, x.period + ' · ' + x.venue))) : null;
    const closed = !s.open;
    if (s.offline && !S.pack && !netBlocked) { try { S.pack = await api('GET', `/sessions/${s.id}/offline-pack`); } catch { /* retry on next visit */ } }
    if (!s.offline && S.pack) S.pack = null;
    const lookup = async () => {
      const v = q.value.trim(); if (v.replace(/[^a-z0-9]/gi, '').length < 6) { results.replaceChildren(h('p', { class: 'm' }, 'Type your full admission number, then tap Find me.')); return; }
      try {
        const { students } = await api('GET', `/sessions/${s.id}/students?q=${encodeURIComponent(v)}`);
        results.replaceChildren(...(students.length ? students.map(st => h('div', { class: 'card gold who-card' },
          h('img', { src: st.photo, alt: 'Portal photo of ' + st.full_name, class: 'mug' }),
          h('div', { style: 'flex:1;min-width:0' }, h('div', { class: 'm' }, 'Is this you?'), h('h2', { style: 'margin:2px 0' }, st.full_name), h('div', { class: 'm' }, st.adm_no, ' · ', st.programme),
            h('div', { class: 'acts', style: 'margin-top:12px' }, h('button', { class: 'btn gold', onclick: () => go('#/student/' + encodeURIComponent(st.adm_no)) }, 'Yes, this is me'), h('button', { class: 'btn', onclick: () => { q.value = ''; results.replaceChildren(); q.focus(); } }, 'No, search again')))))
          : [h('p', { class: 'm' }, 'No student has that admission number. Check it and try again, or ask the invigilator.')]));
      } catch (ex) { results.replaceChildren(ex.data && ex.data.locked ? h('div', { class: 'card red' }, h('b', {}, 'Search locked'), h('p', { class: 'm' }, ex.message), h('p', { class: 'm' }, 'Invigilator: hold the KCA logo for 3 seconds to unlock it.')) : h('div', { class: 'err' }, ex.message)); }
    };
    q.addEventListener('input', () => { if (!q.value.trim()) results.replaceChildren(); });
    q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { clearTimeout(searchTimer); lookup(); } });
    app.replaceChildren(shell('sheet',
      steps(1),
      h('h1', {}, 'Exam sign sheet'),
      h('p', { class: 'sub' }, s.period, ' · ', s.venue, ' · ', fmt(s.opens_at), ' to ', fmt(s.closes_at, { day: undefined, month: undefined })),
      picker && h('div', { class: 'field' }, picker),
      s.offline ? h('div', { class: 'card gold' }, h('b', {}, 'Offline mode is on. '), 'If Wi-Fi drops, signatures are kept on this device and sent automatically when it returns.') : null,
      S.queue.length ? h('div', { class: 'card red' }, h('b', {}, S.queue.length + ' signature' + (S.queue.length > 1 ? 's' : '') + ' waiting to sync. '), 'Keep this page open on the exam device. ', h('button', { class: 'btn sm', onclick: syncQueue }, 'Sync now')) : null,
      S.rejected.length ? h('div', { class: 'card red' }, h('b', {}, 'Not accepted on sync (kept safely on this device)'), ...S.rejected.map(r => h('div', { class: 'row' }, h('div', {}, h('b', {}, r.ref), ' · ', (r.q && r.q.body ? r.q.body.adm_no + ' ' + r.q.body.unit_code : ''), h('div', { class: 'm' }, r.why)),
        h('div', { class: 'acts' }, h('button', { class: 'btn sm', onclick: () => { S.rejected = S.rejected.filter(x => x !== r); S.queue.push(r.q); store.save(); syncQueue(); render(); } }, 'Retry'),
          S.user.role !== 'invigilator' ? h('button', { class: 'btn sm danger', onclick: async () => { if (!(await DEMO.confirm('Discard this saved signature? The student will have to sign again.', 'Yes, discard it', 'Keep it'))) return; S.rejected = S.rejected.filter(x => x !== r); store.save(); render(); } }, 'Discard') : null)))) : null,
      closed ? h('div', { class: 'card red' }, h('b', {}, s.status === 'closed' ? 'This session is closed.' : 'This session is not open for new signatures right now.'), h('div', { class: 'm' }, 'Students can still correct a form only if the exam director has reopened it.')) : null,
      h('div', { class: 'grid' },
        h('div', {}, h('div', { class: 'card' }, h('label', { for: 'q' }, 'Find your name'), q, h('button', { class: 'btn gold', style: 'margin-top:10px;width:100%', onclick: lookup }, 'Find me'), h('div', { id: 'qh', class: 'm', style: 'margin:6px 0 12px' }, 'Type your admission number. Your name and photo from the portal will appear so you can confirm it is you.'), results)),
        h('div', {}, h('div', { class: 'card' }, h('h2', {}, 'Session progress'), h('div', { class: 'big' }, s.signatures, h('span', { class: 'of' }, ' of ' + (s.expected || s.signatures))), h('div', { class: 'pbar', role: 'progressbar', 'aria-label': 'Signatures collected',  'aria-valuemin': 0, 'aria-valuemax': s.expected || 0, 'aria-valuenow': s.signatures }, h('i', { style: 'transform:scaleX(' + Math.min(1, s.signatures / (s.expected || 1)).toFixed(3) + ')' })), h('div', { class: 'm' }, 'signatures from ' + s.students + (s.students === 1 ? ' student · ' : ' students · ') + Math.max(0, (s.expected || 0) - s.signatures) + ' still to sign')),
          h('div', { class: 'card gold' }, h('div', { class: 'm' }, h('b', {}, 'After signing: '), 'a sealed PDF copy is emailed to the student\'s KCA school email, and the sealed original is kept in the school records. They can correct mistakes until the session closes; after that only the exam director can reopen the form.'))))));
    q.focus();
  }

  // --------------------------------------------------------------- student
  async function viewStudent(adm) {
    const s = cur();
    if (!s) return go('#/console');
    app.replaceChildren(shell('sheet', skel()));
    const r = await api('GET', `/sessions/${s.id}/students/${encodeURIComponent(adm)}/units`);
    const back = h('button', { class: 'back', onclick: () => go('#/sheet') }, '‹ Back to search');
    const head = h('div', { class: 'card who-card' }, h('img', { src: r.student.photo, alt: 'Portal photo', class: 'mug' }), h('div', {}, h('h1', {}, r.student.full_name), h('div', { class: 'm' }, r.student.adm_no, ' · ', r.student.programme)));
    if (!r.cleared) {
      return app.replaceChildren(shell('sheet', back, head, h('div', { class: 'card red' }, h('b', { style: 'color:var(--er)' }, 'Not cleared to sit exams'), h('p', {}, r.reason), h('p', { class: 'm' }, 'Do not take a signature. Send the student to the exam director.'))));
    }
    const chosen = S.ident[r.student.adm_no] || {};
    const note = h('input', { type: 'text', maxlength: 120, placeholder: 'Why? e.g. ID left at hostel', 'aria-label': 'Reason for photo-only check', value: chosen.note || '', hidden: chosen.v !== 'photo_only', oninput: (e) => { S.ident[r.student.adm_no].note = e.target.value; } });
    const idCard = h('div', { class: 'card gold', id: 'idcheck' }, h('h2', {}, 'Invigilator: check identity first'),
      h('p', { class: 'm' }, 'Compare the student with the photo above. Check the exam card and student ID before handing the device over to sign.'),
      Object.entries(IDENT).map(([k, label]) => h('label', { class: 'opt' }, h('input', { type: 'radio', name: 'ident', value: k, checked: chosen.v === k, onchange: () => { S.ident[r.student.adm_no] = { v: k, note: S.ident[r.student.adm_no] ? S.ident[r.student.adm_no].note : '' }; note.hidden = k !== 'photo_only'; document.querySelectorAll('[data-needs-id]').forEach(b => { b.disabled = false; }); } }), ' ', label)),
      note, h('p', { class: 'm' }, 'A cleared student without an ID can still sit. Photo-only checks are flagged for the chief supervisor to review.'));
    const mine = r.units.some(u => u.in_session);
    const rows = r.units.length ? r.units.map(u => {
      const reopen = h('div', {});
      if (!u.in_session) return h('div', { class: 'row off', 'aria-disabled': 'true' },
        h('div', {}, h('b', {}, u.code), ' ', u.name, h('div', { class: 'm' }, u.signed ? 'Signed in its own exam session' : 'Not for this session')),
        h('div', { class: 'acts' }, u.signed ? chip('ok', 'Signed') : chip('wn', 'Not signed'), !u.signed ? h('button', { class: 'btn sm', disabled: true, 'aria-disabled': 'true', title: 'Not for this session' }, 'Sign') : null));
      return h('div', {}, h('div', { class: 'row' },
        h('div', {}, h('b', {}, u.code), ' ', u.name, u.queued ? h('div', { class: 'm' }, 'Saved on this device, waiting for Wi-Fi to sync') : u.signed ? h('div', { class: 'm' }, 'Signed ', fmt(u.signed_at, { day: undefined, month: undefined }), u.version > 1 ? ' \u00b7 corrected (v' + u.version + ')' : '', u.email_status && !['sent', 'outbox'].includes(u.email_status) ? ' \u00b7 email copy not delivered' : '') : h('div', { class: 'm' }, 'This session')),
        h('div', { class: 'acts' }, u.signed ? chip('ok', 'Signed') : chip('wn', 'Not signed'),
          !u.signed && s.open ? h('button', { class: 'btn gold sm', 'data-needs-id': '1', disabled: !chosen.v, title: 'Record the identity check first', onclick: () => go(`#/form/${encodeURIComponent(r.student.adm_no)}/${encodeURIComponent(u.code)}`) }, 'Sign') : null,
          !u.signed && !s.open ? h('button', { class: 'btn sm', disabled: true, title: 'This session is not open' }, 'Sign') : null,
          u.signed && u.can_edit ? h('button', { class: 'btn sm', onclick: () => go(`#/form/${encodeURIComponent(r.student.adm_no)}/${encodeURIComponent(u.code)}`) }, 'Correct') : null,
          u.signed && !u.can_edit && s.level !== 'director' ? h('button', { class: 'btn sm', onclick: () => reopenBox(reopen, u.signature_id) }, 'Ask exam director to reopen') : null,
          u.signed && u.email_status === 'failed' ? h('button', { class: 'btn sm', onclick: async () => { try { const x = await api('POST', `/signatures/${u.signature_id}/resend`); toast(x.email.status === 'failed' ? 'Still could not send. Check mail settings.' : 'Email copy sent.', x.email.status === 'failed'); render(); } catch (ex) { toast(ex.message, true); } } }, 'Resend email') : null)), reopen);
    }) : [h('p', { class: 'm', style: 'padding:12px' }, 'This student has no cleared units this semester.')];
    if (r.units.length && !mine) rows.unshift(h('div', { class: 'err', role: 'alert' }, 'None of this student\'s cleared papers is part of this exam session (' + (r.session_units || []).join(', ') + '). Check the student is in the right hall.'));
    app.replaceChildren(shell('sheet', steps(2), back, head, idCard, h('div', { class: 'card' }, h('h2', {}, 'Your units this semester'), h('p', { class: 'm' }, 'Only the unit for this exam session can be signed here.'), ...rows)));
  }

  function reopenBox(host, sigId) {
    if (host.childNodes.length) { host.replaceChildren(); return; }
    const t = h('textarea', { placeholder: 'What needs correcting? e.g. booklet number mistyped', maxlength: 300, 'aria-label': 'Reason' });
    host.replaceChildren(h('div', { class: 'inf' }, t, h('div', { style: 'margin-top:8px' }, h('button', { class: 'btn gold sm', onclick: async () => {
      try { await api('POST', `/signatures/${sigId}/reopen-request`, { reason: t.value }); toast('Request sent to the exam director.'); host.replaceChildren(); } catch (ex) { toast(ex.message, true); }
    } }, 'Send request'))));
  }

  // ------------------------------------------------------------------ form
  async function viewForm(adm, unitCode) {
    const s = cur();
    if (!s) return go('#/console');
    app.replaceChildren(shell('sheet', skel()));
    const [ur, ex] = await Promise.all([api('GET', `/sessions/${s.id}/students/${encodeURIComponent(adm)}/units`),
      api('GET', `/sessions/${s.id}/signatures/${encodeURIComponent(adm)}/${encodeURIComponent(unitCode)}`).catch(() => null)]);
    const unit = ur.units.find(u => u.code === unitCode && u.in_session);
    if (!ur.cleared || !unit) return go('#/student/' + encodeURIComponent(adm));
    const old = ex && ex.signature;
    const resetIdle = () => { clearTimeout(idleTimer); idleTimer = setTimeout(() => { toast('Form closed after 3 minutes of no activity. Nothing was saved.', true); go('#/sheet'); }, 180000); };
    resetIdle();

    const err = h('div', { class: 'err', hidden: true, role: 'alert' });
    const name = h('input', { type: 'text', id: 'fn', readonly: true, value: ur.student.full_name });
    const admIn = h('input', { type: 'text', id: 'fa', readonly: true, value: ur.student.adm_no });
    const pf = ur.prefill || {};
    const phone = h('input', { type: 'tel', id: 'fp', autocomplete: 'off', inputmode: 'tel', placeholder: '0712 345 678', readonly: !!pf.phone_from_portal, value: pf.phone || (old ? old.phone : '') });
    const book = h('input', { type: 'text', id: 'fb', autocomplete: 'off', autocapitalize: 'characters', placeholder: 'As printed on the booklet', maxlength: 20, value: old ? old.booklet_no : '' });
    const email = h('input', { type: 'email', id: 'fe', readonly: true, value: pf.email || '' });
    const sure = h('input', { type: 'checkbox', id: 'fc', style: 'width:22px;height:22px;flex:none' });
    const save = h('button', { class: 'btn gold', style: 'min-height:50px', onclick: submit }, 'Save & send email copy');

    // signature pad
    const canvas = h('canvas', { width: 1000, height: 300, 'aria-label': 'Signature box' });
    const hint = h('div', { class: 'hint' }, 'Sign here with your finger or stylus');
    const ctx = canvas.getContext('2d');
    ctx.lineWidth = 5; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0B2A5B';
    let drawing = false, inked = false, last = null;
    const pos = (e) => { const r = canvas.getBoundingClientRect(); return { x: (e.clientX - r.left) * canvas.width / r.width, y: (e.clientY - r.top) * canvas.height / r.height }; };
    canvas.addEventListener('pointerdown', (e) => { e.preventDefault(); canvas.setPointerCapture(e.pointerId); drawing = true; last = pos(e); ctx.beginPath(); ctx.arc(last.x, last.y, 2.5, 0, 7); ctx.fillStyle = '#0B2A5B'; ctx.fill(); inked = true; hint.hidden = true; resetIdle(); });
    canvas.addEventListener('pointermove', (e) => { if (!drawing) return; e.preventDefault(); const p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; });
    const stop = () => { drawing = false; };
    canvas.addEventListener('pointerup', stop); canvas.addEventListener('pointercancel', stop);
    if (old) { const img = new Image(); img.onload = () => { ctx.drawImage(img, 0, 0, canvas.width, canvas.height); inked = true; hint.hidden = true; }; img.src = old.signature; }
    const clear = () => { ctx.clearRect(0, 0, canvas.width, canvas.height); inked = false; hint.hidden = false; };

    for (const el of [phone, book, sure]) el.addEventListener('input', resetIdle);
    book.addEventListener('change', () => { if (!inked && canvas.scrollIntoView) canvas.scrollIntoView({ block: 'center', behavior: 'smooth' }); });

    async function submit() {
      err.hidden = true;
      if (!inked) return fail('Please sign in the signature box.');
      if (!sure.checked) return fail('Tick the box to confirm you are present for this exam.');
      save.disabled = true; save.textContent = 'Saving...';
      try {
        const r = await api('POST', `/sessions/${s.id}/signatures`, { adm_no: ur.student.adm_no, unit_code: unitCode, phone: phone.value, booklet_no: book.value, signature: canvas.toDataURL('image/png'), ident: (S.ident[adm] || {}).v, ident_note: (S.ident[adm] || {}).note || '' });
        clearTimeout(idleTimer);
        const st = r.email.status;
        delete S.ident[adm]; go('#/sheet');
        if (r.queued) showDone('Saved on this device', 'No Wi-Fi right now. Reference ' + r.ref + '. It sends automatically and the sealed copy goes to ' + r.email.to + '.');
        else if (st === 'failed') { showDone('Signed', 'Your signature is recorded and sealed. The email copy could not be sent yet; the invigilator will resend it.'); toast('Email copy could not be sent. Use "Resend email" on the unit.', true); }
        else showDone('Signed', 'A sealed PDF copy is on its way to ' + r.email.to + '. The school also keeps the sealed original.', r.email.code);
      } catch (e) {
        fail(e.message + (e.data && e.data.needs_reopen ? ' Go back and tap "Ask exam director to reopen".' : ''));
        save.disabled = false; save.textContent = 'Save & send email copy';
      }
    }
    function fail(msg) { err.textContent = msg; err.hidden = false; err.scrollIntoView({ block: 'center', behavior: 'smooth' }); }

    app.replaceChildren(shell('sheet',
      steps(3),
      h('button', { class: 'back', onclick: () => { clearTimeout(idleTimer); go('#/student/' + encodeURIComponent(adm)); } }, '‹ Back to units'),
      h('h1', {}, (old ? 'Correct signature · ' : 'Sign for ') + unit.code), h('p', { class: 'sub' }, unit.name, ' · ', s.period),
      h('div', { class: 'grid' },
        h('div', { class: 'card' }, err,
          h('details', { class: 'portal-details', open: window.innerWidth > 700 }, h('summary', {}, 'Your details from the portal (locked) · ', ur.student.full_name, ' · ', ur.student.adm_no),
            h('div', { class: 'cols' }, h('div', { class: 'field' }, h('label', { for: 'fn' }, 'Full name (from portal)'), name), h('div', { class: 'field' }, h('label', { for: 'fa' }, 'Admission number (from portal)'), admIn)),
            h('div', { class: 'cols' }, h('div', { class: 'field' }, h('label', { for: 'fe' }, 'School email (from portal)'), email), pf.phone_from_portal ? h('div', { class: 'field' }, h('label', { for: 'fp' }, 'Phone number (from portal)'), phone) : null)),
          pf.phone_from_portal ? null : h('div', { class: 'field' }, h('label', { for: 'fp' }, 'Phone number (not on portal, type it)'), phone),
          h('div', { class: 'field' }, h('label', { for: 'fb' }, 'Booklet serial number'), book, h('div', { class: 'm', style: 'margin-top:4px' }, 'Copy it exactly as printed on your answer booklet.')),
          h('div', { class: 'field' }, h('label', {}, 'Signature'), h('div', { class: 'sigwrap' }, canvas, hint), h('button', { class: 'btn sm', style: 'margin-top:8px', type: 'button', onclick: clear }, 'Clear signature')),
          h('div', { class: 'field', style: 'display:flex;gap:10px;align-items:flex-start' }, sure, h('label', { for: 'fc', style: 'font-weight:400;margin:0' }, 'I confirm I am present for this exam and the details above are mine.')),
          h('div', { class: 'acts' }, save, h('button', { class: 'btn', style: 'min-height:50px', onclick: () => { clearTimeout(idleTimer); go('#/student/' + encodeURIComponent(adm)); } }, 'Cancel'))),
        h('div', {}, h('div', { class: 'card' }, h('h2', {}, 'Before you save'), h('p', { class: 'm' }, 'Your name, admission number, phone and school email come straight from the university portal, so they cannot be mistyped. You only enter the booklet serial number and sign. Your copy goes to your school email.')),
          h('div', { class: 'card gold' }, h('p', { class: 'm' }, 'After you save, the screen returns to the sign sheet and a sealed PDF copy is emailed to you. Keep it.'))))));
    (phone.readOnly ? book : phone).focus();
  }

  // -------------------------------------------------------------- director
  async function securityCard() {
    const d = await api('GET', '/director/security');
    const file = h('input', { type: 'file', accept: 'image/png,image/jpeg', 'aria-label': 'Signature image', onchange: (e) => {
      const f0 = e.target.files[0]; if (!f0) return; if (f0.size > 300000) return toast('Image is too large (max 300 KB).', true);
      const rd = new FileReader(); rd.onload = async () => { try { await api('POST', '/director/signature', { signature: rd.result }); toast('Signature updated. It is placed automatically on every document issued from now on.'); render(); } catch (ex) { toast(ex.message, true); } }; rd.readAsDataURL(f0);
    } });
    return h('div', { class: 'card' }, h('h2', {}, 'Signature and school seal'),
      h('div', { class: 'cols' },
        h('div', {}, h('b', {}, 'Your signature on documents'), h('div', { class: 'sigprev' }, d.signature ? h('img', { src: d.signature, alt: 'Current director signature' }) : 'None uploaded'), h('div', { class: 'm' }, d.name), h('div', { class: 'field', style: 'margin-top:8px' }, h('label', { for: 'ds' }, 'Upload a new signature image'), file), h('p', { class: 'm' }, 'This image is placed automatically on every sheet and receipt. It is only for appearance; the hidden seal below is what makes a document impossible to forge.')),
        h('div', {}, h('b', {}, 'Hidden seal key'), h('p', { class: 'm' }, 'A private key that never leaves the server and cannot be exported. Only the exam director and the ICT security officer are custodians. Anybody can check a document against the public seal; nobody can make one.'),
          h('div', { class: 'fp' }, h('span', { class: 'm' }, 'Key fingerprint'), h('div', { class: 'mono' }, d.fingerprint.slice(0, 32), h('br'), d.fingerprint.slice(32))),
          h('div', { class: 'm', style: 'margin-top:8px' }, 'Custodians: ', d.custodians.join(', ')), h('div', { class: 'm' }, 'Times the key has been used: ', h('b', {}, String(d.uses_total))),
          d.uses.length ? h('div', { class: 'keyuse' }, d.uses.map(u => h('div', { class: 'm' }, fmt(u.ts), ' · ', u.purpose, ' · ', u.doc))) : null)),
      h('p', { class: 'm' }, h('b', {}, 'Retention: '), d.retention));
  }

  async function timelineCard() {
    const { events, chain } = await api('GET', '/audit/events');
    return h('div', { class: 'card' }, h('div', { class: 'row', style: 'padding:0 0 10px;border:0' }, h('h2', { style: 'margin:0' }, 'Security timeline'),
      chain.ok ? chip('ok', 'Chain intact · ' + chain.entries + ' entries') : chip('er', 'Chain broken at entry ' + chain.broken_at)),
      h('ol', { class: 'tline' }, events.map(e => h('li', { class: e.kind }, h('span', { class: 'dot' }), h('div', {}, h('b', {}, e.label), e.detail ? h('span', { class: 'm' }, ' · ' + e.detail) : null, h('div', { class: 'm' }, e.actor, ' · ', fmt(e.ts)))))));
  }

  async function viewDirector() {
    if (S.user.role !== 'exam_director') return go('#/console');
    const [{ requests }] = await Promise.all([api('GET', '/reopen-requests')]);
    const auditOut = h('div', {});
    const rows = requests.length ? requests.map(r => {
      const mins = h('select', { 'aria-label': 'Minutes open' }, [15, 30, 60, 120].map(m => h('option', { value: m, selected: m === 30 }, m + ' min')));
      return h('div', { class: 'row' },
        h('div', {}, h('b', {}, r.full_name), ' · ', r.adm_no, h('div', { class: 'm' }, r.unit_code, ' ', r.unit_name, ' · ', r.period, ' · ', r.venue), h('div', {}, '“', r.reason, '”'), h('div', { class: 'm' }, 'Asked by ', r.requested_by, ' at ', fmt(r.requested_at))),
        r.status === 'pending' ? h('div', { class: 'acts' }, mins,
          h('button', { class: 'btn gold sm', onclick: async () => { try { await api('POST', `/reopen-requests/${r.id}/approve`, { minutes: Number(mins.value) }); toast('Form reopened.'); render(); } catch (ex) { toast(ex.message, true); } } }, 'Reopen form'),
          h('button', { class: 'btn sm danger', onclick: async () => { try { await api('POST', `/reopen-requests/${r.id}/reject`, {}); render(); } catch (ex) { toast(ex.message, true); } } }, 'Decline'))
          : chip(r.status === 'approved' ? 'ok' : 'wn', r.status === 'approved' ? 'Reopened' : 'Declined'));
    }) : [h('p', { class: 'm', style: 'padding:12px' }, 'No requests.')];
    const sec = null;
    app.replaceChildren(shell('director', h('h1', {}, 'Exam director'), h('p', { class: 'sub' }, 'Reopen a student\'s form after the exam period when they need to correct their details.'),
      h('div', { class: 'card' }, h('h2', {}, 'Reopen requests'), ...rows),
      await securityCard(),
      await timelineCard(),
      h('div', { class: 'card' }, h('h2', {}, 'Audit trail integrity'), h('p', { class: 'm' }, 'Every sign-in, signature, correction, access change and download is chained. Run this check to confirm nothing was edited or removed.'),
        h('button', { class: 'btn sm', onclick: async () => {
          try { const r = await api('GET', '/audit/verify'); auditOut.replaceChildren(r.ok ? h('div', { class: 'inf' }, '✓ Intact. ' + r.entries + ' entries verified.') : h('div', { class: 'err' }, 'TAMPERING DETECTED at entry ' + r.broken_at + '. Escalate to ICT security.')); }
          catch (ex) { toast(ex.message, true); }
        } }, 'Verify audit trail'), auditOut)));
  }

  // ------------------------------------------------------------- roll call
  async function viewRollcall() {
    await loadSessions(); const s = cur(); if (!s) return go('#/console');
    const host = h('div', {}); let tab = 'pending';
    const seg = h('div', { class: 'seg', role: 'tablist' });
    const draw = async () => {
      const d = await api('GET', `/sessions/${s.id}/rollcall`);
      const total = d.units.reduce((n, u) => n + u.pending.length, 0);
      seg.replaceChildren(...[['pending', 'Not yet signed (' + total + ')'], ['present', 'Signed in (' + d.units.reduce((n, u) => n + u.present.length, 0) + ')']].map(([k, t]) => h('button', { role: 'tab', 'aria-selected': String(tab === k), class: 'segb' + (tab === k ? ' on' : ''), onclick: () => { tab = k; draw(); } }, t)));
      host.replaceChildren(...d.units.map(u => {
        const rows = tab === 'pending' ? u.pending.map(p => h('div', { class: 'row' }, h('div', { class: 'who-row' }, h('img', { src: p.photo, alt: '', class: 'thumb' }), h('div', {}, h('b', {}, p.full_name), h('div', { class: 'm' }, p.adm_no))),
          p.state === 'absent' ? chip('wn', 'Not signed yet') : chip('er', 'Cannot sit: ' + p.reason)))
          : u.present.map(g => { const box = h('div', {});
            return h('div', {}, h('div', { class: 'row' }, h('div', {}, h('b', {}, g.full_name), h('div', { class: 'm' }, g.adm_no, ' · signed ', fmt(g.signed_at, { day: undefined, month: undefined }), g.exit_at ? ' · left ' + fmt(g.exit_at, { day: undefined, month: undefined }) + (g.exit_note ? ' (' + g.exit_note + ')' : '') : '')),
              g.exit_at ? chip('ok', 'Exit recorded') : h('button', { class: 'btn sm', onclick: () => {
                if (box.childNodes.length) { box.replaceChildren(); return; }
                const note = h('input', { type: 'text', maxlength: 80, placeholder: 'Optional note, e.g. submitted, unwell', 'aria-label': 'Exit note' });
                box.replaceChildren(h('div', { class: 'inf' }, note, h('div', { style: 'margin-top:8px' }, h('button', { class: 'btn gold sm', onclick: async () => { try { await api('POST', `/signatures/${g.id}/exit`, { note: note.value }); toast('Exit recorded.'); draw(); } catch (ex) { toast(ex.message, true); } } }, 'Record exit now'))));
              } }, 'Record exit')), box); });
        return h('div', { class: 'card' }, h('div', { class: 'row', style: 'padding:0 0 10px;border:0' }, h('h2', { style: 'margin:0' }, u.code + ' ' + u.name), h('span', { class: 'm' }, u.signed + ' of ' + u.registered + ' signed')),
          h('div', { class: 'pbar', role: 'progressbar', 'aria-label': u.code + ' signed', 'aria-valuemin': 0, 'aria-valuemax': u.registered, 'aria-valuenow': u.signed }, h('i', { style: 'transform:scaleX(' + (u.registered ? u.signed / u.registered : 0).toFixed(3) + ')' })),
          ...(rows.length ? rows : [h('p', { class: 'm' }, tab === 'pending' ? 'Everyone registered for this paper has signed.' : 'Nobody has signed this paper yet.')]));
      }));
      clearTimeout(emailTimer); emailTimer = setTimeout(() => { if (location.hash === '#/rollcall') draw().catch(() => {}); }, 20000);
    };
    app.replaceChildren(shell('rollcall', h('h1', {}, 'Roll call'), h('p', { class: 'sub' }, s.period, ' · ', s.venue, '. Registered students who have not signed yet, so you can call them out. Refreshes every 20 seconds. Keep this screen away from students: it lists names.'), seg, host));
    await draw();
  }

  // --------------------------------------------------------------- records
  async function viewRecords() {
    if (S.user.role === 'invigilator') return go('#/console');
    const { records } = await api('GET', '/records');
    const f = h('input', { type: 'search', id: 'rf', placeholder: 'Filter by unit, code or admission number', 'aria-label': 'Filter records' });
    const list = h('div', {});
    const draw = () => { const t = f.value.trim().toLowerCase(); const rs = records.filter(r => !t || [r.code, r.unit, r.unit_name, r.student, r.period].join(' ').toLowerCase().includes(t));
      list.replaceChildren(...(rs.length ? rs.map(r => h('div', { class: 'row' }, h('div', {}, h('b', {}, r.kind === 'sheet' ? 'Unit sign sheet' : r.kind === 'pack' ? 'Session pack' : 'Student receipt'), ' · ', r.unit, ' ', r.unit_name || '', h('div', { class: 'm' }, r.code, ' · ', r.period, ' · ', r.venue, ' · ', fmt(r.issued_at), r.student ? ' · ' + r.student : '', r.entries != null ? ' · ' + r.entries + ' signed' : '')),
        h('div', { class: 'acts' }, chip(r.status === 'current' ? 'ok' : 'wn', r.status === 'current' ? 'Current' : 'Superseded'), h('button', { class: 'btn sm gold', onclick: () => DEMO.openDoc(r.code) }, 'Open sealed original')))) : [h('p', { class: 'm', style: 'padding:12px' }, 'No records yet. Sealed originals appear here as students sign and when sessions close.')])); };
    f.addEventListener('input', draw); draw();
    app.replaceChildren(shell('records', h('h1', {}, 'Records'), h('p', { class: 'sub' }, 'Sealed originals stored by the school. Every time one is opened, it is logged. Superseded versions are kept for the record.'), h('div', { class: 'card' }, f, list)));
  }

  // ---------------------------------------------------------------- verify
  function viewVerify() {
    const out = h('div', {});
    const code = h('input', { type: 'text', id: 'vc', placeholder: 'KCA-ABCD-EFGH-JKLM', autocapitalize: 'characters', autocomplete: 'off' });
    const show = (r) => out.replaceChildren(h('div', { class: 'card ' + (r.valid ? 'gold' : 'red') },
      h('h2', { style: r.valid ? '' : 'color:var(--er)' }, (r.valid ? '✓ ' : '✕ ') + r.message),
      r.found ? h('div', { class: 'm' }, ({ receipt: 'Student receipt', sheet: 'Unit sign sheet' })[r.kind], ' · ', r.unit, ' · ', r.period, ' · ', r.venue,
        r.student ? ' · Student ' + r.student : '', r.entries ? ' · ' + r.entries + ' students' : '', ' · issued ', fmt(r.issued_at, { year: 'numeric' }), r.status === 'superseded' ? ' · superseded by a newer version' : '') : null));
    const call = async (fn) => { try { show(await fn()); } catch (ex) { out.replaceChildren(h('div', { class: 'err' }, ex.message)); } };
    const shellOrPlain = (...c) => S.user ? shell('verify', ...c) : h('div', {}, h('header', { class: 'top' }, h('div', { class: 'top-in' }, h('div', { class: 'brand' }, h('div', { class: 'logo' }, h('img', { src: window.KCA_LOGO, alt: 'KCA University' })), h('span', { class: 'bt' }, 'KCA University', h('small', {}, 'Exam e-Sign Sheet'))), h('nav', {}, h('a', { href: '#/login' }, 'Staff sign in')))), h('main', {}, ...c));
    app.replaceChildren(shellOrPlain(h('h1', {}, 'Verify a sign sheet or receipt'), h('p', { class: 'sub' }, 'Anyone holding a PDF issued by this system can check that it is genuine and unaltered. No sign-in needed.'),
      h('div', { class: 'cols' },
        h('div', { class: 'card' }, h('h2', {}, 'Check by code'), h('div', { class: 'field' }, h('label', { for: 'vc' }, 'Verification code (printed in the PDF seal)'), code),
          h('button', { class: 'btn gold', onclick: () => call(() => api('POST', '/verify', { code: code.value })) }, 'Verify code')),
        h('div', { class: 'card' }, h('h2', {}, 'Check the PDF file itself'), h('p', { class: 'm' }, 'Detects any change to the file, even one character. The file is checked on the server and is not stored.'),
          h('input', { type: 'file', accept: 'application/pdf', 'aria-label': 'PDF file', onchange: (e) => { const f = e.target.files[0]; if (f) call(() => api('POST', '/verify', f)); } }))),
      out));
  }

  // ---------------------------------------------------------------- router
  function footer() {
    return h('footer', { class: 'foot' }, h('button', { class: 'linkbtn', onclick: () => {
      const ov = h('div', { class: 'okov', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Accessibility and data protection', onclick: (e) => { if (e.target === ov) ov.remove(); } }, h('div', { class: 'done-card', style: 'text-align:left;padding-bottom:20px' },
        h('h2', {}, 'Accessibility and data protection'),
        h('p', { class: 'm' }, 'This system is designed to WCAG 2.2 AA: it can be used with a keyboard, text is readable in light and dark mode, touch targets are large, and motion is switched off for people who prefer reduced motion.'),
        h('p', { class: 'm' }, 'If something is hard to use, tell the chief supervisor or KCA ICT. [KCA ICT contact to be provided.]'),
        h('p', { class: 'm' }, 'Exam records are owned by KCA University and handled under its data protection policy. Students see only their own details on the exam device.'),
        h('button', { class: 'btn gold', onclick: () => ov.remove() }, 'Close')));
      document.body.append(ov); } }, 'Accessibility and data protection'), h('span', { class: 'm' }, ' · Concept demo, not an official KCA product'));
  }
  async function render() { await renderInner(); const old = app.querySelector('.foot'); if (old) old.remove(); app.append(footer()); }
  async function renderInner() {
    clearTimers();
    const hash = location.hash || '#/';
    const [, route, a, b] = hash.split('/').map(decodeURIComponent);
    S.kiosk = ['sheet', 'student', 'form'].includes(route);
    if (route === 'form') S.unlock = 0;
    try {
      if (route === 'verify') return viewVerify();
      if (!S.user) return viewLogin();
      if (route === 'login') return go(home());
      switch (route) {
        case 'console': return await viewConsole();
        case 'sheet': return await viewSheet();
        case 'student': return await viewStudent(a);
        case 'form': return await viewForm(a, b);
        case 'records': return await viewRecords();
        case 'rollcall': return await viewRollcall();
        case 'director': return await viewDirector();
        case 'timetable': return await viewTimetable();
        default: return go(home());
      }
    } catch (ex) {
      if (ex.status === 401) return;
      app.replaceChildren(S.user ? shell('', h('div', { class: 'err' }, ex.message), h('button', { class: 'btn', onclick: () => go('#/console') }, 'Back to start')) : h('div', { class: 'err' }, ex.message));
    }
  }

  // Pointer-only depth on the identity card and sign-in card. Transform only, off for touch and for reduced motion.
  (() => {
    if (!window.matchMedia) return;
    const fine = matchMedia('(hover: hover) and (pointer: fine)'), calm = matchMedia('(prefers-reduced-motion: reduce)'); let on = null;
    document.addEventListener('pointermove', (e) => {
      if (!fine.matches || calm.matches) return;
      const t = e.target.closest ? e.target.closest('.who-card, .center .card') : null;
      if (on && on !== t) { on.style.transform = ''; on = null; }
      if (!t) return;
      if (e.target.closest('input, textarea, select, button, canvas')) { t.style.transform = ''; return; }
      on = t; const r = t.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
      t.style.transform = `perspective(900px) rotateX(${(-y * 4).toFixed(2)}deg) rotateY(${(x * 5).toFixed(2)}deg)`;
    }, { passive: true });
    document.addEventListener('pointerleave', () => { if (on) { on.style.transform = ''; on = null; } }, true);
  })();

  window.addEventListener('hashchange', render);
  (async () => {
    try { S.cfg = await (await fetch('/api/config', { credentials: 'same-origin' })).json(); S.csrf = S.cfg.csrf; S.user = S.cfg.user; }
    catch { S.cfg = {}; }
    render();
  })();
})();

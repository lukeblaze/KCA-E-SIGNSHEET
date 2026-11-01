/* KCA University e-Sign Sheet: single-page app. No frameworks, no innerHTML with data (XSS-safe by construction). */
(() => {
  'use strict';

  const app = document.getElementById('app');
  const S = { user: null, csrf: '', cfg: {}, sessions: [], sid: Number(sessionStorage.getItem('sid')) || 0 };
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
    try { res = await fetch('/api' + path, opt); }
    catch { throw Object.assign(new Error('Cannot reach the server. Check the Wi-Fi connection.'), { network: true }); }
    const data = await res.json().catch(() => ({}));
    if (res.status === 401 && S.user) { S.user = null; go('#/login'); throw Object.assign(new Error('Your session expired. Sign in again.'), { status: 401 }); }
    if (!res.ok) throw Object.assign(new Error(data.error || 'Request failed'), { status: res.status, data });
    return data;
  }

  const go = (hash) => { if (location.hash === hash) render(); else location.hash = hash; };
  const clearTimers = () => { clearTimeout(idleTimer); clearTimeout(searchTimer); clearTimeout(emailTimer); };

  // ----------------------------------------------------------------- shell
  function shell(active, ...content) {
    const nav = [['#/sheet', 'Sign sheet', 'sheet'], ['#/console', 'Console', 'console']];
    if (S.user.role === 'exam_director') nav.push(['#/director', 'Exam director', 'director']);
    nav.push(['#/verify', 'Verify a document', 'verify']);
    return h('div', {},
      h('header', { class: 'top' }, h('div', { class: 'top-in' },
        h('div', { class: 'brand' }, h('div', { class: 'logo' }, 'KCA'), 'KCA University · Exam e-Sign Sheet'),
        h('nav', { 'aria-label': 'Main' }, nav.map(([href, label, id]) => h('a', { href, 'aria-current': id === active ? 'page' : false }, label))),
        h('div', { class: 'who' }, h('span', {}, S.user.name, ' · ', ({ supervisor: 'Chief supervisor', invigilator: 'Invigilator', exam_director: 'Exam director' })[S.user.role]),
          h('button', { onclick: logout }, 'Sign out')))),
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
        go(r.user.role === 'exam_director' ? '#/director' : '#/console');
      } catch (ex) { err.textContent = ex.message; err.hidden = false; btn.disabled = false; }
    } },
      err,
      h('div', { class: 'field' }, h('label', { for: 'em' }, 'Staff email'), email),
      h('div', { class: 'field' }, h('label', { for: 'pw' }, 'Password'), pw), btn);
    const dev = S.cfg.dev ? h('div', { class: 'inf', style: 'margin-top:16px' },
      h('b', {}, 'Development mode (mock portal). '), 'Demo accounts: supervisor@kcau.ac.ke / Demo#Super1, invigilator1@kcau.ac.ke / Demo#Invig1, director@kcau.ac.ke / Demo#Direct1') : null;
    app.replaceChildren(
      h('header', { class: 'top' }, h('div', { class: 'top-in' }, h('div', { class: 'brand' }, h('div', { class: 'logo' }, 'KCA'), 'KCA University · Exam e-Sign Sheet'))),
      h('main', {}, h('div', { class: 'center' }, h('div', { class: 'card' }, h('h1', {}, 'Staff sign in'),
        h('p', { class: 'sub' }, 'Use your KCA portal staff account. Students never sign in; they sign on the exam device.'), form), dev,
        h('p', { class: 'm', style: 'text-align:center' }, h('a', { href: '#/verify' }, 'Verify a sign sheet or receipt')))));
  }

  // --------------------------------------------------------------- console
  async function loadSessions() { S.sessions = (await api('GET', '/sessions')).sessions; return S.sessions; }
  const cur = () => S.sessions.find(s => s.id === S.sid);

  async function viewConsole() {
    await loadSessions();
    const wrap = h('div', {});
    app.replaceChildren(shell('console', h('h1', {}, 'Exam sessions'), h('p', { class: 'sub' }, S.user.role === 'supervisor'
      ? 'Load an exam session, let other invigilators sign in to it with their own accounts, then collect signatures on this device.'
      : 'Sessions you can see or have been given access to.'), wrap));

    if (S.user.role === 'supervisor') {
      const now = new Date(), end = new Date(now.getTime() + 3 * 3600e3);
      const loc = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
      const f = { period: h('input', { type: 'text', id: 'pe', placeholder: 'e.g. Sem 2 2026, Main exams', maxlength: 80 }), venue: h('input', { type: 'text', id: 've', placeholder: 'e.g. Main Hall A', maxlength: 80 }),
        o: h('input', { type: 'datetime-local', id: 'op', value: loc(now) }), c: h('input', { type: 'datetime-local', id: 'cl', value: loc(end) }) };
      const err = h('div', { class: 'err', hidden: true, role: 'alert' });
      wrap.append(h('div', { class: 'card' }, h('h2', {}, '1 · Load exam session'), err,
        h('div', { class: 'cols' }, h('div', { class: 'field' }, h('label', { for: 'pe' }, 'Exam period'), f.period), h('div', { class: 'field' }, h('label', { for: 've' }, 'Venue'), f.venue)),
        h('div', { class: 'cols' }, h('div', { class: 'field' }, h('label', { for: 'op' }, 'Opens'), f.o), h('div', { class: 'field' }, h('label', { for: 'cl' }, 'Closes'), f.c)),
        h('button', { class: 'btn gold', onclick: async (e) => {
          err.hidden = true; e.target.disabled = true;
          try {
            const r = await api('POST', '/sessions', { period: f.period.value, venue: f.venue.value, opens_at: new Date(f.o.value).toISOString(), closes_at: new Date(f.c.value).toISOString() });
            S.sid = r.session.id; sessionStorage.setItem('sid', S.sid); toast('Exam session loaded. Add invigilators below, or start signing.'); render();
          } catch (ex) { err.textContent = ex.message; err.hidden = false; e.target.disabled = false; }
        } }, 'Load sign sheet on this device')));
    }

    if (!S.sessions.length) { wrap.append(h('div', { class: 'card' }, h('p', { class: 'm' }, 'No exam sessions yet.'))); return; }
    wrap.append(h('h2', { style: 'margin-top:8px' }, S.user.role === 'supervisor' ? '2 · Your sessions' : 'Sessions'));
    for (const s of S.sessions) wrap.append(sessionCard(s));
  }

  function sessionCard(s) {
    const body = h('div', {});
    const status = s.status === 'closed' ? chip('wn', 'Closed') : s.open ? chip('ok', 'Open for signing') : chip('wn', 'Not open yet / ended');
    const card = h('div', { class: 'card' + (s.id === S.sid ? ' gold' : '') },
      h('div', { class: 'row', style: 'padding:0 0 12px;border:0' },
        h('div', {}, h('b', {}, s.period), h('div', { class: 'm' }, s.venue, ' · ', fmt(s.opens_at), ' to ', fmt(s.closes_at, { day: undefined, month: undefined }), ' · Supervisor: ', s.supervisor)),
        h('div', { class: 'acts' }, status, h('span', { class: 'm' }, s.signatures + ' signatures · ' + s.students + ' students'))),
      h('div', { class: 'acts' },
        h('button', { class: 'btn gold sm', onclick: () => { S.sid = s.id; sessionStorage.setItem('sid', s.id); go('#/sheet'); } }, 'Open sign sheet'),
        h('button', { class: 'btn sm', onclick: () => toggle('units') }, 'Unit sheets (PDF)'),
        s.level !== 'invigilator' ? h('button', { class: 'btn sm', onclick: () => toggle('access') }, s.level === 'supervisor' ? 'Invigilator access' : 'View access') : null,
        s.level !== 'invigilator' && s.status === 'active' ? h('button', { class: 'btn sm danger', onclick: async () => {
          if (!confirm('Close this exam session now? Signing stops. Students can only correct a form if the exam director reopens it.')) return;
          try { await api('POST', `/sessions/${s.id}/close`); toast('Session closed.'); render(); } catch (ex) { toast(ex.message, true); }
        } }, 'Close session') : null),
      body);
    let shown = null;
    async function toggle(which) {
      if (shown === which) { body.replaceChildren(); shown = null; return; }
      shown = which; body.replaceChildren(h('p', { class: 'm' }, 'Loading...'));
      try { body.replaceChildren(which === 'units' ? await unitsPanel(s) : await accessPanel(s, () => toggle2(which))); }
      catch (ex) { body.replaceChildren(h('div', { class: 'err' }, ex.message)); }
    }
    async function toggle2(which) { shown = null; await toggle(which); }
    return card;
  }

  async function unitsPanel(s) {
    const { units } = await api('GET', `/sessions/${s.id}/units`);
    if (!units.length) return h('p', { class: 'm', style: 'margin-top:12px' }, 'No signatures recorded yet.');
    return h('div', { style: 'margin-top:12px' }, units.map(u => h('div', { class: 'row' },
      h('div', {}, h('b', {}, u.code), ' ', u.name, h('div', { class: 'm' }, u.signed + ' signed', Number(u.email_problems) ? ' · ' + u.email_problems + ' email copies need resending' : '')),
      h('a', { class: 'btn sm gold', style: 'display:inline-flex;align-items:center;text-decoration:none', href: `/api/sessions/${s.id}/sheet/${encodeURIComponent(u.code)}.pdf` }, 'Download sealed PDF'))));
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
    const q = h('input', { type: 'search', id: 'q', placeholder: 'Type name or admission number', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', 'aria-describedby': 'qh' });
    const picker = S.sessions.length > 1 ? h('select', { 'aria-label': 'Exam session', onchange: (e) => { S.sid = Number(e.target.value); sessionStorage.setItem('sid', S.sid); render(); } },
      S.sessions.map(x => h('option', { value: x.id, selected: x.id === S.sid }, x.period + ' · ' + x.venue))) : null;
    const closed = !s.open;
    q.addEventListener('input', () => {
      clearTimeout(searchTimer);
      const v = q.value.trim();
      if (v.length < 2) { results.replaceChildren(); return; }
      searchTimer = setTimeout(async () => {
        try {
          const { students } = await api('GET', `/sessions/${s.id}/students?q=${encodeURIComponent(v)}`);
          results.replaceChildren(...(students.length ? students.map(st => h('button', { class: 'row pick', onclick: () => go('#/student/' + encodeURIComponent(st.adm_no)) },
            h('span', {}, h('b', {}, st.full_name), h('div', { class: 'm' }, st.adm_no, ' · ', st.programme)), h('span', { class: 'm' }, 'Select ›')))
            : [h('p', { class: 'm' }, 'No student matches. Check the spelling or try the admission number.')]));
        } catch (ex) { results.replaceChildren(h('div', { class: 'err' }, ex.message)); }
      }, 250);
    });
    app.replaceChildren(shell('sheet',
      h('h1', {}, 'Exam sign sheet'),
      h('p', { class: 'sub' }, s.period, ' · ', s.venue, ' · ', fmt(s.opens_at), ' to ', fmt(s.closes_at, { day: undefined, month: undefined })),
      picker && h('div', { class: 'field' }, picker),
      closed ? h('div', { class: 'card red' }, h('b', {}, s.status === 'closed' ? 'This session is closed.' : 'This session is not open for new signatures right now.'), h('div', { class: 'm' }, 'Students can still correct a form only if the exam director has reopened it.')) : null,
      h('div', { class: 'grid' },
        h('div', {}, h('div', { class: 'card' }, h('label', { for: 'q' }, 'Find student'), q, h('div', { id: 'qh', class: 'm', style: 'margin:6px 0 12px' }, 'Hand the device to the student. They type their name or admission number, then tap their name.'), results)),
        h('div', {}, h('div', { class: 'card' }, h('h2', {}, 'Session progress'), h('div', { class: 'big' }, s.signatures), h('div', { class: 'm' }, 'signatures from ' + s.students + ' students')),
          h('div', { class: 'card gold' }, h('div', { class: 'm' }, h('b', {}, 'After signing: '), 'a sealed PDF copy is emailed to the student\'s KCA school email. They can correct mistakes until the session closes; after that only the exam director can reopen the form.'))))));
    q.focus();
  }

  // --------------------------------------------------------------- student
  async function viewStudent(adm) {
    const s = cur();
    if (!s) return go('#/console');
    app.replaceChildren(shell('sheet', h('p', { class: 'm' }, 'Loading...')));
    const r = await api('GET', `/sessions/${s.id}/students/${encodeURIComponent(adm)}/units`);
    const back = h('button', { class: 'back', onclick: () => go('#/sheet') }, '‹ Back to search');
    const head = h('div', { class: 'card' }, h('h1', {}, r.student.full_name), h('div', { class: 'm' }, r.student.adm_no, ' · ', r.student.programme));
    if (!r.cleared) {
      return app.replaceChildren(shell('sheet', back, head, h('div', { class: 'card red' }, h('b', { style: 'color:var(--er)' }, 'Not cleared to sit exams'), h('p', {}, r.reason), h('p', { class: 'm' }, 'Do not take a signature. Send the student to the exam director.'))));
    }
    const rows = r.units.length ? r.units.map(u => {
      const reopen = h('div', {});
      return h('div', {}, h('div', { class: 'row' },
        h('div', {}, h('b', {}, u.code), ' ', u.name, u.signed ? h('div', { class: 'm' }, 'Signed ', fmt(u.signed_at, { day: undefined, month: undefined }), u.version > 1 ? ' · corrected (v' + u.version + ')' : '', u.email_status && !['sent', 'outbox'].includes(u.email_status) ? ' · email copy not delivered' : '') : null),
        h('div', { class: 'acts' }, u.signed ? chip('ok', 'Signed') : chip('wn', 'Not signed'),
          !u.signed && s.open ? h('button', { class: 'btn gold sm', onclick: () => go(`#/form/${encodeURIComponent(r.student.adm_no)}/${encodeURIComponent(u.code)}`) }, 'Sign') : null,
          u.signed && u.can_edit ? h('button', { class: 'btn sm', onclick: () => go(`#/form/${encodeURIComponent(r.student.adm_no)}/${encodeURIComponent(u.code)}`) }, 'Correct') : null,
          u.signed && !u.can_edit && s.level !== 'director' ? h('button', { class: 'btn sm', onclick: () => reopenBox(reopen, u.signature_id) }, 'Ask exam director to reopen') : null,
          u.signed && u.email_status === 'failed' ? h('button', { class: 'btn sm', onclick: async () => { try { const x = await api('POST', `/signatures/${u.signature_id}/resend`); toast(x.email.status === 'failed' ? 'Still could not send. Check mail settings.' : 'Email copy sent.', x.email.status === 'failed'); render(); } catch (ex) { toast(ex.message, true); } } }, 'Resend email') : null)), reopen);
    }) : [h('p', { class: 'm', style: 'padding:12px' }, 'The portal lists no units for this student this semester.')];
    app.replaceChildren(shell('sheet', back, head, h('div', { class: 'card' }, h('h2', {}, 'Units cleared for ' + s.period), h('p', { class: 'm' }, 'Tap the unit being sat now.'), ...rows)));
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
    app.replaceChildren(shell('sheet', h('p', { class: 'm' }, 'Loading...')));
    const [ur, ex] = await Promise.all([api('GET', `/sessions/${s.id}/students/${encodeURIComponent(adm)}/units`),
      api('GET', `/sessions/${s.id}/signatures/${encodeURIComponent(adm)}/${encodeURIComponent(unitCode)}`).catch(() => null)]);
    const unit = ur.units.find(u => u.code === unitCode);
    if (!ur.cleared || !unit) return go('#/student/' + encodeURIComponent(adm));
    const old = ex && ex.signature;
    const resetIdle = () => { clearTimeout(idleTimer); idleTimer = setTimeout(() => { toast('Form closed after 3 minutes of no activity. Nothing was saved.', true); go('#/sheet'); }, 180000); };
    resetIdle();

    const err = h('div', { class: 'err', hidden: true, role: 'alert' });
    const name = h('input', { type: 'text', id: 'fn', readonly: true, value: ur.student.full_name });
    const admIn = h('input', { type: 'text', id: 'fa', readonly: true, value: ur.student.adm_no });
    const phone = h('input', { type: 'tel', id: 'fp', autocomplete: 'off', inputmode: 'tel', placeholder: '0712 345 678', value: old ? old.phone : '' });
    const book = h('input', { type: 'text', id: 'fb', autocomplete: 'off', autocapitalize: 'characters', placeholder: 'As printed on the booklet', maxlength: 20, value: old ? old.booklet_no : '' });
    const email = h('input', { type: 'email', id: 'fe', autocomplete: 'off', inputmode: 'email', autocapitalize: 'off', placeholder: 'your school email', value: old ? old.email : '' });
    const checks = h('ul', { class: 'checks', 'aria-live': 'polite' });
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

    async function runChecks() {
      if (!email.value.trim()) { checks.replaceChildren(); return null; }
      try {
        const r = await api('POST', `/sessions/${s.id}/validate-email`, { adm_no: ur.student.adm_no, email: email.value });
        checks.replaceChildren(...r.checks.map(c => h('li', {}, h('span', { class: 'dot ' + (c.passed ? 'p' : 'f'), 'aria-hidden': 'true' }, c.passed ? '✓' : '✕'), h('span', {}, c.label, h('span', { class: 'sr' }, c.passed ? ' passed' : ' failed')))));
        return r.ok;
      } catch { return null; }
    }
    email.addEventListener('input', () => { clearTimeout(emailTimer); emailTimer = setTimeout(runChecks, 350); resetIdle(); });
    for (const el of [phone, book, sure]) el.addEventListener('input', resetIdle);
    if (old) runChecks();

    async function submit() {
      err.hidden = true;
      if (!inked) return fail('Please sign in the signature box.');
      if (!sure.checked) return fail('Tick the box to confirm you are present for this exam.');
      save.disabled = true; save.textContent = 'Saving...';
      try {
        const r = await api('POST', `/sessions/${s.id}/signatures`, { adm_no: ur.student.adm_no, unit_code: unitCode, phone: phone.value, booklet_no: book.value, email: email.value, signature: canvas.toDataURL('image/png') });
        clearTimeout(idleTimer);
        const st = r.email.status;
        toast(st === 'failed' ? 'Saved, but the email copy could not be sent. Use "Resend email" on the unit.' : st === 'outbox' ? 'Saved. Email copy queued (development outbox).' : 'Saved. A PDF copy was sent to ' + r.email.to, st === 'failed');
        go('#/sheet');
      } catch (e) {
        if (e.data && e.data.checks) runChecks();
        fail(e.message + (e.data && e.data.needs_reopen ? ' Go back and tap "Ask exam director to reopen".' : ''));
        save.disabled = false; save.textContent = 'Save & send email copy';
      }
    }
    function fail(msg) { err.textContent = msg; err.hidden = false; err.scrollIntoView({ block: 'center', behavior: 'smooth' }); }

    app.replaceChildren(shell('sheet',
      h('button', { class: 'back', onclick: () => { clearTimeout(idleTimer); go('#/student/' + encodeURIComponent(adm)); } }, '‹ Back to units'),
      h('h1', {}, (old ? 'Correct signature · ' : 'Sign for ') + unit.code), h('p', { class: 'sub' }, unit.name, ' · ', s.period),
      h('div', { class: 'grid' },
        h('div', { class: 'card' }, err,
          h('div', { class: 'cols' }, h('div', { class: 'field' }, h('label', { for: 'fn' }, 'Full name (from portal)'), name), h('div', { class: 'field' }, h('label', { for: 'fa' }, 'Admission number (from portal)'), admIn)),
          h('div', { class: 'cols' }, h('div', { class: 'field' }, h('label', { for: 'fp' }, 'Phone number'), phone), h('div', { class: 'field' }, h('label', { for: 'fb' }, 'Booklet serial number'), book)),
          h('div', { class: 'field' }, h('label', { for: 'fe' }, 'School email'), email, checks),
          h('div', { class: 'field' }, h('label', {}, 'Signature'), h('div', { class: 'sigwrap' }, canvas, hint), h('button', { class: 'btn sm', style: 'margin-top:8px', type: 'button', onclick: clear }, 'Clear signature')),
          h('div', { class: 'field', style: 'display:flex;gap:10px;align-items:flex-start' }, sure, h('label', { for: 'fc', style: 'font-weight:400;margin:0' }, 'I confirm I am present for this exam and the details above are mine.')),
          h('div', { class: 'acts' }, save, h('button', { class: 'btn', style: 'min-height:50px', onclick: () => { clearTimeout(idleTimer); go('#/student/' + encodeURIComponent(adm)); } }, 'Cancel'))),
        h('div', {}, h('div', { class: 'card' }, h('h2', {}, 'Before you save'), h('p', { class: 'm' }, 'Use your own KCA school email. Copies sent to any other address are blocked, so your record cannot be lost or leaked.')),
          h('div', { class: 'card gold' }, h('p', { class: 'm' }, 'After you save, the screen returns to the sign sheet and a sealed PDF copy is emailed to you. Keep it.'))))));
    phone.focus();
  }

  // -------------------------------------------------------------- director
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
    app.replaceChildren(shell('director', h('h1', {}, 'Exam director'), h('p', { class: 'sub' }, 'Reopen a student\'s form after the exam period when they need to correct their details.'),
      h('div', { class: 'card' }, h('h2', {}, 'Reopen requests'), ...rows),
      h('div', { class: 'card' }, h('h2', {}, 'Audit trail integrity'), h('p', { class: 'm' }, 'Every sign-in, signature, correction, access change and download is chained. Run this check to confirm nothing was edited or removed.'),
        h('button', { class: 'btn sm', onclick: async () => {
          try { const r = await api('GET', '/audit/verify'); auditOut.replaceChildren(r.ok ? h('div', { class: 'inf' }, '✓ Intact. ' + r.entries + ' entries verified.') : h('div', { class: 'err' }, 'TAMPERING DETECTED at entry ' + r.broken_at + '. Escalate to ICT security.')); }
          catch (ex) { toast(ex.message, true); }
        } }, 'Verify audit trail'), auditOut)));
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
    const shellOrPlain = (...c) => S.user ? shell('verify', ...c) : h('div', {}, h('header', { class: 'top' }, h('div', { class: 'top-in' }, h('div', { class: 'brand' }, h('div', { class: 'logo' }, 'KCA'), 'KCA University · Exam e-Sign Sheet'), h('nav', {}, h('a', { href: '#/login' }, 'Staff sign in')))), h('main', {}, ...c));
    app.replaceChildren(shellOrPlain(h('h1', {}, 'Verify a sign sheet or receipt'), h('p', { class: 'sub' }, 'Anyone holding a PDF issued by this system can check that it is genuine and unaltered. No sign-in needed.'),
      h('div', { class: 'cols' },
        h('div', { class: 'card' }, h('h2', {}, 'Check by code'), h('div', { class: 'field' }, h('label', { for: 'vc' }, 'Verification code (printed in the PDF seal)'), code),
          h('button', { class: 'btn gold', onclick: () => call(() => api('POST', '/verify', { code: code.value })) }, 'Verify code')),
        h('div', { class: 'card' }, h('h2', {}, 'Check the PDF file itself'), h('p', { class: 'm' }, 'Detects any change to the file, even one character. The file is checked on the server and is not stored.'),
          h('input', { type: 'file', accept: 'application/pdf', 'aria-label': 'PDF file', onchange: (e) => { const f = e.target.files[0]; if (f) call(() => api('POST', '/verify', f)); } }))),
      out));
  }

  // ---------------------------------------------------------------- router
  async function render() {
    clearTimers();
    const hash = location.hash || '#/';
    const [, route, a, b] = hash.split('/').map(decodeURIComponent);
    try {
      if (route === 'verify') return viewVerify();
      if (!S.user) return viewLogin();
      if (route === 'login') return go(S.user.role === 'exam_director' ? '#/director' : '#/console');
      switch (route) {
        case 'console': return await viewConsole();
        case 'sheet': return await viewSheet();
        case 'student': return await viewStudent(a);
        case 'form': return await viewForm(a, b);
        case 'director': return await viewDirector();
        default: return go(S.user.role === 'exam_director' ? '#/director' : '#/console');
      }
    } catch (ex) {
      if (ex.status === 401) return;
      app.replaceChildren(S.user ? shell('', h('div', { class: 'err' }, ex.message), h('button', { class: 'btn', onclick: () => go('#/console') }, 'Back to start')) : h('div', { class: 'err' }, ex.message));
    }
  }

  window.addEventListener('hashchange', render);
  (async () => {
    try { S.cfg = await (await fetch('/api/config', { credentials: 'same-origin' })).json(); S.csrf = S.cfg.csrf; S.user = S.cfg.user; }
    catch { S.cfg = {}; }
    render();
  })();
})();

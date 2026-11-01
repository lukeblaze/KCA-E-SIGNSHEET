/* In-browser stand-in for the PHP server. Same rules, same JSON shapes, state lives in memory and resets on reload. */
(() => {
  'use strict';
  const P = __PORTAL__;
  class Err extends Error { constructor(status, msg, data) { super(msg); this.status = status; this.data = data || {}; } }
  const iso = (d) => new Date(d).toISOString().replace(/\.\d+Z$/, 'Z');
  const nowI = () => iso(Date.now());
  const norm = (s) => String(s).toUpperCase().replace(/[^A-Z0-9]/g, '');
  const AL = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const mkcode = () => { let o = ''; for (let i = 0; i < 12; i++) o += AL[Math.floor(Math.random() * 32)]; return `KCA-${o.slice(0, 4)}-${o.slice(4, 8)}-${o.slice(8)}`; };
  const sha = async (s) => [...new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(s)))].map(b => b.toString(16).padStart(2, '0')).join('');
  const fakeSig = () => { let o = ''; const b = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'; for (let i = 0; i < 68; i++) o += b[Math.floor(Math.random() * 64)]; return o; };
  const wait = (ms) => new Promise(r => setTimeout(r, ms));
  let DB, user = null, netOff = false;
  const photo = (st) => { let k = 0; for (const c of st.adm_no) k = (k * 31 + c.charCodeAt(0)) % 360;
    return 'data:image/svg+xml;utf8,' + encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 150"><rect width="120" height="150" fill="hsl(${k},35%,82%)"/><circle cx="60" cy="58" r="26" fill="hsl(${k},30%,45%)"/><path d="M12 150c4-42 26-58 48-58s44 16 48 58z" fill="hsl(${k},30%,45%)"/></svg>`); };
  const sampleSig = () => 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 90"><path d="M12 62C40 8 62 92 92 40S142 18 152 60 202 84 288 28" fill="none" stroke="#0B2A5B" stroke-width="3.5" stroke-linecap="round"/><path d="M40 78H250" stroke="#0B2A5B" stroke-width="1.5" opacity=".5"/></svg>');
  const STATUSES = { open: 'Open', resolved_fixed: 'Resolved: issue fixed', let_go: 'Noted: no action taken', resit_required: 'Re-sit required', referred: 'Referred to disciplinary committee' };
  const CATS = ['Suspected malpractice', 'Unauthorised material', 'Disruption', 'Illness or emergency', 'Other'];
  const IDENT = { card_id: 'Exam card and student ID checked', card_only: 'Exam card checked, no student ID', photo_only: 'Photo match only (no exam card or ID)' };
  const sUnits = (s) => s.units || Object.keys(P.units);
  const regFor = (code) => P.students.filter(st => (st.cleared ? st.units : (st.registered || [])).includes(code));

  function reset() {
    DB = { sessions: [], access: [], sigs: [], hist: [], reopen: [], docs: [], audit: [], fails: {}, incidents: [], flags: [], keyuse: [], misses: {}, failNext: false, dir: { name: 'Prof. Exam Director', sig: sampleSig(), at: nowI() }, keyfp: '0'.repeat(64), n: { s: 0, sig: 0, req: 0, inc: 0, flag: 0, i: 0 }, imports: [] };
    sha('kca-demo-signing-key-' + Math.random()).then(x => { DB.keyfp = x; });
    user = null;
    DB.sessions.push({ id: ++DB.n.s, code: 'S' + new Date().toISOString().slice(2, 10).replace(/-/g, '') + '-DEMO', period: 'Sem 2 2026, Main exams', venue: 'Main Hall A',
      opens_at: iso(Date.now() - 10 * 60e3), closes_at: iso(Date.now() + 4 * 3600e3), supervisor_email: 'supervisor@kcau.ac.ke', supervisor_name: 'Dr. Sample Supervisor', status: 'active', offline: false, units: ['BSD 4101', 'BSD 4102', 'BSD 4103', 'BIT 3201'], late_after: 30 });
  }
  async function audit(actor, action, sid, payload) {
    const prev = DB.audit.length ? DB.audit[DB.audit.length - 1].hash : '0'.repeat(64);
    const ts = nowI(), json = JSON.stringify(payload || {});
    DB.audit.push({ ts, actor, action, sid, json, prev, hash: await sha([prev, ts, actor, action, sid, json].join('|')) });
  }
  async function auditVerify() {
    let prev = '0'.repeat(64), n = 0;
    for (const r of DB.audit) { n++; if (r.prev !== prev || r.hash !== await sha([prev, r.ts, r.actor, r.action, r.sid, r.json].join('|'))) return { ok: false, entries: n, broken_at: n }; prev = r.hash; }
    return { ok: true, entries: n, broken_at: null };
  }

  const staff = (email) => P.staff.find(u => u.email.toLowerCase() === String(email).trim().toLowerCase());
  const pubStaff = (u) => ({ email: u.email, name: u.name, role: u.role, school: u.school || '' });
  const student = (adm) => P.students.find(s => norm(s.adm_no) === norm(adm));
  const unitsFor = (st) => st.cleared ? st.units.map(c => ({ code: c, name: P.units[c] || c })) : [];
  const str = (b, k, max) => { const v = b[k] == null ? '' : b[k]; if (typeof v !== 'string') throw new Err(422, 'Invalid ' + k + '.'); const t = v.trim().replace(/\s+/g, ' '); if (t.length > (max || 200)) throw new Err(422, k + ' is too long.'); return t; };
  const phoneNorm = (p) => { const m = String(p || '').replace(/[\s\-().]/g, '').match(/^(?:\+?254|0)([17]\d{8})$/); return m ? '+254' + m[1] : null; };
  const emailOk = (e) => { const d = e.split('@')[1] || ''; return /^[^@\s]+@[^@\s]+$/.test(e) && ['students.kcau.ac.ke', 'students.kca.ac.ke'].includes(d); };

  function access(sid) {
    const s = DB.sessions.find(x => x.id === Number(sid));
    if (!s) throw new Err(404, 'Exam session not found.');
    const now = nowI(), active = s.status === 'active', open = active && s.opens_at <= now && now <= s.closes_at;
    if (user.role === 'exam_director') return [s, 'director', open];
    if (s.supervisor_email === user.email) return [s, 'supervisor', open];
    if (DB.access.some(a => a.sid === s.id && a.email === user.email && !a.revoked_at) && active && now <= s.closes_at) return [s, 'invigilator', open];
    throw new Err(403, 'You do not have access to this exam session.');
  }
  const need = (lvl, ok) => { if (!ok.includes(lvl)) throw new Err(403, 'Your role cannot do that.'); };
  const canEdit = (s, g, now) => (s.status === 'active' && now <= s.closes_at) || (g.reopened_until && g.reopened_until > now);
  function sessionOut(s, level, open) {
    const g = DB.sigs.filter(x => x.session_id === s.id);
    return { source: s.source || 'timetable', id: s.id, code: s.code, period: s.period, venue: s.venue, opens_at: s.opens_at, closes_at: s.closes_at, status: s.status, supervisor: s.supervisor_name, level, open, offline: !!s.offline, units: sUnits(s), late_after: s.late_after || 30, expected: sUnits(s).reduce((n, c) => n + regFor(c).filter(st => st.cleared).length, 0), signatures: g.length, students: new Set(g.map(x => x.adm_no)).size };
  }
  const ink = (url) => new Promise((res) => {
    const im = new Image();
    im.onload = () => { const c = document.createElement('canvas'); c.width = 120; c.height = 40; const x = c.getContext('2d'); x.drawImage(im, 0, 0, 120, 40); const d = x.getImageData(0, 0, 120, 40).data; let n = 0; for (let i = 3; i < d.length; i += 4) if (d[i] > 60) n++; res(n); };
    im.onerror = () => res(0); im.src = url;
  });

  const useKey = (doc, purpose) => DB.keyuse.push({ ts: nowI(), doc: doc.code, purpose, by: user ? user.email : 'system' });
  const dirNow = () => ({ ...DB.dir });

  function incidentsFor(sid, unit) { return DB.incidents.filter(i => i.sid === sid && (!unit || i.unit === unit)); }
  function unitReport(s, code) {
    const rows = DB.sigs.filter(x => x.session_id === s.id && x.unit_code === code).sort((a, b) => a.full_name.localeCompare(b.full_name));
    const signed = new Set(rows.map(r => r.adm_no));
    const absent = regFor(code).filter(st => !signed.has(st.adm_no)).map(st => ({ adm_no: st.adm_no, full_name: st.full_name, state: st.cleared ? 'absent' : 'not_cleared', reason: st.cleared ? null : (st.reason || 'Not cleared') }));
    return { rows, absent, registered: regFor(code).length };
  }
  async function makeSheet(s, code) {
    const { rows, absent } = unitReport(s, code); if (!rows.length && !absent.length) return null;
    const incs = incidentsFor(s.id, code);
    const key = JSON.stringify([rows.map(r => [r.id, r.version, r.review || '', r.exit_at || '']), absent.map(a => a.adm_no), incs.map(i => [i.id, i.entries.length])]);
    let doc = DB.docs.find(d => d.kind === 'sheet' && d.sid === s.id && d.unit === code && d.key === key);
    if (!doc) {
      doc = { code: mkcode(), kind: 'sheet', sid: s.id, unit: code, unit_name: P.units[code] || code, key, n: DB.docs.length + 1, entries: rows.length, issued_at: nowI(), hash: await sha(key + s.code), rsa: fakeSig(), period: s.period, venue: s.venue };
      doc.snap = { rows: JSON.parse(JSON.stringify(rows)), absent, incidents: JSON.parse(JSON.stringify(incs)), supervisor: s.supervisor_name, opens_at: s.opens_at, late_after: s.late_after || 30, dir: dirNow(), fp: DB.keyfp };
      DB.docs.push(doc); useKey(doc, 'Sealed unit sign sheet'); await audit(user ? user.email : 'system', 'sheet_sealed', s.id, { unit: code, doc: doc.code });
    }
    return doc;
  }
  async function makePack(s) {
    const sheets = []; for (const code of sUnits(s)) { const d = await makeSheet(s, code); if (d) sheets.push(d); }
    const sigs = DB.sigs.filter(g => g.session_id === s.id), incs = incidentsFor(s.id), chain = await auditVerify();
    const rows = sheets.map(d => ({ unit: d.unit, name: d.unit_name, registered: d.snap.rows.length + d.snap.absent.length, signed: d.snap.rows.length, absent: d.snap.absent.length, code: d.code }));
    const key = JSON.stringify([sheets.map(d => d.code), chain.ok]);
    let doc = DB.docs.find(d => d.kind === 'pack' && d.sid === s.id && d.key === key);
    if (!doc) {
      const late = sigs.filter(g => g.signed_at > iso(new Date(s.opens_at).getTime() + (s.late_after || 30) * 60000)).length;
      doc = { code: mkcode(), kind: 'pack', sid: s.id, unit: 'All papers', unit_name: sUnits(s).join(', '), key, n: DB.docs.length + 1, entries: sigs.length, issued_at: nowI(), hash: await sha(key + s.code + 'pack'), rsa: fakeSig(), period: s.period, venue: s.venue };
      doc.snap = { rows, supervisor: s.supervisor_name, opens_at: s.opens_at, closes_at: s.closes_at, status: s.status,
        totals: { signed: sigs.length, late, exits: sigs.filter(g => g.exit_at).length, offline: sigs.filter(g => g.offline).length, photo_only: sigs.filter(g => g.ident === 'photo_only').length, review_pending: sigs.filter(g => g.review === 'pending').length, incidents: incs.length, corrections: DB.hist.filter(h => sigs.some(g => g.id === h.id)).length },
        chain: { ok: chain.ok, entries: chain.entries }, dir: dirNow(), fp: DB.keyfp };
      DB.docs.push(doc); useKey(doc, 'Sealed session pack'); await audit(user ? user.email : 'system', 'pack_sealed', s.id, { doc: doc.code });
    }
    return doc;
  }
  const docStatus = (d) => d.kind === 'pack' ? (DB.docs.some(x => x.kind === 'pack' && x.sid === d.sid && x.n > d.n) ? 'superseded' : 'current') : d.kind === 'receipt' ? ((DB.sigs.find(x => x.id === d.sigId) || { version: 0 }).version > d.version ? 'superseded' : 'current')
    : (DB.docs.some(x => x.kind === 'sheet' && x.sid === d.sid && x.unit === d.unit && x.n > d.n) ? 'superseded' : 'current');

  async function deliver(g) {
    const s = DB.sessions.find(x => x.id === g.session_id);
    const payload = { kind: 'receipt', session: s.code, adm_no: g.adm_no, unit_code: g.unit_code, version: g.version, booklet: g.booklet_no, signed_at: g.signed_at };
    const doc = { code: mkcode(), kind: 'receipt', sid: s.id, sigId: g.id, unit: g.unit_code, version: g.version, issued_at: nowI(), hash: await sha(JSON.stringify(payload)), rsa: fakeSig(), period: s.period, venue: s.venue, unit_name: g.unit_name, adm_no: g.adm_no };
    doc.snap = { sig: JSON.parse(JSON.stringify(g)), dir: { ...DB.dir }, fp: DB.keyfp };
    DB.docs.push(doc); useKey(doc, 'Sealed student receipt');
    g.receipt_code = doc.code;
    if (DB.failNext) { DB.failNext = false; g.email_status = 'failed'; await audit('system', 'receipt_email_failed', s.id, { signature_id: g.id, doc: doc.code }); return { status: 'failed', code: doc.code, to: g.email }; }
    g.email_status = 'sent';
    await audit('system', 'receipt_sent', s.id, { signature_id: g.id, doc: doc.code });
    return { status: 'sent', code: doc.code, to: g.email };
  }


  // ================================================================ timetable engine (rules-based, no AI)
  const TT_SCHOOL = (code) => ({ BSD: 'School of Technology', BIT: 'School of Technology', BBM: 'School of Business', BED: 'School of Education' })[String(code).slice(0, 3)] || 'Other';
  const lev = (a, b) => { const m = a.length, n = b.length, d = Array.from({ length: m + 1 }, (_, i) => [i, ...Array(n).fill(0)]); for (let j = 1; j <= n; j++) d[0][j] = j;
    for (let i = 1; i <= m; i++) for (let j = 1; j <= n; j++) d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1)); return d[m][n]; };
  function parseCsv(text) {
    const first = String(text).split(/\r?\n/).find(l => l.trim()) || '';
    const delim = [',', ';', '\t'].map(d => [d, first.split(d).length]).sort((a, b) => b[1] - a[1])[0][0];
    const rows = []; let row = [], cell = '', q = false;
    const src = String(text).replace(/^﻿/, '');
    for (let i = 0; i < src.length; i++) {
      const c = src[i];
      if (q) { if (c === '"') { if (src[i + 1] === '"') { cell += '"'; i++; } else q = false; } else cell += c; }
      else if (c === '"') q = true;
      else if (c === delim) { row.push(cell); cell = ''; }
      else if (c === '\n' || c === '\r') { if (c === '\r' && src[i + 1] === '\n') i++; row.push(cell); if (row.some(x => x.trim())) rows.push({ cells: row, line: rows.length + 1 }); row = []; cell = ''; }
      else cell += c;
    }
    row.push(cell); if (row.some(x => x.trim())) rows.push({ cells: row, line: rows.length + 1 });
    return rows;
  }
  const HDR = { unit: ['unit', 'unitcode', 'code', 'course', 'coursecode', 'paper'], date: ['date', 'examdate', 'day'], start: ['start', 'starttime', 'from', 'time'], end: ['end', 'endtime', 'to', 'finish'],
    venue: ['venue', 'room', 'hall', 'location'], lecturer: ['lecturer', 'lecturers', 'instructor', 'teacher', 'lecturername', 'lectureremail'], candidates: ['candidates', 'students', 'hallsize', 'size', 'headcount', 'numberofstudents'] };
  const MON = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
  const nairobiYear = () => Number(new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Nairobi', year: 'numeric' }).format(new Date()));
  function parseDate(v) {
    v = String(v).trim().toLowerCase().replace(/\s+/g, ' '); let y, mo, d, m;
    if ((m = v.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/))) { y = +m[1]; mo = +m[2]; d = +m[3]; }
    else if ((m = v.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/))) { d = +m[1]; mo = +m[2]; y = +m[3]; if (y < 100) y += 2000; }
    else if ((m = v.replace(/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*,?\s*/, '').match(/^(\d{1,2})(?:st|nd|rd|th)?\s*(?:of\s+)?([a-z]{3,9})\.?,?\s*(\d{4})?$/))) { d = +m[1]; mo = MON.indexOf(m[2].slice(0, 3)) + 1; y = m[3] ? +m[3] : nairobiYear(); }
    if (!y || mo < 1 || mo > 12 || d < 1 || d > 31) return null;
    const t = new Date(Date.UTC(y, mo - 1, d)); if (t.getUTCMonth() !== mo - 1) return null;
    return `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
  }
  function parseTime(v) {
    const m = String(v).trim().toLowerCase().replace(/\s+/g, '').match(/^(\d{1,2})(?:[:.h](\d{2}))?(am|pm|a\.m\.|p\.m\.)?$/); if (!m) return null;
    let h = +m[1]; const mi = m[2] ? +m[2] : 0, ap = m[3] ? m[3][0] : '';
    if (ap === 'p' && h < 12) h += 12; if (ap === 'a' && h === 12) h = 0;
    if (h > 23 || mi > 59 || (ap && +m[1] > 12)) return null;
    return `${String(h).padStart(2, '0')}:${String(mi).padStart(2, '0')}`;
  }
  const TITLES = new Set(['dr', 'prof', 'mr', 'mrs', 'ms', 'miss', 'eng', 'rev', 'sir']);
  const nameToks = (s) => String(s).toLowerCase().replace(/[^a-z\s.]/g, ' ').replace(/\./g, ' ').split(/\s+/).filter(t => t && !TITLES.has(t));
  function matchStaff(v) {
    v = String(v).trim(); if (!v) return null;
    if (v.includes('@')) return P.staff.find(u => u.email.toLowerCase() === v.toLowerCase()) || null;
    const q = nameToks(v); if (!q.length) return null;
    const hits = P.staff.filter(u => { const t = nameToks(u.name); return q.every(x => x.length === 1 ? t.some(y => y[0] === x) : t.includes(x)); });
    return hits.length === 1 ? hits[0] : null;
  }
  const unitLecturers = (code, extra) => new Set([...P.staff.filter(u => (u.teaches || []).includes(code)).map(u => u.email), ...((extra && extra[code]) || [])]);
  const iv = (date, hm) => new Date(`${date}T${hm}:00+03:00`);
  const pool = () => P.staff.filter(u => ['supervisor', 'invigilator'].includes(u.role));

  function buildPlan(csv, opt) {
    const rows = parseCsv(csv), errors = [], tidy = [], warns = [], units = {};
    if (rows.length < 2) { errors.push({ line: 0, msg: 'The file has no timetable rows. The first line must be the column headings.' }); return { rows: 0, errors, tidy, warns, sessions: [] }; }
    const head = rows[0].cells.map(c => c.toLowerCase().replace(/[^a-z]/g, '')), col = {};
    for (const [k, al] of Object.entries(HDR)) col[k] = head.findIndex(h => al.includes(h));
    const miss = ['unit', 'date', 'start', 'end', 'venue'].filter(k => col[k] < 0);
    if (miss.length) { errors.push({ line: 1, msg: 'Missing column(s): ' + miss.join(', ') + '. Needed: Unit, Date, Start, End, Venue (Lecturer and Candidates are optional).' }); return { rows: 0, errors, tidy, warns, sessions: [] }; }
    const codes = Object.keys(P.units), items = [];
    for (const r of rows.slice(1)) {
      const g = (k) => col[k] < 0 ? '' : String(r.cells[col[k]] == null ? '' : r.cells[col[k]]).trim();
      const it = { line: r.line, ok: true }; const bad = (field, found, msg, suggest) => { it.ok = false; errors.push({ line: r.line, field, found, msg, suggest: suggest || null }); };
      const rawU = g('unit'), key = norm(rawU); const unit = codes.find(c => norm(c) === key);
      if (unit) { it.unit = unit; if (rawU !== unit) tidy.push(`Line ${r.line}: unit "${rawU}" written as ${unit}`); }
      else { const best = codes.map(c => [c, lev(norm(c), key)]).sort((a, b) => a[1] - b[1]); const sg = best[0][1] <= 2 && (!best[1] || best[1][1] > best[0][1]) ? best[0][0] : null;
        bad('unit', rawU, rawU ? `"${rawU}" is not a unit on the portal.` + (sg ? ` Did you mean ${sg}?` : '') : 'Unit is empty.', sg); }
      if (unit && opt.school && opt.school !== 'All schools' && TT_SCHOOL(unit) !== opt.school) bad('unit', rawU, `${unit} belongs to ${TT_SCHOOL(unit)}, not ${opt.school}. Only that school's coordinator can upload it.`);
      const rawD = g('date'), date = parseDate(rawD); if (!date) bad('date', rawD, `Date "${rawD}" is not understood. Use for example 03/11/2026 or 3 Nov 2026.`);
      else if (rawD !== date) tidy.push(`Line ${r.line}: date "${rawD}" read as ${date}`);
      const rawS = g('start'), rawE = g('end'), st = parseTime(rawS), en = parseTime(rawE);
      if (!st) bad('start', rawS, `Start time "${rawS}" is not understood. Use for example 09:00 or 2pm.`); if (!en) bad('end', rawE, `End time "${rawE}" is not understood. Use for example 12:00 or 5pm.`);
      if (st && en && date && iv(date, en) <= iv(date, st)) bad('end', rawE, 'End time must be after the start time.');
      const venue = g('venue').replace(/\s+/g, ' '); if (!venue) bad('venue', '', 'Venue is empty.');
      let cand = g('candidates'); cand = cand === '' ? null : Number(cand.replace(/[^0-9]/g, ''));
      if (it.ok) { Object.assign(it, { date, start: st, end: en, venue: venue.replace(/\b\w/g, c => c.toUpperCase()).replace(/\b(\d+)([a-z])\b/g, (m0, a, b) => a + b.toUpperCase()), vkey: norm(venue), cand });
        if (it.venue !== venue) tidy.push(`Line ${r.line}: venue "${venue}" written as ${it.venue}`);
        const lv = g('lecturer'); if (lv) for (const part of lv.split(/[;\/]/)) { const u = matchStaff(part); if (u) { (units[it.unit] = units[it.unit] || new Set()).add(u.email); if (part.trim() !== u.name) tidy.push(`Line ${r.line}: lecturer "${part.trim()}" matched to ${u.name}`); } else warns.push(`Line ${r.line}: lecturer "${part.trim()}" is not on the portal staff list, so the own-paper rule cannot be applied for them.`); }
        items.push(it); }
    }
    const byKey = {};
    for (const it of items) { const k = [it.date, it.start, it.end, it.vkey].join('|'); const s = byKey[k] = byKey[k] || { key: k, date: it.date, start: it.start, end: it.end, venue: it.venue, vkey: it.vkey, units: [], candidates: 0, lines: [] };
      if (s.units.includes(it.unit)) { warns.push(`Line ${it.line}: ${it.unit} appears twice for the same sitting; counted once.`); continue; }
      s.units.push(it.unit); s.lines.push(it.line); s.candidates += it.cand != null ? it.cand : regFor(it.unit).length; }
    const sessions = Object.values(byKey).sort((a, b) => a.date.localeCompare(b.date) || a.start.localeCompare(b.start) || a.venue.localeCompare(b.venue));
    for (const s of sessions) { s.opens_at = iso(iv(s.date, s.start)); s.closes_at = iso(iv(s.date, s.end)); s.school = (() => { const c = {}; s.units.forEach(u => { c[TT_SCHOOL(u)] = (c[TT_SCHOOL(u)] || 0) + 1; }); return Object.entries(c).sort((a, b) => b[1] - a[1])[0][0]; })(); s.need = Math.max(1, Math.ceil(s.candidates / opt.ratio)); s.lect = {}; s.units.forEach(u => { s.lect[u] = [...unitLecturers(u, units)]; }); }
    const ovl = (a, b) => a.opens_at < b.closes_at && b.opens_at < a.closes_at;
    sessions.forEach((a, i) => { sessions.slice(i + 1).forEach(b => { if (a.vkey === b.vkey && ovl(a, b)) errors.push({ line: a.lines[0], msg: `${a.venue} is double-booked: ${a.units.join(', ')} (${a.start}-${a.end}) overlaps ${b.units.join(', ')} (${b.start}-${b.end}) on ${a.date}.` }); }); });
    for (const s of sessions) for (const o of DB.sessions) if (o.status === 'active' && norm(o.venue) === s.vkey && o.opens_at < s.closes_at && s.opens_at < o.closes_at) errors.push({ line: s.lines[0], msg: `${s.venue} on ${s.date} ${s.start}-${s.end} clashes with an existing session already loaded (${o.units.join(', ')}).` });
    assignAll(sessions, opt);
    return { rows: items.length, errors, tidy, warns, sessions };
  }
  const BUF = 30 * 60e3;
  const busyOf = (email, sessions, skip) => {
    const out = []; for (const o of DB.sessions) if (o.status === 'active' && (o.supervisor_email === email || DB.access.some(a => a.sid === o.id && a.email === email && !a.revoked_at))) out.push([new Date(o.opens_at) - BUF, +new Date(o.closes_at), o.venue]);
    for (const s of sessions) if (s !== skip && ((s.chief === email) || (s.invs || []).includes(email))) out.push([new Date(s.opens_at) - BUF, +new Date(s.closes_at), s.venue]); return out;
  };
  const isFree = (email, s, sessions) => !busyOf(email, sessions, s).some(([a, b]) => a < +new Date(s.closes_at) && +new Date(s.opens_at) - BUF < b);
  const teachesHere = (email, s) => s.units.some(u => (s.lect[u] || []).includes(email));
  const loadOf = (email, sessions) => busyOf(email, sessions, null).length;
  function assignAll(sessions, opt) {
    sessions.forEach(s => { s.chief = null; s.invs = []; s.notes = []; });
    for (const s of [...sessions].sort((a, b) => a.opens_at.localeCompare(b.opens_at) || b.candidates - a.candidates)) {
      const score = (u, extra) => loadOf(u.email, sessions) + (u.school === s.school ? 0 : 1.5) + (extra || 0) + 0;
      const ok = (u) => isFree(u.email, s, sessions) && !teachesHere(u.email, s) && u.email !== s.chief && !s.invs.includes(u.email);
      const chiefs = pool().filter(u => u.role === 'supervisor' && ok(u)).sort((a, b) => score(a) - score(b) || a.name.localeCompare(b.name));
      if (chiefs[0]) { const ld = loadOf(chiefs[0].email, sessions); s.chief = chiefs[0].email; s.notes.push(`Chief: ${chiefs[0].name}, free at this time, ${ld} other sitting${ld === 1 ? '' : 's'} so far${chiefs[0].school === s.school ? ', same school' : ''}.`); }
      for (let i = 0; i < s.need; i++) { const c = pool().filter(ok).sort((a, b) => score(a, a.role === 'supervisor' ? .25 : 0) - score(b, b.role === 'supervisor' ? .25 : 0) || a.name.localeCompare(b.name))[0]; if (!c) break; s.invs.push(c.email); }
    }
    sessions.forEach(s => validateSession(s, sessions));
  }
  function validateSession(s, sessions) {
    s.problems = []; const seen = new Set();
    const chk = (email, label) => { if (!email) return; const u = P.staff.find(x => x.email === email); if (!u) return s.problems.push(`${label}: unknown staff account.`);
      if (seen.has(email)) s.problems.push(`${u.name} is listed twice.`); seen.add(email);
      if (!isFree(email, s, sessions)) s.problems.push(`${u.name} is already assigned to another sitting at this time.`);
      if (teachesHere(email, s)) s.problems.push(`${u.name} teaches a paper in this session and cannot invigilate it.`); };
    if (!s.chief) s.problems.push('No chief supervisor is free for this sitting. Choose one manually.'); else { const u = P.staff.find(x => x.email === s.chief); if (!u || u.role !== 'supervisor') s.problems.push('The chief must be a chief-supervisor account.'); chk(s.chief, 'Chief'); }
    s.invs.forEach(e => chk(e, 'Invigilator'));
    if (s.invs.length < s.need) s.problems.push(`Needs ${s.need} invigilator(s) for ${s.candidates} candidates; ${s.invs.length} assigned.`);
    return s.problems;
  }
  const impOut = (i, full) => { const base = { id: i.id, by: i.by_name, school: i.school, period: i.period, status: i.status, at: i.at, sessions: i.plan.sessions.length, errors: i.plan.errors.length, decided_by: i.decided_by || null, note: i.note || '', session_ids: i.session_ids || [] };
    if (!full) return base;
    const workload = {}; i.plan.sessions.forEach(s => [s.chief, ...s.invs].filter(Boolean).forEach(e => { workload[e] = (workload[e] || 0) + 1; }));
    return { ...base, ratio: i.ratio, late_after: i.late_after, csv: i.csv, plan: { ...i.plan, sessions: i.plan.sessions.map(s => ({ ...s, chief_name: s.chief ? P.staff.find(u => u.email === s.chief).name : null, inv_names: s.invs.map(e => P.staff.find(u => u.email === e).name), problems: s.problems })), workload: Object.entries(workload).map(([e, n]) => ({ email: e, name: P.staff.find(u => u.email === e).name, sittings: n })).sort((a, b) => b.sittings - a.sittings) },
      pool: pool().map(u => ({ email: u.email, name: u.name, role: u.role, school: u.school })) }; };
  const needTT = () => { if (!['exam_director', 'coordinator'].includes(user.role)) throw new Err(403, 'Only the exam director or a school exam coordinator can manage the timetable.'); };
  const getImp = (id) => { const i = DB.imports.find(x => x.id === Number(id)); if (!i) throw new Err(404, 'Timetable upload not found.'); if (user.role === 'coordinator' && i.by !== user.email) throw new Err(403, 'That upload belongs to another coordinator.'); return i; };

  const routes = [
    ['GET', /^\/timetable$/, async () => { needTT(); return [200, { school: user.school || 'All schools', schools: ['School of Technology', 'School of Business', 'School of Education'], role: user.role, ratio: 40, imports: [...DB.imports].reverse().filter(i => user.role === 'exam_director' || i.by === user.email).map(i => impOut(i)), chiefs: P.staff.filter(u => u.role === 'supervisor').map(u => ({ email: u.email, name: u.name })) }]; }],
    ['POST', /^\/timetable\/preview$/, async (m, b) => {
      needTT(); const school = user.role === 'coordinator' ? user.school : str(b, 'school', 60) || 'All schools', period = str(b, 'period', 80), ratio = Math.max(10, Math.min(200, Number(b.ratio) || 40)), late = Math.max(5, Math.min(180, Number(b.late_after) || 30));
      if (!period) throw new Err(422, 'Enter the exam period, for example Sem 2 2026, Main exams.');
      const csv = String(b.csv || ''); if (csv.length > 400000) throw new Err(413, 'That file is too large.'); if (!csv.trim()) throw new Err(422, 'Paste or choose a timetable file first.');
      DB.imports = DB.imports.filter(i => !(i.status === 'draft' && i.by === user.email));
      const imp = { id: ++DB.n.i, by: user.email, by_name: user.name, school, period, ratio, late_after: late, csv, status: 'draft', at: nowI(), plan: buildPlan(csv, { school, ratio }) };
      DB.imports.push(imp); return [200, { import: impOut(imp, true) }];
    }],
    ['GET', /^\/timetable\/(\d+)$/, async (m) => { needTT(); return [200, { import: impOut(getImp(m[1]), true) }]; }],
    ['POST', /^\/timetable\/(\d+)\/assign$/, async (m, b) => {
      needTT(); const imp = getImp(m[1]); if (!['draft', 'pending'].includes(imp.status)) throw new Err(409, 'This upload is already decided.');
      if (imp.status === 'pending' && user.role !== 'exam_director') throw new Err(403, 'Only the exam director can change a submitted timetable.');
      const s = imp.plan.sessions.find(x => x.key === b.key); if (!s) throw new Err(404, 'Sitting not found.');
      const u = b.email ? P.staff.find(x => x.email === b.email) : null; if (b.email && (!u || !['supervisor', 'invigilator'].includes(u.role))) throw new Err(422, 'Choose a staff account from the list.');
      if (b.slot === 'chief') s.chief = u ? u.email : null; else if (b.slot === 'inv') { if (u) s.invs[Number(b.index)] = u.email; else s.invs.splice(Number(b.index), 1); s.invs = s.invs.filter(Boolean); } else throw new Err(422, 'Unknown slot.');
      s.notes = (s.notes || []).filter(n => !n.startsWith('Chief:')); s.notes.push('Changed by ' + user.name + '.'); imp.plan.sessions.forEach(x => validateSession(x, imp.plan.sessions));
      await audit(user.email, 'timetable_assignment_changed', null, { import: imp.id, key: s.key, slot: b.slot, to: b.email || null }); return [200, { import: impOut(imp, true) }];
    }],
    ['POST', /^\/timetable\/(\d+)\/submit$/, async (m) => {
      needTT(); const imp = getImp(m[1]); if (imp.status !== 'draft') throw new Err(409, 'Already submitted.');
      if (imp.plan.errors.length) throw new Err(409, 'Fix the highlighted errors before submitting.'); if (imp.plan.sessions.some(s => s.problems.length)) throw new Err(409, 'Resolve the assignment problems before submitting.');
      imp.status = 'pending'; await audit(user.email, 'timetable_submitted', null, { import: imp.id, sittings: imp.plan.sessions.length }); return [200, { import: impOut(imp) }];
    }],
    ['POST', /^\/timetable\/(\d+)\/approve$/, async (m) => {
      if (user.role !== 'exam_director') throw new Err(403, 'Only the exam director can approve a timetable.'); const imp = getImp(m[1]);
      if (!['draft', 'pending'].includes(imp.status)) throw new Err(409, 'Already decided.');
      imp.plan.errors = buildPlan(imp.csv, { school: imp.school, ratio: imp.ratio }).errors; imp.plan.sessions.forEach(x => validateSession(x, imp.plan.sessions)); if (imp.plan.errors.length) throw new Err(409, 'Fix the highlighted errors first.'); if (imp.plan.sessions.some(s => s.problems.length)) throw new Err(409, 'Resolve the assignment problems first.');
      imp.session_ids = [];
      for (const s of imp.plan.sessions) {
        const ch = P.staff.find(u => u.email === s.chief);
        const n = { id: ++DB.n.s, code: 'S' + s.date.slice(2).replace(/-/g, '') + '-' + Math.random().toString(16).slice(2, 6).toUpperCase(), period: imp.period, venue: s.venue, opens_at: s.opens_at, closes_at: s.closes_at, supervisor_email: ch.email, supervisor_name: ch.name, status: 'active', offline: false, units: [...s.units], late_after: imp.late_after, source: 'timetable', import_id: imp.id };
        DB.sessions.push(n); imp.session_ids.push(n.id);
        for (const e of s.invs) { const u = P.staff.find(x => x.email === e); DB.access.push({ sid: n.id, email: u.email, name: u.name, by: 'timetable', at: nowI() }); }
        await audit(user.email, 'session_created', n.id, { period: n.period, venue: n.venue, from: 'timetable', import: imp.id });
      }
      imp.status = 'approved'; imp.decided_by = user.name; await audit(user.email, 'timetable_approved', null, { import: imp.id, sessions: imp.session_ids.length }); return [200, { import: impOut(imp), created: imp.session_ids.length }];
    }],
    ['POST', /^\/timetable\/(\d+)\/reject$/, async (m, b) => {
      if (user.role !== 'exam_director') throw new Err(403, 'Only the exam director can reject a timetable.'); const imp = getImp(m[1]); if (!['draft', 'pending'].includes(imp.status)) throw new Err(409, 'Already decided.');
      imp.status = 'rejected'; imp.decided_by = user.name; imp.note = str(b, 'note', 200); await audit(user.email, 'timetable_rejected', null, { import: imp.id, note: imp.note }); return [200, { import: impOut(imp) }];
    }],
    ['GET', /^\/config$/, async () => [200, { institution: 'KCA University', dev: true, portal: 'mock', mail_mode: 'demo', csrf: 'demo', user }]],
    ['POST', /^\/login$/, async (m, b) => {
      const email = str(b, 'email', 120).toLowerCase(), pw = String(b.password || '');
      if (!/^[^@\s]+@kcau\.ac\.ke$/.test(email)) throw new Err(422, 'Sign in with your KCA school email (name@kcau.ac.ke). Outside email addresses cannot be used.');
      if ((DB.fails[email] || 0) >= 5) throw new Err(429, 'Too many failed attempts. Wait 15 minutes and try again.');
      const u = staff(email);
      if (!u || u.password !== pw) { DB.fails[email] = (DB.fails[email] || 0) + 1; throw new Err(401, 'Incorrect email or password.'); }
      user = pubStaff(u); await audit(u.email, 'login', null, { role: u.role });
      return [200, { user, csrf: 'demo' }];
    }],
    ['POST', /^\/logout$/, async () => { user = null; return [200, { ok: true }]; }],
    ['GET', /^\/sessions$/, async () => {
      const out = [];
      for (const s of [...DB.sessions].reverse()) { try { const [r, l, o] = access(s.id); out.push(sessionOut(r, l, o)); } catch (e) { /* not visible to this user */ } }
      return [200, { sessions: out }];
    }],
    ['POST', /^\/sessions$/, async (m, b) => {
      if (user.role !== 'exam_director') throw new Err(403, 'Sessions come from the exam timetable. Only the exam director can add a one-off session.');
      const chief = staff(str(b, 'supervisor_email', 120)); if (!chief || chief.role !== 'supervisor') throw new Err(422, 'Choose a chief supervisor for this session.');
      const period = str(b, 'period', 80), venue = str(b, 'venue', 80), o = new Date(b.opens_at), c = new Date(b.closes_at);
      if (!period || !venue || isNaN(o) || isNaN(c)) throw new Err(422, 'Exam period, venue, opening and closing time are required.');
      if (c <= o) throw new Err(422, 'Closing time must be after opening time.');
      if (c - o > 86400e3) throw new Err(422, 'An exam session cannot last longer than 24 hours.');
      const units = Array.isArray(b.units) ? b.units.filter(x => P.units[x]) : []; if (!units.length) throw new Err(422, 'Choose at least one paper for this session.');
      const late = Math.max(5, Math.min(180, Number(b.late_after) || 30));
      const s = { id: ++DB.n.s, code: 'S' + new Date().toISOString().slice(2, 10).replace(/-/g, '') + '-' + Math.random().toString(16).slice(2, 6).toUpperCase(), period, venue, opens_at: iso(o), closes_at: iso(c), supervisor_email: chief.email, supervisor_name: chief.name, status: 'active', offline: false, units, late_after: late, source: 'manual' };
      DB.sessions.push(s); await audit(user.email, 'session_created', s.id, { period, venue, from: 'manual one-off' });
      const [r, l, op] = access(s.id); return [201, { session: sessionOut(r, l, op) }];
    }],
    ['POST', /^\/sessions\/(\d+)\/close$/, async (m) => {
      const [s, l] = access(m[1]); need(l, ['supervisor', 'director']);
      s.status = 'closed'; const n = nowI(); if (n < s.closes_at) s.closes_at = n; await audit(user.email, 'session_closed', s.id, {});
      let sealed = 0; for (const code of sUnits(s)) if (await makeSheet(s, code)) sealed++;
      return [200, { ok: true, sealed }];
    }],
    ['GET', /^\/sessions\/(\d+)\/access$/, async (m) => {
      const [s, l] = access(m[1]); need(l, ['supervisor', 'director']);
      return [200, { access: DB.access.filter(a => a.sid === s.id).map(a => ({ email: a.email, name: a.name, granted_by: a.by, granted_at: a.at, revoked_at: a.revoked_at || null })) }];
    }],
    ['POST', /^\/sessions\/(\d+)\/access$/, async (m, b) => {
      const [s, l] = access(m[1]); need(l, ['supervisor']);
      if (s.status !== 'active') throw new Err(409, 'This session is closed.');
      const u = staff(str(b, 'email', 120));
      if (!u || !['invigilator', 'supervisor'].includes(u.role)) throw new Err(404, 'No invigilator account with that email on the portal.');
      if (u.email === s.supervisor_email) throw new Err(409, 'You already have full access.');
      let a = DB.access.find(x => x.sid === s.id && x.email === u.email);
      if (a) { a.revoked_at = null; a.at = nowI(); } else DB.access.push({ sid: s.id, email: u.email, name: u.name, by: user.email, at: nowI() });
      await audit(user.email, 'access_granted', s.id, { to: u.email }); return [200, { ok: true, name: u.name }];
    }],
    ['DELETE', /^\/sessions\/(\d+)\/access\/([^/]+)$/, async (m) => {
      const [s, l] = access(m[1]); need(l, ['supervisor']);
      const a = DB.access.find(x => x.sid === s.id && x.email === decodeURIComponent(m[2]).toLowerCase()); if (a && !a.revoked_at) a.revoked_at = nowI();
      await audit(user.email, 'access_revoked', s.id, { from: decodeURIComponent(m[2]) }); return [200, { ok: true }];
    }],
    ['GET', /^\/sessions\/(\d+)\/students$/, async (m, b, q) => {
      const [ses] = access(m[1]); const qa = norm(q.get('q') || ''); if (qa.length < 6) return [200, { students: [] }];
      const key = user.email + ':' + ses.id, now = Date.now(); DB.misses[key] = (DB.misses[key] || []).filter(t => now - t < 120000);
      if (DB.misses[key].length >= 5) throw new Err(429, 'Search is locked after several wrong admission numbers. Call the invigilator to unlock it.', { locked: true });
      const hit = P.students.filter(s => norm(s.adm_no) === qa);
      if (hit.length) DB.misses[key] = []; else { DB.misses[key].push(now); if (DB.misses[key].length >= 5) await audit(user.email, 'search_locked', ses.id, {}); }
      return [200, { students: hit.map(s => ({ adm_no: s.adm_no, full_name: s.full_name, programme: s.programme, photo: photo(s) })) }];
    }],
    ['GET', /^\/sessions\/(\d+)\/students\/([^/]+)\/units$/, async (m) => {
      const [s] = access(m[1]); const st = student(decodeURIComponent(m[2])); if (!st) throw new Err(404, 'Student not found on the portal.');
      const pub = { adm_no: st.adm_no, full_name: st.full_name, programme: st.programme, photo: photo(st) };
      if (!st.cleared) return [200, { student: pub, cleared: false, reason: st.reason || 'Not cleared to sit exams', units: [] }];
      const now = nowI(), pp = phoneNorm(st.phone);
      const units = unitsFor(st).map(u => { const inS = sUnits(s).includes(u.code); const g = DB.sigs.find(x => (inS ? x.session_id === s.id : true) && x.adm_no === st.adm_no && x.unit_code === u.code);
        if (!inS) return { code: u.code, name: u.name, in_session: false, signed: !!g, signed_at: g ? g.signed_at : null, signature_id: null, version: null, can_edit: false };
        return { in_session: true, code: u.code, name: u.name, signed: !!g, signature_id: g ? g.id : null, signed_at: g ? g.signed_at : null, version: g ? g.version : null, email_status: g ? g.email_status : null, can_edit: g ? !!canEdit(s, g, now) : false }; });
      return [200, { student: pub, cleared: true, units, session_units: sUnits(s), prefill: { email: st.school_email.toLowerCase(), phone: pp, phone_from_portal: pp !== null } }];
    }],
    ['GET', /^\/sessions\/(\d+)\/signatures\/([^/]+)\/([^/]+)$/, async (m) => {
      const [s] = access(m[1]); const g = DB.sigs.find(x => x.session_id === s.id && x.adm_no === decodeURIComponent(m[2]) && x.unit_code === decodeURIComponent(m[3]));
      if (!g) throw new Err(404, 'Not signed yet.');
      return [200, { signature: { id: g.id, adm_no: g.adm_no, unit_code: g.unit_code, unit_name: g.unit_name, full_name: g.full_name, phone: g.phone, booklet_no: g.booklet_no, email: g.email, signature: g.signature, version: g.version, email_status: g.email_status, reopened_until: g.reopened_until || null, can_edit: !!canEdit(s, g, nowI()) } }];
    }],
    ['POST', /^\/sessions\/(\d+)\/signatures$/, async (m, b) => {
      const [s, l, open] = access(m[1]); need(l, ['supervisor', 'invigilator', 'director']);
      const adm = str(b, 'adm_no', 30), unitCode = str(b, 'unit_code', 30);
      const st = student(adm); if (!st) throw new Err(404, 'Student not found on the portal.');
      if (!st.cleared) throw new Err(422, 'This student is not cleared to sit exams: ' + (st.reason || 'see exam director') + '.');
      const unit = unitsFor(st).find(u => u.code === unitCode); if (!unit) throw new Err(422, 'The student is not cleared to sit that unit this semester.');
      if (!sUnits(s).includes(unitCode)) throw new Err(422, 'That paper is not part of this exam session (' + sUnits(s).join(', ') + '). Check the student is in the right hall.');
      let phone = phoneNorm(st.phone);
      if (!phone) { phone = phoneNorm(str(b, 'phone', 30)); if (!phone) throw new Err(422, 'The portal has no phone number for this student. Enter a valid Kenyan phone number, e.g. 0712 345 678.', { needs_phone: true }); }
      const booklet = str(b, 'booklet_no', 30).toUpperCase();
      if (!/^[A-Z0-9][A-Z0-9\-/ ]{2,19}$/.test(booklet)) throw new Err(422, 'Enter the booklet serial number exactly as printed (3-20 letters/digits).');
      const dupe = DB.sigs.find(x => x.session_id === s.id && x.booklet_no === booklet && !(x.adm_no === st.adm_no && x.unit_code === unitCode));
      if (dupe) throw new Err(409, 'That booklet serial number is already recorded for another student in this session. Check the number on the booklet, or call the invigilator.');
      const email = st.school_email.toLowerCase();
      if (!emailOk(email)) throw new Err(422, "The school email on this student's portal record is not an allowed KCA address. Ask the exam director to correct the portal record.");
      const isEdit0 = DB.sigs.some(x => x.session_id === s.id && x.adm_no === st.adm_no && x.unit_code === unitCode);
      const ident = b.ident == null ? null : String(b.ident);
      if (!isEdit0 && !IDENT[ident]) throw new Err(422, 'The invigilator must record how the student was identified (exam card and student ID, or photo only).');
      const queued = b.queued_at ? new Date(b.queued_at) : null;
      if (queued && !s.offline) throw new Err(409, 'Offline mode was not enabled for this session by the chief supervisor.');
      const sig = String(b.signature || '');
      if (!/^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(sig) || sig.length > 600000) throw new Err(422, 'Please sign in the signature box.');
      if (await ink(sig) < 25) throw new Err(422, 'The signature looks empty. Please sign in the signature box.');
      let now = nowI(); if (queued && !isNaN(queued) && iso(queued) <= now && iso(queued) >= s.opens_at) now = iso(queued);
      let g = DB.sigs.find(x => x.session_id === s.id && x.adm_no === st.adm_no && x.unit_code === unitCode); const edited = !!g;
      if (g) {
        if (!canEdit(s, g, now)) throw new Err(403, 'The exam period for this unit has closed. Ask the exam director to reopen your form.', { needs_reopen: true, signature_id: g.id });
        DB.hist.push({ id: g.id, version: g.version, booklet: g.booklet_no }); Object.assign(g, { phone, booklet_no: booklet, email, signature: sig, updated_at: now, version: g.version + 1, captured_by: user.email, email_status: 'pending', reopened_until: null });
      } else {
        if (!open) throw new Err(409, 'This exam session is not open for signing right now.');
        g = { id: ++DB.n.sig, session_id: s.id, adm_no: st.adm_no, unit_code: unitCode, unit_name: unit.name, full_name: st.full_name, phone, booklet_no: booklet, email, signature: sig, signed_at: now, updated_at: now, version: 1, captured_by: user.email, email_status: 'pending', ident, ident_note: ident === 'photo_only' ? str(b, 'ident_note', 120) : '', review: ident === 'photo_only' ? 'pending' : null, offline: !!queued };
        const prior = DB.incidents.find(i => i.adm_no === st.adm_no && i.unit === unitCode && i.status === 'resit_required' && !i.resit_sid && i.sid !== s.id);
        if (prior) { g.resit_of = prior.case_no; prior.resit_sid = s.id; prior.entries.push({ ts: now, by: 'system', text: 'Re-sit sign sheet issued: ' + s.period + ', ' + s.venue + '.', status: prior.status }); await audit('system', 'resit_linked', s.id, { case: prior.case_no, adm_no: st.adm_no }); }
        DB.sigs.push(g);
      }
      await audit(user.email, edited ? 'signature_corrected' : (queued ? 'signature_saved_offline' : 'signature_saved'), s.id, { adm_no: st.adm_no, unit: unitCode, booklet, version: g.version });
      await wait(250);
      return [200, { ok: true, edited, email: await deliver(g) }];
    }],
    ['GET', /^\/sessions\/(\d+)\/units$/, async (m) => {
      const [s] = access(m[1]); const out = [];
      for (const code of sUnits(s)) {
        const r = unitReport(s, code); if (!r.rows.length && !r.absent.length) continue;
        out.push({ code, name: P.units[code], registered: r.registered, signed: r.rows.length, absent: r.absent.length, email_problems: r.rows.filter(g => !['sent', 'outbox'].includes(g.email_status)).length, incidents: incidentsFor(s.id, code).length });
      }
      return [200, { units: out }];
    }],
    ['POST', /^\/signatures\/(\d+)\/resend$/, async (m) => {
      const g = DB.sigs.find(x => x.id === Number(m[1])); if (!g) throw new Err(404, 'Not found.'); access(g.session_id); return [200, { email: await deliver(g) }];
    }],
    ['POST', /^\/signatures\/(\d+)\/reopen-request$/, async (m, b) => {
      const g = DB.sigs.find(x => x.id === Number(m[1])); if (!g) throw new Err(404, 'Not found.');
      const [, l] = access(g.session_id); need(l, ['supervisor', 'invigilator']);
      const reason = str(b, 'reason', 300); if (reason.length < 10) throw new Err(422, 'Explain what needs correcting (at least 10 characters).');
      if (DB.reopen.some(r => r.sigId === g.id && r.status === 'pending')) throw new Err(409, 'A request for this form is already waiting for the exam director.');
      DB.reopen.push({ id: ++DB.n.req, sigId: g.id, requested_by: user.email, reason, status: 'pending', requested_at: nowI() });
      await audit(user.email, 'reopen_requested', g.session_id, { signature_id: g.id, reason }); return [201, { ok: true }];
    }],
    ['GET', /^\/reopen-requests$/, async () => {
      if (user.role !== 'exam_director') throw new Err(403, 'Only the exam director can see these.');
      return [200, { requests: [...DB.reopen].sort((a, b) => (b.status === 'pending') - (a.status === 'pending') || b.id - a.id).map(r => { const g = DB.sigs.find(x => x.id === r.sigId), s = DB.sessions.find(x => x.id === g.session_id);
        return { id: r.id, reason: r.reason, status: r.status, requested_by: r.requested_by, requested_at: r.requested_at, full_name: g.full_name, adm_no: g.adm_no, unit_code: g.unit_code, unit_name: g.unit_name, version: g.version, venue: s.venue, period: s.period }; }) }];
    }],
    ['POST', /^\/reopen-requests\/(\d+)\/(approve|reject)$/, async (m, b) => {
      if (user.role !== 'exam_director') throw new Err(403, 'Only the exam director can do that.');
      const r = DB.reopen.find(x => x.id === Number(m[1]) && x.status === 'pending'); if (!r) throw new Err(404, 'No pending request with that number.');
      r.status = m[2] === 'approve' ? 'approved' : 'rejected'; let mins = 0;
      const g = DB.sigs.find(x => x.id === r.sigId);
      if (m[2] === 'approve') { mins = Math.max(5, Math.min(240, Number(b.minutes) || 30)); g.reopened_until = iso(Date.now() + mins * 60e3); }
      await audit(user.email, 'reopen_' + m[2] + 'd', g.session_id, { request: r.id, minutes: mins }); return [200, { ok: true, minutes: mins }];
    }],
    ['GET', /^\/portal\/units$/, async () => { if (user.role !== 'exam_director') throw new Err(403, 'Only the exam director can add a one-off session.'); return [200, { units: Object.entries(P.units).map(([code, name]) => ({ code, name })) }]; }],
    ['POST', /^\/sessions\/(\d+)\/lookups\/reset$/, async (m) => { const [s] = access(m[1]); DB.misses[user.email + ':' + s.id] = []; await audit(user.email, 'search_unlocked', s.id, {}); return [200, { ok: true }]; }],
    ['GET', /^\/sessions\/(\d+)\/rollcall$/, async (m) => {
      const [s, l] = access(m[1]); need(l, ['supervisor', 'invigilator', 'director']);
      return [200, { updated_at: nowI(), units: sUnits(s).map(code => { const r = unitReport(s, code); return { code, name: P.units[code], registered: r.registered, signed: r.rows.length,
        pending: r.absent.map(a => { const st = student(a.adm_no); return { adm_no: a.adm_no, full_name: a.full_name, photo: photo(st), state: a.state, reason: a.reason }; }),
        present: r.rows.map(g => ({ id: g.id, adm_no: g.adm_no, full_name: g.full_name, signed_at: g.signed_at, exit_at: g.exit_at || null, exit_note: g.exit_note || '' })) }; }) }];
    }],
    ['POST', /^\/signatures\/(\d+)\/exit$/, async (m, b) => {
      const g = DB.sigs.find(x => x.id === Number(m[1])); if (!g) throw new Err(404, 'Not found.'); const [, l] = access(g.session_id); need(l, ['supervisor', 'invigilator', 'director']);
      if (g.exit_at) throw new Err(409, 'An exit is already recorded for this student.');
      g.exit_at = nowI(); g.exit_note = str(b, 'note', 80); await audit(user.email, 'exit_recorded', g.session_id, { signature_id: g.id, adm_no: g.adm_no, note: g.exit_note }); return [200, { ok: true, exit_at: g.exit_at }];
    }],
    ['POST', /^\/sessions\/(\d+)\/pack$/, async (m) => { const [s, l] = access(m[1]); need(l, ['supervisor', 'director']); const d = await makePack(s); return [200, { code: d.code }]; }],
    ['POST', /^\/sessions\/(\d+)\/offline$/, async (m, b) => {
      const [s, l] = access(m[1]); need(l, ['supervisor']);
      s.offline = !!b.on; await audit(user.email, s.offline ? 'offline_enabled' : 'offline_disabled', s.id, {}); return [200, { ok: true, offline: s.offline }];
    }],
    ['GET', /^\/sessions\/(\d+)\/offline-pack$/, async (m) => {
      const [s] = access(m[1]); if (!s.offline) throw new Err(409, 'Offline mode is not enabled for this session.');
      return [200, { sid: s.id, students: P.students.map(st => { const pp = phoneNorm(st.phone); return { adm_no: st.adm_no, full_name: st.full_name, programme: st.programme, photo: photo(st), cleared: st.cleared, reason: st.reason || null, units: unitsFor(st).map(u => ({ ...u, in_session: sUnits(s).includes(u.code) })),
        prefill: { email: st.school_email.toLowerCase(), phone: pp, phone_from_portal: pp !== null } }; }) }];
    }],
    ['GET', /^\/sessions\/(\d+)\/incidents$/, async (m) => {
      const [s, l] = access(m[1]);
      const flags = DB.flags.filter(f => f.sid === s.id && (l !== 'invigilator' || f.by === user.email));
      if (l === 'invigilator') return [200, { level: l, flags, incidents: [], reviews: [] }];
      return [200, { level: l, flags, incidents: incidentsFor(s.id), statuses: STATUSES, categories: CATS,
        reviews: DB.sigs.filter(g => g.session_id === s.id && g.review).map(g => ({ id: g.id, full_name: g.full_name, adm_no: g.adm_no, unit_code: g.unit_code, note: g.ident_note, review: g.review, reviewed_by: g.reviewed_by || null })) }];
    }],
    ['POST', /^\/sessions\/(\d+)\/flags$/, async (m, b) => {
      const [s, l] = access(m[1]); need(l, ['supervisor', 'invigilator']);
      const st = student(str(b, 'adm_no', 30)); if (!st) throw new Err(404, 'Student not found on the portal.');
      const text = str(b, 'text', 300); if (text.length < 10) throw new Err(422, 'Describe what you saw (at least 10 characters).');
      const f = { id: ++DB.n.flag, sid: s.id, adm_no: st.adm_no, full_name: st.full_name, unit: str(b, 'unit_code', 30), text, by: user.email, at: nowI(), status: 'open' };
      DB.flags.push(f); await audit(user.email, 'incident_flagged', s.id, { adm_no: st.adm_no, unit: f.unit }); return [201, { ok: true, flag: f }];
    }],
    ['POST', /^\/sessions\/(\d+)\/incidents$/, async (m, b) => {
      const [s, l] = access(m[1]);
      if (l === 'invigilator') throw new Err(403, 'Only the chief supervisor can record an incident. Use "Flag for supervisor" instead.');
      need(l, ['supervisor']);
      const st = student(str(b, 'adm_no', 30)); if (!st) throw new Err(404, 'Student not found on the portal.');
      const unit = str(b, 'unit_code', 30); if (!regFor(unit).some(x => x.adm_no === st.adm_no)) throw new Err(422, 'That student is not registered for ' + (unit || 'that unit') + '.');
      const cat = str(b, 'category', 60); if (!CATS.includes(cat)) throw new Err(422, 'Choose an incident category.');
      const text = str(b, 'text', 500); if (text.length < 10) throw new Err(422, 'Write what was seen (at least 10 characters).');
      const inc = { id: ++DB.n.inc, case_no: 'INC-' + new Date().getFullYear() + '-' + String(DB.n.inc).padStart(4, '0'), sid: s.id, adm_no: st.adm_no, full_name: st.full_name, unit, category: cat, status: 'open', opened_by: user.name, opened_at: nowI(), entries: [{ ts: nowI(), by: user.name, text, status: 'open' }], resit_sid: null };
      DB.incidents.push(inc); if (b.flag_id) { const f = DB.flags.find(x => x.id === Number(b.flag_id)); if (f) f.status = 'recorded'; }
      await audit(user.email, 'incident_recorded', s.id, { case: inc.case_no, adm_no: st.adm_no, unit }); return [201, { ok: true, incident: inc }];
    }],
    ['POST', /^\/incidents\/(\d+)\/update$/, async (m, b) => {
      const inc = DB.incidents.find(x => x.id === Number(m[1])); if (!inc) throw new Err(404, 'Case not found.');
      const [, l] = access(inc.sid); if (l === 'invigilator') throw new Err(403, 'Only the chief supervisor can update an incident.'); need(l, ['supervisor']);
      const status = str(b, 'status', 30), text = str(b, 'text', 500); if (!STATUSES[status]) throw new Err(422, 'Choose an outcome.'); if (text.length < 5) throw new Err(422, 'Add a short note (at least 5 characters).');
      inc.status = status; inc.entries.push({ ts: nowI(), by: user.name, text, status }); await audit(user.email, 'incident_updated', inc.sid, { case: inc.case_no, status }); return [200, { ok: true }];
    }],
    ['POST', /^\/signatures\/(\d+)\/review$/, async (m) => {
      const g = DB.sigs.find(x => x.id === Number(m[1])); if (!g) throw new Err(404, 'Not found.'); const [, l] = access(g.session_id); need(l, ['supervisor']);
      g.review = 'reviewed'; g.reviewed_by = user.name; await audit(user.email, 'identity_reviewed', g.session_id, { signature_id: g.id }); return [200, { ok: true }];
    }],
    ['GET', /^\/records$/, async () => {
      need(user.role === 'exam_director' ? 'director' : DB.sessions.some(s => s.supervisor_email === user.email) ? 'supervisor' : 'x', ['director', 'supervisor']);
      const mine = (d) => user.role === 'exam_director' || (DB.sessions.find(s => s.id === d.sid) || {}).supervisor_email === user.email;
      return [200, { records: DB.docs.filter(mine).map(d => ({ code: d.code, kind: d.kind, unit: d.unit, unit_name: d.unit_name, period: d.period, venue: d.venue, issued_at: d.issued_at, status: docStatus(d), student: d.kind === 'receipt' ? d.adm_no : null, entries: d.entries || null })).reverse() }];
    }],
    ['GET', /^\/director\/security$/, async () => {
      if (user.role !== 'exam_director') throw new Err(403, 'Only the exam director can see this.');
      return [200, { name: DB.dir.name, signature: DB.dir.sig, signature_at: DB.dir.at, fingerprint: DB.keyfp, uses_total: DB.keyuse.length, uses: [...DB.keyuse].reverse().slice(0, 8),
        custodians: ['Exam director', 'ICT security officer (KCA)'], retention: 'Set by KCA’s data protection policy and applied by KCA ICT. Records are owned by the school.' }];
    }],
    ['POST', /^\/director\/signature$/, async (m, b) => {
      if (user.role !== 'exam_director') throw new Err(403, 'Only the exam director can do that.');
      const sig = String(b.signature || ''); if (!/^data:image\/(png|jpeg|svg\+xml)[;,]/.test(sig) || sig.length > 400000) throw new Err(422, 'Upload a PNG or JPG image of the signature (under 300 KB).');
      DB.dir.sig = sig; DB.dir.at = nowI(); await audit(user.email, 'director_signature_updated', null, {}); return [200, { ok: true }];
    }],
    ['GET', /^\/audit\/events$/, async () => {
      if (user.role !== 'exam_director') throw new Err(403, 'Only the exam director can see this.');
      const L = { login: ['Staff sign-in', 'info'], signature_saved: ['Signature recorded', 'ok'], signature_saved_offline: ['Signature synced from offline', 'ok'], signature_corrected: ['Signature corrected', 'warn'], receipt_sent: ['Sealed copy emailed', 'ok'], receipt_email_failed: ['Email copy failed', 'bad'],
        sheet_sealed: ['Unit sheet sealed', 'ok'], sheet_downloaded: ['Sheet opened', 'info'], session_created: ['Session loaded', 'info'], session_closed: ['Session closed', 'warn'], access_granted: ['Invigilator access granted', 'info'], access_revoked: ['Invigilator access revoked', 'warn'],
        incident_recorded: ['Incident recorded', 'bad'], incident_updated: ['Incident updated', 'warn'], incident_flagged: ['Invigilator flagged a student', 'warn'], identity_reviewed: ['Identity check reviewed', 'info'], resit_linked: ['Re-sit linked to earlier case', 'info'],
        reopen_requested: ['Reopen requested', 'warn'], reopen_approved: ['Form reopened by director', 'warn'], reopen_rejected: ['Reopen declined', 'info'], offline_enabled: ['Offline mode switched on', 'warn'], offline_disabled: ['Offline mode switched off', 'info'],
        record_viewed: ['Stored record opened', 'info'], pack_sealed: ['Session pack sealed', 'ok'], exit_recorded: ['Student exit recorded', 'info'], search_locked: ['Student search locked (wrong numbers)', 'warn'], search_unlocked: ['Student search unlocked by staff', 'info'], director_signature_updated: ['Director signature changed', 'warn'] };
      const ev = [...DB.audit].reverse().slice(0, 14).map(r => { const l = L[r.action] || [r.action.replace(/_/g, ' '), 'info']; let d = ''; try { const j = JSON.parse(r.json); d = j.adm_no || j.unit || j.case || j.to || j.from || ''; } catch (e) { d = ''; } return { ts: r.ts, actor: r.actor, label: l[0], kind: l[1], detail: d }; });
      return [200, { events: ev, chain: await auditVerify() }];
    }],
    ['GET', /^\/audit\/verify$/, async () => { if (user.role !== 'exam_director') throw new Err(403, 'Only the exam director can run this check.'); return [200, await auditVerify()]; }],
    ['POST', /^\/verify$/, async (m, b) => {
      if (b instanceof Blob) return [200, { found: false, valid: false, message: 'Checking an uploaded PDF needs the real server. In this demo, verify by code: sign something, then copy the code from the email preview.' }];
      const code = str(b, 'code', 40).toUpperCase();
      if (!/^KCA-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/.test(code)) throw new Err(422, 'Codes look like KCA-ABCD-EFGH-JKLM.');
      const d = DB.docs.find(x => x.code === code);
      if (!d) return [200, { found: false, valid: false, message: 'No document with that code was issued by this system.' }];
      const status = docStatus(d);
      const out = { found: true, valid: true, record_intact: true, file_matches: true, status, code: d.code, kind: d.kind, issued_at: d.issued_at, period: d.period, venue: d.venue, unit: d.unit + ' ' + d.unit_name,
        message: status === 'superseded' ? 'Authentic and unaltered, but a newer version of this record has been issued since.' : 'Authentic and unaltered.' };
      if (d.kind === 'receipt') { out.student = d.adm_no.slice(0, 4) + '***'; out.version = d.version; } else out.entries = d.entries;
      return [200, out];
    }],
  ];

  window.fetch = async (url, opt = {}) => {
    await wait(80);
    if (netOff && !String(url).endsWith('/config')) throw new TypeError('Failed to fetch');
    const u = new URL(String(url), 'http://demo'); const path = u.pathname.replace(/^\/api/, '');
    let body = {}; if (opt.body) body = opt.body instanceof Blob ? opt.body : JSON.parse(opt.body);
    const reply = (st, data) => ({ ok: st < 400, status: st, json: async () => data });
    try {
      for (const [method, re, fn] of routes) {
        if (method !== (opt.method || 'GET')) continue;
        const m = path.match(re); if (!m) continue;
        if (!user && !['/config', '/login', '/verify'].includes(path)) return reply(401, { error: 'Please sign in again.' });
        const [st, data] = await fn(m, body, u.searchParams); return reply(st, data);
      }
      return reply(404, { error: 'Not found' });
    } catch (e) { return reply(e.status || 500, Object.assign({ error: e.status ? e.message : 'Demo error: ' + e.message }, e.data || {})); }
  };

  // ------------------------------------------------------------------ demo UI: previews, confirm, banner
  const el = (t, a, ...k) => { const e = document.createElement(t); for (const [n, v] of Object.entries(a || {})) { if (n === 'class') e.className = v; else if (n.startsWith('on')) e.addEventListener(n.slice(2), v); else if (v !== false && v != null) e.setAttribute(n, v === true ? '' : v); } for (const c of k.flat()) if (c != null && c !== false) e.append(c.nodeType ? c : document.createTextNode(String(c))); return e; };
  const fmt = (i, o) => new Intl.DateTimeFormat('en-KE', Object.assign({ timeZone: 'Africa/Nairobi', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }, o)).format(new Date(i));
  function overlay(title, ...content) {
    const close = () => { ov.remove(); document.body.style.overflow = ''; };
    const ov = el('div', { class: 'ov', role: 'dialog', 'aria-modal': 'true', 'aria-label': title, onclick: (e) => { if (e.target === ov) close(); } },
      el('div', { class: 'ovbox' }, el('div', { class: 'ovbar' }, el('b', {}, title), el('button', { class: 'btn sm', onclick: close }, 'Close')), ...content));
    document.body.append(ov); document.body.style.overflow = 'hidden'; return close;
  }
  const pattern = (fp, px) => {
    const NS = 'http://www.w3.org/2000/svg', svg = document.createElementNS(NS, 'svg'); svg.setAttribute('viewBox', '0 0 5 5'); svg.setAttribute('width', px || 54); svg.setAttribute('height', px || 54); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'Security pattern derived from the school seal key');
    const hue = parseInt(fp.slice(0, 3), 16) % 360, bits = parseInt(fp.slice(3, 8), 16); let i = 0;
    for (let x = 0; x < 3; x++) for (let y = 0; y < 5; y++) if ((bits >> i++) & 1) for (const xx of x === 2 ? [2] : [x, 4 - x]) { const r = document.createElementNS(NS, 'rect'); r.setAttribute('x', xx); r.setAttribute('y', y); r.setAttribute('width', 1); r.setAttribute('height', 1); r.setAttribute('fill', `hsl(${hue},55%,30%)`); svg.append(r); }
    return svg;
  };
  const seal = (code, hash, sig, snap) => el('div', { class: 'seal' }, el('b', {}, 'DOCUMENT SEAL'),
    el('div', { class: 'sealg' }, el('div', {}, el('small', {}, 'Verification code'), el('div', { class: 'code' }, code)), el('div', {}, el('small', {}, 'Verify at'), el('div', {}, location.origin + '/#verify'))),
    el('small', {}, 'SHA-256 fingerprint of the sealed record'), el('div', { class: 'mono' }, hash.slice(0, 32), el('br'), hash.slice(32)),
    el('small', {}, 'Digital signature (RSA-2048 / SHA-256)'), el('div', { class: 'mono' }, sig.slice(0, 34), el('br'), sig.slice(34) + '...'),
    snap ? el('div', { class: 'auth' }, el('div', { class: 'authsig' }, el('small', {}, 'ISSUED UNDER THE AUTHORITY OF THE EXAM DIRECTOR'), snap.dir.sig ? el('img', { src: snap.dir.sig, alt: 'Exam director signature', class: 'psig dirsig' }) : el('div', { class: 'pm' }, '(signature not uploaded)'), el('div', {}, snap.dir.name)),
      el('div', { class: 'authpat' }, pattern(snap.fp), el('small', {}, 'SECURITY PATTERN'))) : null);
  const paperHead = (sub) => el('div', { class: 'phead' }, el('div', { class: 'plogo' }, el('img', { src: window.KCA_LOGO, alt: '' })), el('div', {}, el('b', {}, 'KCA UNIVERSITY'), el('div', {}, sub)), el('b', { class: 'pr' }, 'e-Sign Sheet'));
  const sigImg = (src) => el('img', { src, alt: 'Signature', class: 'psig' });

  function receiptPaper(doc) {
    const g = doc.snap.sig, s = DB.sessions.find(x => x.id === g.session_id);
    return el('div', { class: 'paper' }, paperHead('Examination Attendance Receipt'), el('div', { class: 'pbody' },
      el('h3', {}, 'Examination attendance receipt'), el('p', { class: 'pm' }, "Electronic sign sheet record. Student copy, emailed to the student's KCA school email."),
      el('div', { class: 'pgrid' }, ...[['Student', g.full_name], ['Admission number', g.adm_no], ['Unit', g.unit_code + '  ' + g.unit_name], ['Exam period', s.period], ['Venue', s.venue], ['Signed at', fmt(g.signed_at) + ' EAT'],
        ['Booklet serial number', g.booklet_no], ['Phone number', g.phone], ['School email', g.email], ['Identity check', IDENT[g.ident] || 'Recorded'], ['Record version', 'v' + g.version + (g.version > 1 ? ' (corrected copy)' : '')]].map(([l, v]) => el('div', {}, el('small', {}, l.toUpperCase()), el('div', {}, v)))),
      el('small', {}, 'STUDENT SIGNATURE'), el('div', { class: 'pbox' }, sigImg(g.signature)), seal(doc.code, doc.hash, doc.rsa, doc.snap)));
  }
  function sheetPaper(doc) {
    const sn = doc.snap, rows = sn.rows, s = DB.sessions.find(x => x.id === doc.sid);
    const flagFor = (r) => sn.incidents.find(i => i.adm_no === r.adm_no);
    const t = el('table', {}, el('thead', {}, el('tr', {}, ...['No.', 'Adm. no.', 'Name', 'Booklet no.', 'Phone', 'Time', 'Signature'].map(x => el('th', {}, x)))),
      el('tbody', {}, ...rows.map((r, i) => { const inc = flagFor(r);
        return el('tr', {}, el('td', {}, i + 1), el('td', {}, r.adm_no), el('td', {}, r.full_name,
          r.ident === 'photo_only' ? el('div', { class: 'tag' }, 'ID: photo only' + (r.review === 'reviewed' ? ' (reviewed by ' + r.reviewed_by + ')' : ' (review pending)')) : null,
          r.resit_of ? el('div', { class: 'tag' }, 'RE-SIT · prior case ' + r.resit_of) : null,
          inc ? el('div', { class: 'tag red' }, '⚑ INCIDENT ' + inc.case_no + ' · ' + STATUSES[inc.status]) : null,
          r.offline ? el('div', { class: 'tag' }, 'Signed offline, synced later') : null,
          r.signed_at > iso(new Date(sn.opens_at).getTime() + sn.late_after * 60000) ? el('div', { class: 'tag' }, 'LATE ARRIVAL (more than ' + sn.late_after + ' min after start)') : null,
          r.exit_at ? el('div', { class: 'tag' }, 'LEFT ' + fmt(r.exit_at, { day: undefined, month: undefined, year: undefined }) + (r.exit_note ? ' · ' + r.exit_note : '')) : null),
          el('td', {}, r.booklet_no), el('td', {}, r.phone), el('td', {}, fmt(r.signed_at, { day: undefined, month: undefined, year: undefined })), el('td', {}, sigImg(r.signature))); })));
    return el('div', { class: 'paper' }, paperHead('Examination Attendance Sheet'), el('div', { class: 'pbody' }, el('h3', {}, doc.unit + '  ' + doc.unit_name),
      el('div', { class: 'pgrid' }, ...[['Exam period', s.period], ['Venue', s.venue], ['Chief supervisor', sn.supervisor], ['Registered', rows.length + sn.absent.length], ['Signed', rows.length], ['Did not sign', sn.absent.length]].map(([l, v]) => el('div', {}, el('small', {}, l.toUpperCase()), el('div', {}, String(v))))),
      rows.length ? el('div', { class: 'tw', tabindex: '0', role: 'region', 'aria-label': 'Signatures table, scrolls sideways' }, t) : el('p', { class: 'pm' }, 'No signatures were collected for this unit.'),
      sn.absent.length ? el('div', { class: 'sec' }, el('b', {}, 'Registered but did not sign'), ...sn.absent.map(a => el('div', { class: 'ab' }, a.adm_no + '  ' + a.full_name, ' · ', a.state === 'absent' ? 'Absent / did not sign' : 'Not cleared: ' + a.reason))) : null,
      sn.incidents.length ? el('div', { class: 'sec' }, el('b', {}, 'Incident notes (recorded by the chief supervisor)'), ...sn.incidents.map(i => el('div', { class: 'inc' },
        el('div', {}, el('b', {}, i.case_no), ' · ' + i.adm_no + ' ' + i.full_name + ' · ' + i.category + ' · ', el('b', {}, STATUSES[i.status])),
        ...i.entries.map(e => el('div', { class: 'pm' }, fmt(e.ts) + ' · ' + e.by + ': ' + e.text)), i.resit_sid ? el('div', { class: 'pm' }, 'Re-sit sheet linked to this case.') : null))) : null,
      seal(doc.code, doc.hash, doc.rsa, sn)));
  }
  function packPaper(doc) {
    const sn = doc.snap, T = sn.totals, s = DB.sessions.find(x => x.id === doc.sid);
    const tbl = el('table', {}, el('thead', {}, el('tr', {}, ...['Paper', 'Registered', 'Signed', 'Did not sign', 'Sheet code'].map(x => el('th', {}, x)))),
      el('tbody', {}, ...sn.rows.map(r => el('tr', {}, el('td', {}, r.unit + '  ' + r.name), el('td', {}, r.registered), el('td', {}, r.signed), el('td', {}, r.absent), el('td', { class: 'mono' }, r.code)))));
    return el('div', { class: 'paper' }, paperHead('Examination Session Pack'), el('div', { class: 'pbody' }, el('h3', {}, s.period + ' · ' + s.venue),
      el('div', { class: 'pgrid' }, ...[['Chief supervisor', sn.supervisor], ['Opened', fmt(sn.opens_at)], ['Closed', sn.status === 'closed' ? fmt(sn.closes_at) : 'Not closed yet'], ['Signatures', T.signed], ['Late arrivals', T.late], ['Exits recorded', T.exits], ['Signed offline', T.offline], ['Corrections', T.corrections], ['Incident cases', T.incidents], ['Photo-only identity checks', T.photo_only + ' (' + T.review_pending + ' awaiting review)'], ['Audit chain', sn.chain.ok ? 'Intact, ' + sn.chain.entries + ' entries' : 'BROKEN']].map(([l, v]) => el('div', {}, el('small', {}, l.toUpperCase()), el('div', {}, String(v))))),
      el('div', { class: 'tw', tabindex: '0', role: 'region', 'aria-label': 'Papers table' }, tbl), el('p', { class: 'pm' }, 'Each paper has its own sealed sign sheet, listed above by code and stored in the school records.'), seal(doc.code, doc.hash, doc.rsa, sn)));
  }
  async function openPack(sid) {
    const s = DB.sessions.find(x => x.id === Number(sid)); const doc = await makePack(s);
    await audit(user.email, 'pack_downloaded', s.id, { doc: doc.code });
    overlay('Sealed session pack preview', el('p', { class: 'note' }, 'The real system produces one PDF: this cover page followed by every unit sheet. The cover is shown here; each sheet opens from Records.'), packPaper(doc));
  }
  async function showEmail(code) {
    const doc = DB.docs.find(d => d.code === code); if (!doc) return;
    const g = doc.snap.sig, s = DB.sessions.find(x => x.id === g.session_id);
    const body = `Dear ${g.full_name},\n\nThis is your copy of the electronic exam sign sheet entry for ${g.unit_code} ${g.unit_name} (${s.period}, ${s.venue}), signed on ${fmt(g.signed_at)} EAT.\n\n`
      + (g.version > 1 ? `This is a CORRECTED copy (version ${g.version}). It replaces earlier copies.\n\n` : '')
      + `Please keep this email. If the school's record is ever lost or disputed, the attached PDF is your proof of attendance. The school also keeps the sealed original.\n\nVerification code: ${doc.code}\n\nKCA University. This is an automated message; do not reply.`;
    overlay('Email copy sent to the student (simulated)',
      el('div', { class: 'mail' }, el('div', {}, el('small', {}, 'From'), ' KCA University e-Sign Sheet <esign@kcau.ac.ke>'), el('div', {}, el('small', {}, 'To'), ' ' + g.email),
        el('div', {}, el('small', {}, 'Subject'), ` KCA exam attendance copy: ${g.unit_code} (${g.adm_no})`), el('pre', {}, body), el('div', { class: 'att' }, 'Attachment: KCA-attendance-' + g.unit_code.replace(/\W/g, '') + '-' + doc.code + '.pdf')),
      el('p', { class: 'note' }, 'Nothing was actually emailed. This shows what the student receives; the PDF is previewed below. The sealed original is also stored in the school records.'), receiptPaper(doc));
  }
  async function openSheet(sid, unitCode) {
    const s = DB.sessions.find(x => x.id === Number(sid)); const doc = await makeSheet(s, unitCode); if (!doc) return;
    await audit(user.email, 'sheet_downloaded', s.id, { unit: unitCode, doc: doc.code });
    overlay('Sealed sign sheet preview', el('p', { class: 'note' }, 'The real system produces this as a downloadable, digitally sealed PDF. This is a preview of its content.'), sheetPaper(doc));
  }
  async function openDoc(code) {
    const doc = DB.docs.find(d => d.code === code); if (!doc) return;
    await audit(user.email, 'record_viewed', doc.sid, { doc: code });
    overlay('Stored sealed original', el('p', { class: 'note' }, 'Opened from the school records. This view is logged. Status: ' + (docStatus(doc) === 'superseded' ? 'superseded by a newer version (kept for the record).' : 'current.')), doc.kind === 'sheet' ? sheetPaper(doc) : doc.kind === 'pack' ? packPaper(doc) : receiptPaper(doc));
  }
  function confirmBox(msg, yes, no) {
    return new Promise((res) => {
      const ov = el('div', { class: 'ov', role: 'alertdialog', 'aria-modal': 'true' }, el('div', { class: 'ovbox sm' }, el('p', {}, msg),
        el('div', { class: 'acts' }, el('button', { class: 'btn danger', onclick: () => { ov.remove(); res(true); } }, yes || 'Yes, close it'), el('button', { class: 'btn', onclick: () => { ov.remove(); res(false); } }, no || 'Keep it open'))));
      document.body.append(ov);
    });
  }
  function banner() {
    const msg = el('span', { class: 'dmsg', 'aria-live': 'polite' });
    const say = (t) => { msg.textContent = t; };
    const quick = (email, pw) => () => { const em = document.getElementById('em'), pwi = document.getElementById('pw'); if (!em) return say('Sign out first, then pick a role.'); em.value = email; pwi.value = pw; em.closest('form').dispatchEvent(new Event('submit', { cancelable: true })); say(''); };
    return el('div', { id: 'demobar' }, el('div', { class: 'dbin' }, el('b', {}, 'DEMO'), el('span', {}, 'Concept demo for KCA University, not an official KCA product. Simulated server; resets on reload.'),
      el('span', { class: 'dq' }, 'Sign in as:', el('button', { class: 'dbtn', onclick: quick('supervisor@kcau.ac.ke', 'Demo#Super1') }, 'Chief supervisor'), el('button', { class: 'dbtn', onclick: quick('invigilator1@kcau.ac.ke', 'Demo#Invig1') }, 'Invigilator'), el('button', { class: 'dbtn', onclick: quick('director@kcau.ac.ke', 'Demo#Direct1') }, 'Exam director'), el('button', { class: 'dbtn', onclick: quick('coordinator@kcau.ac.ke', 'Demo#Coord1') }, 'School coordinator')),
      el('button', { class: 'dbtn', onclick: () => { if (DB.audit.length < 2) return say('Do something first (sign in, sign a unit), then tamper.'); DB.audit[1].json = '{"forged":true}'; say('Audit row 2 was edited. Sign in as the exam director and run "Verify audit trail".'); } }, 'Simulate tampering'),
      el('button', { class: 'dbtn', id: 'dwifi', onclick: (e) => { netOff = !netOff; e.target.textContent = netOff ? 'Wi-Fi: OFF (click to restore)' : 'Simulate Wi-Fi loss'; if (!netOff) window.dispatchEvent(new Event('online')); say(netOff ? 'Wi-Fi is now off. Offline mode works only if the chief supervisor switched it on for the session.' : 'Wi-Fi restored. Queued signatures sync automatically.'); } }, 'Simulate Wi-Fi loss'),
      el('button', { class: 'dbtn', id: 'dtime', onclick: () => { DB.sessions.forEach(s => { s.opens_at = iso(new Date(s.opens_at).getTime() - 20 * 60e3); }); say('Time passes: the exam started 20 minutes earlier, so new signatures can count as late.'); } }, 'Time passes +20 min'),
      el('button', { class: 'dbtn', onclick: () => { DB.failNext = true; say('The next email copy will bounce, so you can see the failure alert and Resend.'); } }, 'Make next email fail'),
      el('button', { class: 'dbtn', onclick: () => { DEMO.reset(); location.hash = ''; location.reload(); } }, 'Reset demo'), msg));
  }

  reset();
  window.DEMO = { showEmail, openSheet, openDoc, openPack, confirm: confirmBox, reset: () => { try { localStorage.removeItem('kca_queue'); } catch (e) { /* ignore */ } } };
  document.getElementById('demo-slot').replaceWith(banner());
})();

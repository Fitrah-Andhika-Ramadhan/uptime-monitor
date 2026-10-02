'use strict';
(() => {
  const $ = (s) => document.querySelector(s);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtMs = (v) => v == null ? '—' : (v >= 10000 ? (v / 1000).toFixed(0) + ' s' : v + ' ms');
  const fmtAgo = (ts) => {
    if (!ts) return '—';
    const d = Math.max(0, Date.now() / 1000 - ts);
    if (d < 60) return Math.floor(d) + 's ago';
    if (d < 3600) return Math.floor(d / 60) + ' min ago';
    if (d < 86400) return Math.floor(d / 3600) + ' h ago';
    return Math.floor(d / 86400) + ' hr lalu';
  };
  const fmtTime = (ts) => new Date(ts * 1000).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });

  const state = { me: null, monitors: [], poll: null, editing: null, busy: {} };

  const ep = (action) => new URL('index.php?action=' + encodeURIComponent(action), location.href).href;

  async function api(action, { method = 'GET', body = null, raw = false } = {}) {
    const opt = { method, credentials: 'same-origin', headers: {} };
    if (!raw) opt.headers['Accept'] = 'application/json';
    if (body !== null) {
      opt.headers['Content-Type'] = 'application/json';
      opt.headers['X-Requested-With'] = 'fetch';
      opt.headers['X-CSRF'] = state.me?.csrf ?? '';
      opt.body = JSON.stringify(body);
    }
    const res = await fetch(ep(action), opt);
    const ct = res.headers.get('content-type') || '';
    const json = ct.includes('json') ? await res.json().catch(() => null) : await res.text().catch(() => null);
    if (!res.ok) {
      const message = (json && (json.error || json.message)) || ('HTTP ' + res.status);
      const err = new Error(message);
      err.status = res.status;
      err.payload = json;
      throw err;
    }
    return json;
  }

  function toast(msg, bad = false) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.toggle('bad', bad);
    t.classList.remove('hidden');
    clearTimeout(t._h);
    t._h = setTimeout(() => t.classList.add('hidden'), 3200);
  }

  /* ================= charts ================= */
  function drawSpark(canvas, points, color = '#3ddc97') {
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.clientWidth || canvas.parentElement.clientWidth || 200;
    const h = canvas.clientHeight || 40;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, w, h);
    if (!points || points.length < 2) {
      ctx.strokeStyle = 'rgba(133,147,168,.3)';
      ctx.beginPath();
      ctx.moveTo(0, h / 2); ctx.lineTo(w, h / 2); ctx.stroke();
      return;
    }
    const max = Math.max(...points, 1);
    const stepX = w / (points.length - 1);
    const y = (v) => h - 3 - (v / max) * (h - 8);
    const grad = ctx.createLinearGradient(0, 0, 0, h);
    grad.addColorStop(0, color + '44');
    grad.addColorStop(1, color + '00');

    ctx.beginPath();
    points.forEach((v, i) => i === 0 ? ctx.moveTo(0, y(v)) : ctx.lineTo(i * stepX, y(v)));
    ctx.lineTo(w, h); ctx.lineTo(0, h); ctx.closePath();
    ctx.fillStyle = grad;
    ctx.fill();

    ctx.beginPath();
    points.forEach((v, i) => i === 0 ? ctx.moveTo(0, y(v)) : ctx.lineTo(i * stepX, y(v)));
    ctx.strokeStyle = color;
    ctx.lineWidth = 1.6;
    ctx.lineJoin = 'round';
    ctx.stroke();
  }

  /* ================= stats ================= */
  function recomputeStats() {
    const ms = state.monitors;
    if (!ms.length) return;
    const ups = ms.filter((m) => m.uptime_24h >= 0);
    const avgUp = ups.length ? ups.reduce((a, m) => a + m.uptime_24h, 0) / ups.length : null;
    const lats = ms.filter((m) => m.latency_ms > 0);
    const avgLat = lats.length ? Math.round(lats.reduce((a, m) => a + m.latency_ms, 0) / lats.length) : null;
    const worst = ms.filter((m) => m.status === 'down').length;

    const su = $('#statUptime');
    su.textContent = avgUp == null ? '—' : avgUp.toFixed(1) + '%';
    su.className = 'stat-value ' + (worst ? 'bad' : avgUp >= 99 ? 'ok' : '');

    const sl = $('#statLatency');
    sl.textContent = fmtMs(avgLat);
    sl.className = 'stat-value';

    const si = $('#statIncidents');
    si.textContent = worst;
    si.className = 'stat-value ' + (worst ? 'bad' : 'ok');
    $('#statIncidentsSub').textContent = worst ? 'needs attention' : 'all clear';

    $('#statMonitors').textContent = ms.length;
    $('#statMonitorsSub').textContent = ms.filter((m) => m.status === 'up').length + ' healthy';

    const badge = $('#systemBadge');
    badge.classList.remove('hidden');
    badge.classList.toggle('ok', !worst);
    badge.classList.toggle('bad', worst > 0);
    $('#systemBadgeText').textContent = worst ? worst + ' service(s) DOWN' : 'All systems operational';

    drawSpark($('#statUptimeChart'), ms.map((m) => Math.max(m.uptime_24h, 0)), worst ? '#e11d48' : '#0f9d58');
    drawSpark($('#statLatencyChart'), ms.map((m) => Math.max(m.latency_ms || 0, 0)), '#6739e6');
  }

  /* ================= site list (hPanel style) ================= */
  let searchQ = '';

  function renderList() {
    const root = $('#monitorGrid');
    root.innerHTML = '';
    const all = state.monitors;

    $('#emptyState').classList.toggle('hidden', all.length > 0);

    const groups = {};
    all.forEach((m) => (groups[m.group_name] = groups[m.group_name] || []).push(m));

    Object.keys(groups).sort().forEach((gname, gi) => {
      const items = groups[gname].filter((m) =>
        !searchQ || (m.name + ' ' + m.url + ' ' + m.group_name).toLowerCase().includes(searchQ.toLowerCase()));

      const gcard = document.createElement('div');
      gcard.className = 'site-group';
      gcard.style.animationDelay = (gi * 60) + 'ms';

      const head = document.createElement('div');
      head.className = 'sg-head';
      head.innerHTML = `<div><div class="sg-title">${esc(gname)}</div><div class="sg-sub dim">${items.length} websites &middot; interval ${esc(Math.min(...items.map((m) => m.interval_min)) || 5)} min</div></div>` +
        `<div class="sg-actions">
          <button class="btn btn-ghost btn-sm" data-checkgroup="${esc(gname)}">Check all</button>
          <button class="btn btn-primary btn-sm" data-addgroup="${esc(gname)}">+ Add website</button>
        </div>`;
      gcard.appendChild(head);

      if (!items.length) {
        gcard.insertAdjacentHTML('beforeend', '<div class="sg-empty dim">No matching websites.</div>');
        root.appendChild(gcard);
        return;
      }

      const rows = document.createElement('div');
      items.forEach((m, idx) => {
        const st = m.status;
        const dot = { up: 'in', degraded: 'degraded', down: 'down', unknown: 'unknown' }[st] || 'unknown';
        const chip = { up: ['Operational', 'chip-ok'], degraded: ['Slow', 'chip-warn'], down: ['DOWN', 'chip-bad'], unknown: ['Pending', 'chip-idle'] }[st];
        const upCls = m.uptime_24h >= 99 ? 'ok' : m.uptime_24h >= 95 ? 'warn' : 'bad';

        const row = document.createElement('div');
        row.className = 'site-row';
        row.style.animationDelay = ((gi * 60) + (idx * 35)) + 'ms';
        row.innerHTML = `
          <span class="sr-icon"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>
          <div class="sr-name"><span class="status-dot ${dot}"></span><b>${esc(m.name)}</b> <a class="sr-ext" href="${esc(m.url)}" target="_blank" rel="noopener noreferrer" title="Open site">&#8599;</a></div>
          <span class="chip ${chip[1]}"><span></span>${chip[0]}</span>
          <canvas class="sr-spark" data-spark="${m.id}" width="90" height="30"></canvas>
          <span class="sr-lat" title="Latensi terakhir">${fmtMs(m.latency_ms)}</span>
          <span class="sr-up up-pct ${m.uptime_24h < 0 ? 'dim' : upCls}" title="Uptime 24 jam">${m.uptime_24h >= 0 ? m.uptime_24h.toFixed(1) + '%' : '—'}</span>
          <div class="sr-actions">
            <button class="iconbtn" data-a="check" title="Check now" data-id="${m.id}">&#8635;</button>
            <button class="iconbtn" data-a="edit" title="Edit" data-id="${m.id}">&#9998;</button>
            <button class="iconbtn" data-a="del" title="Delete" data-id="${m.id}">&#10005;</button>
          </div>
        `;
        rows.appendChild(row);
      });
      gcard.appendChild(rows);
      root.appendChild(gcard);

      gcard.querySelectorAll('.iconbtn').forEach((b) => {
        b.onclick = () => {
          const id = +b.dataset.id;
          if (b.dataset.a === 'check') instantCheck(id);
          if (b.dataset.a === 'edit') openMonModal(state.monitors.find((x) => x.id === id));
          if (b.dataset.a === 'del') delMonitor(id);
        };
      });
      gcard.querySelector('[data-addgroup]').onclick = () => { openMonModal(null); $('#fGroup').value = gname; };
      gcard.querySelector('[data-checkgroup]').onclick = () => items.forEach((m) => instantCheck(m.id));
    });

    requestAnimationFrame(() => {
      document.querySelectorAll('canvas[data-spark]').forEach((cv) => {
        const m = state.monitors.find((x) => x.id === +cv.dataset.spark);
        if (m) drawSpark(cv, m.spark.map((p) => p.l), '#6739e6');
      });
    });
  }

  /* ================= data refresh ================= */
  async function refresh(rerender = true) {
    try {
      const d = await api('monitors');
      state.monitors = d.monitors || [];
      if (rerender) renderList();
      recomputeStats();
    } catch (e) {
      if (e.status === 401) showLogin();
    }
  }

  function startPolling() {
    clearInterval(state.poll);
    if (state.es) state.es.close();

    if (typeof EventSource !== 'undefined') {
      state.es = new EventSource(ep('live'));
      state.es.addEventListener('tick', (e) => {
        try {
          const d = JSON.parse(e.data);
          state.monitors = d.monitors || state.monitors;
          state.attLive = d.att || null;
          state.secLive = d.sec || null;
          onLive();
        } catch { }
      });
      state.es.onerror = () => {
        if (document.visibilityState === 'visible' && !state.es._fell) {
          state.es._fell = true;
          state.es.close();
          startPollingLegacy();
          return;
        }
        state.es.close();
        startPollingLegacy();
      };
    } else {
      startPollingLegacy();
    }
  }

  let legacyPoll = null;
  function startPollingLegacy() {
    clearInterval(legacyPoll);
    legacyPoll = setInterval(() => refresh(true), 15000);
  }

  function onLive() {
    try {
      recomputeStats();
      if (state.view === 'dashboard') renderList();
      if (state.view === 'statistik') renderStatistik();
      if (state.view === 'keamanan' && state.secLive) renderSecurity(state.secLive, state.attLive);
    } catch { }
  }

  /* ================= auth ================= */
  function showLogin() {
    $('#appView').classList.add('hidden');
    $('#chatFab').classList.add('hidden');
    $('#loginView').classList.remove('hidden');
    $('#loginPassword').focus();
  }

  function showApp() {
    $('#loginView').classList.add('hidden');
    $('#appView').classList.remove('hidden');
    $('#chatFab').classList.remove('hidden');
    switchView('dashboard');
    refresh(true);
    startPolling();
  }

  /* ================= monitor modal ================= */
  function openMonModal(m = null) {
    state.editing = m ? m.id : null;
    $('#monModalTitle').textContent = m ? 'Edit monitor' : 'New monitor';
    $('#fName').value = m?.name ?? '';
    $('#fUrl').value = m?.url ?? '';
    $('#fGroup').value = m?.group_name ?? 'Web';
    $('#fInterval').value = m?.interval_min ?? 5;
    $('#fMethod').value = m?.method ?? 'HEAD';
    $('#fCode').value = m?.expected_code ?? 0;
    $('#fKeyword').value = m?.keyword ?? '';
    $('#monModalTitle').closest('.modal').classList.remove('hidden');
    $('#fName').focus();
  }

  function closeMonModal() { $('#modalMon').classList.add('hidden'); }

  async function submitMon(e) {
    e.preventDefault();
    const body = {
      id: state.editing,
      name: $('#fName').value.trim(),
      url: $('#fUrl').value.trim(),
      group_name: $('#fGroup').value.trim() || 'Web',
      interval_min: +$('#fInterval').value || 5,
      method: $('#fMethod').value,
      expected_code: +$('#fCode').value || 0,
      keyword: $('#fKeyword').value.trim(),
    };
    const btn = $('#monSubmit');
    btn.disabled = true;
    try {
      await api(state.editing ? 'monitor-update' : 'monitor-create', { method: 'POST', body });
      closeMonModal();
      toast(state.editing ? 'Monitor updated' : 'Monitor added - checking...');
      refresh(true);
    } catch (err) {
      toast(err.message, true);
    } finally {
      btn.disabled = false;
    }
  }

  async function delMonitor(id) {
    const m = state.monitors.find((x) => x.id === id);
    if (!confirm(`Delete monitor "${m?.name}"?`)) return;
    try {
      await api('monitor-delete', { method: 'POST', body: { id } });
      toast('Monitor removed');
      refresh(true);
    } catch (err) {
      toast(err.message, true);
    }
  }

  async function instantCheck(id) {
    if (state.busy[id]) return;
    state.busy[id] = true;
    try {
      const d = await api('instant-check', { method: 'POST', body: { id } });
      const r = d.result;
      toast(r.down ? `DOWN: ${r.err || 'HTTP ' + r.code}` : `OK - HTTP ${r.code} dalam ${fmtMs(r.latency)}`, !!r.down);
      refresh(true);
    } catch (err) {
      toast(err.message, true);
    } finally {
      state.busy[id] = false;
    }
  }

  /* ================= insight ================= */
  async function openInsight() {
    $('#modalInsight').classList.remove('hidden');
    const body = $('#insightBody');
    body.innerHTML = '<div class="chat-hint">Analyzing monitoring data...</div>';
    try {
      const d = await api('insight', { method: 'POST', body: {} });
      await typeIn(body, d.text || '(kosong)', d.cached);
    } catch (err) {
      body.innerHTML = `<div class="msg err">${esc(err.message)}</div>`;
    }
  }

  async function typeIn(el, md, cached) {
    el.innerHTML = '';
    el.insertAdjacentHTML('beforeend', `<div class="stat-sub" style="margin-bottom:10px">${cached ? 'cached dari analisis terakhir' : 'analisis baru'}</div>`);
    const target = document.createElement('div');
    el.appendChild(target);
    const html = mdToHtml(md);
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
      target.innerHTML = html;
      return;
    }
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    for (const node of [...tmp.childNodes]) {
      await new Promise((r) => setTimeout(r, 120));
      target.appendChild(node);
    }
  }

  function mdToHtml(md) {
    let h = esc(md)
      .replace(/^### (.*)$/gm, '<h3>$1</h3>')
      .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
      .replace(/\n\n+/g, '</p><p>');
    h = h.replace(/(<li>[\s\S]*?<\/li>)/g, '<ul>$1</ul>').replace(/<ul>(\s*<li>)+/g, '<ul>').replace(/(<\/li>)+\s*<\/ul>/g, '</li></ul>');
    return '<p>' + h + '</p>'
      .replace(/<ul><p>/g, '<ul>').replace(/<\/p><ul>/g, '<ul>')
      .replace(/<\/ul><p>/g, '</ul><p>').replace(/<\/p><\/ul>/g, '</ul><p>');
  }

  /* ================= chat ================= */
  let chatOpen = false;
  let busy = false;

  function addMsg(cls, text = '') {
    $('#chatBody').querySelector('.chat-hint')?.remove();
    const div = document.createElement('div');
    div.className = 'msg ' + cls;
    div.textContent = text;
    $('#chatBody').appendChild(div);
    $('#chatBody').scrollTop = $('#chatBody').scrollHeight;
    return div;
  }

  async function sendChat(e) {
    e.preventDefault();
    if (busy) return;
    const field = $('#chatField');
    const msg = field.value.trim();
    if (!msg) return;
    field.value = '';
    addMsg('user', msg);

    busy = true;
    let node = null;
    try {
      const res = await fetch(ep('chat'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'fetch',
          'X-CSRF': state.me?.csrf ?? '',
        },
        body: JSON.stringify({ message: msg }),
      });

      if (!res.ok || !res.body) {
        let message = 'HTTP ' + res.status;
        try { message = (await res.json()).error || message; } catch { }
        throw new Error(message);
      }

      node = addMsg('ai');
      const reader = res.body.getReader();
      const dec = new TextDecoder();
      let buf = '';
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buf += dec.decode(value, { stream: true });
        const parts = buf.split('\n\n');
        buf = parts.pop();
        for (const part of parts) {
          const line = part.split('\n').find((l) => l.startsWith('data:'));
          if (!line) continue;
          const j = JSON.parse(line.slice(5).trim());
          if (j.c) {
            node.textContent += j.c;
            $('#chatBody').scrollTop = $('#chatBody').scrollHeight;
          } else if (j.e) {
            node.textContent = 'Error: ' + j.e;
          }
        }
      }
    } catch (err) {
      (node || addMsg('ai')).textContent = 'Error: ' + err.message;
    } finally {
      busy = false;
    }
  }

  window.runQuickSecScan = async function() {
    const url = $('#quickSecUrl').value.trim();
    if (!url) return alert('Masukkan URL!');
    const btn = $('#btnQuickSec');
    const resDiv = $('#quickSecResult');
    btn.disabled = true;
    btn.textContent = 'Scanning...';
    resDiv.style.display = 'none';
    
    try {
      const res = await api('quick-sec-scan', { method: 'POST', body: JSON.stringify({ url }) });
      const d = res.result;
      const headers = ['hsts', 'xfo', 'xcto', 'ref', 'csp'].map(h => `<span class="chip ${d.headers[h] ? 'chip-ok' : 'chip-bad'}">${h}</span>`).join(' ');
      const bl = Object.keys(d.blacklist).map(k => `<span class="chip ${d.blacklist[k] === 1 ? 'chip-bad' : 'chip-idle'}">${k}</span>`).join(' ');
      
      resDiv.innerHTML = `
        <div style="display:flex;gap:15px;align-items:center;margin-bottom:15px">
            <div style="font-size:32px;font-weight:bold;color:${d.grade==='A'?'#10b981':d.grade==='B'?'#a3e635':d.grade==='C'?'#eab308':'#ef4444'}">${d.grade}</div>
            <div>
                <div style="font-size:18px">Score: <b>${d.score}/90</b></div>
                <div class="dim">IP: ${d.ip || 'Unknown'}</div>
            </div>
        </div>
        <div style="margin-bottom:8px"><b>SSL:</b> ${d.ssl_ok ? `<span style="color:#10b981">Valid (${d.ssl_days} days) - ${d.ssl_issuer}</span>` : '<span style="color:#ef4444">Invalid / Expired</span>'}</div>
        <div style="margin-bottom:8px"><b>Security Headers:</b><br><div style="margin-top:5px">${headers}</div></div>
        <div style="margin-bottom:8px"><b>Email Auth:</b> SPF ${d.spf ? '✅' : '❌'} | DMARC ${d.dmarc ? '✅' : '❌'}</div>
        <div style="margin-bottom:8px"><b>Reputation:</b><br><div style="margin-top:5px">${bl}</div></div>
      `;
      resDiv.style.display = 'block';
    } catch (e) {
      alert('Error: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Scan Website';
    }
  };

  /* ================= navigation & views ================= */
  const VIEW_TITLES = { dashboard: 'Dashboard', statistik: 'Statistics', domain: 'Domain', keamanan: 'Security', database: 'Database', penempatan: 'Deployments', cron: 'Cron Job', php: 'Info PHP', cache: 'Cache Manager', ssh: 'SSH Access', dns: 'DNS Zone Editor', git: 'GIT', gate: 'Gerbang Deploy', terminal: 'Terminal' };

  function switchView(name) {
    state.view = name;
    document.querySelectorAll('.nav .nav-item, .nav-sub a').forEach((el) => el.classList.toggle('active', el.dataset.view === name || el.dataset.panel === name));
    $('#viewTitle').textContent = VIEW_TITLES[name] || 'Dashboard';
    $('#btnAdd').style.display = name === 'dashboard' ? '' : 'none';
    $('#view-dashboard').classList.toggle('hidden', name !== 'dashboard');
    $('#view-statistik').classList.toggle('hidden', name !== 'statistik');
    $('#view-panel').classList.toggle('hidden', !VIEW_PANELS[name]);
    if (name === 'statistik') renderStatistik();
    if (name === 'keamanan') renderSecurity();
    if (name === 'penempatan') renderDeployments();
    if (name === 'database') renderDatabase();
    if (VIEW_PANELS[name]) renderPanel(name);
  }

  const H = 'https://hpanel.hostinger.com';
  const VIEW_PANELS = {
    domain: { title: 'Domain', body: () => `
      <h3>Kelola DNS & domain di hPanel</h3>
      <p>All monitored domains are managed in hPanel: A/AAAA records, nameservers, redirects, whois.</p>
      <code>hPanel > Domain > DNS Zone Editor</code>
      <ol>
        <li>Pastikan record <b>A/AAAA</b> domain menunjuk ke IP hosting Hostinger Anda.</li>
        <li>If a monitor shows <i>Could not resolve host</i>, check DNS here.</li>
      </ol>
      <p><a class="panel-link" href="${H}/websites" target="_blank" rel="noopener">Open Domains in hPanel &nearr;</a></p>` },
    database: { title: 'Database', body: () => `
      <h3>Database dashboard (SQLite)</h3>
      <p>Dashboard ini memakai SQLite pada path berikut di akun hosting Anda:</p>
      <code>data/Xttack.sqlite (folder di samping public_html)</code>
      <ol>
        <li>Backup otomatis: salin file tersebut kapan saja via File Manager hPanel.</li>
        <li>Full reset: delete Xttack.sqlite - cron rebuilds it automatically.</li>
        <li>Tidak butuh MySQL - hemat resource paket Business.</li>
      </ol>` },
    cron: { title: 'Cron Job', body: () => `
      <h3>Automated uptime check every 5 minutes</h3>
      <p>In hPanel <b>Advanced > Cron Job</b>, add this job:</p>
      <code>/usr/local/bin/php /home/USERNAME/cron/check.php</code>
      <ol>
        <li>Replace <b>USERNAME</b> with your hosting account username.</li>
        <li>Schedule: every 5 minutes (<i>*/5 * * * *</i>).</li>
        <li>Save and wait 5-10 minutes for the first data points.</li>
      </ol>` },
    php: { title: 'Info PHP', body: () => `
      <h3>PHP version & config</h3>
      <p>This app requires PHP 7.4+ (8.1+ recommended). Adjust in hPanel <b>Advanced > PHP Config</b>.</p>
      <code>upload_max_filesize = 32M\nmemory_limit = 256M\nmax_execution_time = 60</code>` },
    cache: { title: 'Cache Manager', body: () => `
      <h3>Cache</h3>
      <p>The dashboard fetches data directly (live SSE), so no caching needed. For monitored websites, make sure a CDN cache doesn't mask the origin server's real status.</p>` },
    ssh: { title: 'SSH Access', body: () => `
      <h3>Akses SSH</h3>
      <p>Enable in hPanel <b>Advanced > SSH Access</b>, then run the check manually if needed:</p>
      <code>php /home/USERNAME/cron/check.php</code>` },
    dns: { title: 'DNS Zone Editor', body: () => `
      <h3>DNS domain yang dimonitor</h3>
      <p>If a monitor shows <i>Could not resolve host</i>, inspect A/AAAA records in hPanel <b>Domains > DNS Zone Editor</b> for that domain.</p>` },
    git: { title: 'GIT', body: () => `
      <h3>Deploy via GIT</h3>
      <p>If your website is deployed via GIT (like in hPanel), each push triggers an automatic deploy. This monitor will detect the brief downtime during deployment.</p>` },
    gate: { title: 'Gerbang Deploy — pre-flight checklist', body: () => `
      <p>All changes must pass this gate before deploying to Hostinger: enter the staging/local URL and the system checks endpoint, HTTPS, DNS/SPF/DMARC, and security headers, then issues a verdict.</p>
      <div style="display:flex;gap:10px;margin:14px 0 6px">
        <input id="gateUrl" style="flex:1;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--txt);font:400 13.5px var(--sans)" placeholder="https://staging-website-anda.com">
        <button class="btn btn-primary" id="btnGate" type="button">Jalankan gerbang</button>
      </div>
      <div id="gateRes"></div>` },
    terminal: { title: 'Terminal', body: () => 'Xttack TTY' },
  };

  function renderPanel(name) {
    const p = VIEW_PANELS[name];
    const box = $('#view-panel');
    box.classList.remove('hidden');
    $('#view-dashboard').classList.add('hidden');
    $('#view-statistik').classList.add('hidden');
    if (name === 'ssh' || name === 'terminal') {
      box.innerHTML = `
        <div style="margin-bottom:12px">
            <h3>Terminal Akses (Web Shell)</h3>
            <p class="dim" style="font-size:13px">Jalankan perintah layaknya SSH langsung dari browser. Perintah yang tersedia: ls, pwd, cat, php, curl, dll.</p>
        </div>
        <div id="termMount" style="max-width:820px"></div>
      `;
      initTerminal(box.querySelector('#termMount'));
      return;
    }
    box.innerHTML = `<div class="panel-card"><h3>${p.title}</h3>${p.body()}</div>`;
    if (name === 'gate') {
      box.querySelector('#btnGate').onclick = runGate;
      box.querySelector('#gateUrl').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); runGate(); } });
    }
  }

  /* ================= security hardening ================= */
  let secScans = {};

  const HDRL = { hsts: 'HSTS', xfo: 'X-Frame-Options', xcto: 'X-Content-Type-Options', ref: 'Referrer-Policy', csp: 'CSP' };

  function gradeCls(g) { return { A: 'grade-a', B: 'grade-b', C: 'grade-c', D: 'grade-d', F: 'grade-f' }[g] || 'grade-f'; }

  async function renderSecurity(secData, attData) {
    const root = $('#view-panel');
    document.querySelectorAll('.nav .nav-item, .nav-sub a').forEach((el) => el.classList.toggle('active', el.dataset.view === 'keamanan'));
    $('#viewTitle').textContent = 'Security';
    $('#view-dashboard').classList.add('hidden');
    $('#view-statistik').classList.add('hidden');
    root.classList.remove('hidden');

    let sec = secData || {};
    let att = attData || { rows: [], stats: { h24: 0, uniq24: 0, high24: 0, d7: 0, banned: 0 }, bans: [] };
    const hadData = !!secData;
    if (!secData) {
      try {
        const [d, a] = await Promise.all([api('security'), api('attacks')]);
        sec = d.scans || {};
        att = a;
      } catch (e) {
        sec = {};
      }
    }
    secScans = sec;

    const sevCls = { low: 'chip-idle', medium: 'chip-warn', high: 'chip-bad' };
    const sevLbl = { low: 'rendah', medium: 'sedang', high: 'TINGGI' };
    const attHtml = att.rows.length ? `
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:12px 0">
        <div class="mini-stat"><span class="stat-label">Serangan 24j</span><span class="stat-value" style="font-size:20px">${att.stats.h24}</span></div>
        <div class="mini-stat"><span class="stat-label">IP unik 24j</span><span class="stat-value" style="font-size:20px">${att.stats.uniq24}</span></div>
        <div class="mini-stat"><span class="stat-label">Auto-banned</span><span class="stat-value" style="font-size:20px">${att.stats.banned}</span></div>
        <div class="mini-stat"><span class="stat-label">Total 7d</span><span class="stat-value" style="font-size:20px">${att.stats.d7}</span></div>
      </div>
      <div style="max-height:260px;overflow-y:auto;border:1px solid var(--line);border-radius:10px">
      ${att.rows.map((r) => `
        <div style="display:flex;gap:10px;align-items:center;padding:9px 12px;border-bottom:1px solid var(--line);font-size:12.8px">
          <span class="dim" style="min-width:110px;font-family:var(--mono)">${fmtAgo(r.ts)}</span>
          <span class="chip ${sevCls[r.sev] || 'chip-idle'}"><span></span>${sevLbl[r.sev] || r.sev}</span>
          <b style="font-family:var(--mono);min-width:120px">${esc(r.ip)}</b>
          <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.reason)}</span>
          <button class="iconbtn" title="Unban" style="width:26px;height:26px;font-size:15px" onclick="navigator.clipboard&&navigator.clipboard.writeText('${esc(r.ip)}');this.textContent='OK'">unban</button>
        </div>`).join('')}
      </div>` : `<p class="dim">No attack logs yet. Honeypot & middleware are active — every guess for .env/wp-admin/SQL injection/scanner tercatat di sini.</p>`;
    const banHtml = (att.bans && Object.keys(att.bans).length) ? `<div class="sub dim" style="margin-top:8px">Banned IPs: ${Object.keys(att.bans).map(esc).join(', ')} <button class="btn btn-ghost btn-sm" id="btnClearBan">Clear all bans</button></div>` : '';

    let html = `
      <div class="list-head"><h2>Quick Security Scan (Manual)</h2>
      <div class="dim" style="font-size:12.5px">Periksa keamanan web mana saja melalui link (tanpa perlu masuk monitor)</div></div>
      <div class="panel-card" style="margin-bottom:20px;border-color:var(--brand)">
          <div style="display:flex;gap:10px">
              <input type="text" id="quickSecUrl" class="input" placeholder="https://example.com" style="flex:1">
              <button class="btn" onclick="runQuickSecScan()" id="btnQuickSec">Scan Website</button>
          </div>
          <div id="quickSecResult" style="margin-top:15px;display:none;background:#052e16;padding:15px;border:1px solid var(--brand);border-radius:10px"></div>
      </div>
      
      <div class="list-head"><h2>Attack Detection</h2>
      <div class="dim" style="font-size:12.5px">honeypot · scanner UA · SQLi/XSS · bruteforce — auto-ban 24 jam</div></div>
      <div class="panel-card">${attHtml}${banHtml}</div>`;

    html += `<div class="list-head" style="margin-top:22px"><h2>Per-site hardening</h2>
      <div class="dim" style="font-size:12.5px">click "Scan" to refresh</div></div>`;

    if (!state.monitors.length) {
      html += `<div class="empty"><div class="empty-icon">&#9788;</div><h2>No monitors</h2><p class="dim">Add a monitor on the Dashboard before running security scans.</p></div>`;
    } else {
      html += `<div style="overflow-x:auto"><table class="stat-table"><thead><tr>
        <th>Website</th><th>Grade</th><th>Score</th><th>SSL</th><th>Headers</th><th>Reputation</th><th>SPF / DMARC</th><th>Action</th>
      </tr></thead><tbody id="secRows">`;
      state.monitors.forEach((m) => { html += secRowHtml(m); });
      html += '</tbody></table></div>';
      html += `<div class="panel-card"><h3>How to harden on Hostinger</h3>
        <ol>
          <li><b>SSL</b>: hPanel > Security > SSL - enable certificate + Force HTTPS</li>
          <li><b>HSTS & CSP headers</b>: open .htaccess and add these lines</li>
        </ol>
        <code>Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"</code>
        <p>SPF/DMARC are set in hPanel > Email / DNS. Business plan includes an active malware scanner.</p>
      </div>`;
    }
    root.innerHTML = html;

    document.querySelectorAll('#secRows [data-scan]').forEach((btn) => {
      btn.onclick = () => runScan(btn.dataset.scan);
    });
    const cl = root.querySelector('#btnClearBan');
    if (cl) cl.onclick = async () => {
      Object.keys(att.bans).forEach((ip) => { api('unban', { method: 'POST', body: { ip } }).catch(() => { }); });
      toast('All bans cleared');
      renderSecurity();
    };
  }

  function secRowHtml(m) {
    const scan = secScans[m.id];
    if (!scan) {
      return `<tr>
        <td><b>${esc(m.name)}</b><div class="sub">${esc(m.url.replace(/^https?:\/\//, ''))}</div></td>
        <td colspan="5" class="sub">Not scanned yet</td>
        <td><button class="btn btn-ghost btn-sm" data-scan="${m.id}">Scan</button></td>
      </tr>`;
    }
    const sslTxt = scan.ssl_ok
      ? `${scan.ssl_days} hari (${esc(scan.ssl_issuer ? scan.ssl_issuer.split(',')[0] : '')})`
      : '<span style="color:var(--bad)">tidak valid</span>';
    const bl = scan.blacklist || {};
    const blMeta = { dbl_dblspamhaus: 'Spamhaus DBL', dbl_surbl: 'SURBL', dnsbl_sorbs: 'SORBS', dbl_zen: 'Spamhaus Zen' };
    const blChips = Object.keys(blMeta).map((k) => {
      const v = bl[k];
      const cls = v === 1 ? 'bl-bad' : v === 0 ? 'bl-ok' : 'bl-unk';
      const lbl = v === 1 ? 'LISTED' : v === 0 ? 'clean' : 'n/a';
      return `<span class="blchip ${cls}" title="${blMeta[k]}">${blMeta[k].split(' ').pop()}·${lbl}</span>`;
    }).join('');
    const ipTxt = scan.ip ? `<span class="sub" style="font-family:var(--mono)">${esc(scan.ip)}</span>` : '';
    const hdrs = Object.keys(HDRL).map((k) =>
      `<span class="hdrchip ${scan.headers[k] ? 'on' : 'off'}" title="${HDRL[k]}">${HDRL[k]}</span>`).join('');
    const dns = (scan.spf ? 'SPF ✓' : 'SPF ✕') + ' / ' + (scan.dmarc ? 'DMARC ✓' : 'DMARC ✕');
    return `<tr>
      <td><b>${esc(m.name)}</b><div class="sub">${esc(m.url.replace(/^https?:\/\//, ''))} ${ipTxt}</div></td>
      <td><span class="grade-badge ${gradeCls(scan.grade)}">${scan.grade}</span></td>
      <td>${scan.score}/90${scan.blacklisted ? '<div class="sub" style="color:var(--bad)">blacklisted</div>' : ''}</td>
      <td>${sslTxt}</td>
      <td>${hdrs}</td>
      <td>${blChips}</td>
      <td class="sub">${dns}</td>
      <td><button class="btn btn-ghost btn-sm" data-scan="${m.id}">Re-scan</button></td>
    </tr>`;
  }

  async function runScan(id) {
    try {
      toast('Scanning security...');
      const d = await api('security-scan', { method: 'POST', body: { id } });
      secScans[id] = { ...d.scan, ts: Math.floor(Date.now() / 1000) };
      toast(`Grade ${d.scan.grade} (${d.scan.score}/90) untuk ${secOf(id)}`);
      renderSecurity();
    } catch (err) {
      toast(err.message, true);
    }
  }

  function secOf(id) {
    const m = state.monitors.find((x) => x.id === +id);
    return m ? m.name : '';
  }

  /* ================= database CRUD ================= */
  async function renderDatabase() {
    const box = $('#view-panel');
    box.classList.remove('hidden');
    $('#view-dashboard').classList.add('hidden');
    $('#view-statistik').classList.add('hidden');
    $('#viewTitle').textContent = 'Database Admin';
    
    // Embed Adminer in iframe
    box.innerHTML = `
      <div style="height:calc(100vh - 80px); margin:-15px">
          <iframe src="dbadmin.php" style="width:100%;height:100%;border:none;background:#fff;border-radius:6px"></iframe>
      </div>
    `;
  }

  async function loadDbRows(table, page = 1) {
    const body = $('#dbBody');
    body.innerHTML = '<div class="chat-hint">Loading rows...</div>';
    let meta = await api('db-tables');
    let rows = [];
    try {
      rows = (await api('db-rows&table=' + encodeURIComponent(table) + '&page=' + page)).rows;
    } catch (e) {
      body.innerHTML = `<div class="msg err">${esc(e.message)}</div>`;
      return;
    }
    const cols = meta.columns[table] || [];
    const editable = (meta.tables.find((t) => t.name === table) || {}).editable;
    const ctrl = editable
      ? `<div style="display:flex;gap:8px;margin:10px 0"><button class="btn btn-ghost btn-sm" id="dbPrev" ${page <= 1 ? 'disabled' : ''}>&larr; prev</button><span class="dim mono" style="align-self:center">page ${page}</span><button class="btn btn-ghost btn-sm" id="dbNext">next &rarr;</button><button class="btn btn-primary btn-sm" id="dbAdd">+ New row</button></div>`
      : `<div class="dim mono" style="margin:10px 0;font-size:11px">read-only table</div>`;

    const head = '<th>id</th>' + cols.filter((c) => c.name !== 'id').map((c) => `<th>${esc(c.name)}</th>`).join('') + (editable ? '<th></th>' : '');
    const trs = rows.map((r) => {
      const tds = cols.filter((c) => c.name !== 'id').map((c) => {
        let v = r[c.name];
        if (v === null) return '<td class="sub">null</td>';
        v = String(v);
        const long = v.length > 40;
        return `<td title="${esc(v)}" class="mono-cell">${esc(long ? v.slice(0, 40) + '…' : v)}</td>`;
      }).join('');
      const act = editable
        ? `<td style="white-space:nowrap"><button class="iconbtn" data-e="${r.id}" title="Edit">✎</button><button class="iconbtn" data-d="${r.id}" title="Delete">✕</button></td>`
        : '';
      return `<tr>${'<td class="mono-cell">' + r.id + '</td>' + tds + act}</tr>`;
    }).join('');

    body.innerHTML = `
      <div style="overflow-x:auto"><table class="stat-table"><thead><tr>${head}</tr></thead><tbody>${trs || '<tr><td colspan="9" class="dim" style="padding:16px">empty</td></tr>'}</tbody></table></div>
      ${ctrl}`;

    if (editable) {
      body.querySelector('#dbPrev')?.addEventListener('click', () => loadDbRows(table, page - 1));
      body.querySelector('#dbNext')?.addEventListener('click', () => loadDbRows(table, page + 1));
      body.querySelector('#dbAdd')?.addEventListener('click', () => dbForm(table, cols.filter((c) => c.name !== 'id'), null));
      body.querySelectorAll('[data-e]').forEach((b) => b.onclick = () => {
        const r = rows.find((x) => x.id === b.dataset.e) || rows.find((x) => x.id === +b.dataset.e);
        dbForm(table, cols.filter((c) => c.name !== 'id'), r);
      });
      body.querySelectorAll('[data-d]').forEach((b) => b.onclick = async () => {
        if (!confirm('Delete row ' + b.dataset.d + ' from ' + table + '?')) return;
        try {
          await api('db-delete', { method: 'POST', body: { table, id: +b.dataset.d } });
          toast('Row deleted');
          loadDbRows(table, page);
        } catch (e) { toast(e.message, true); }
      });
    }
  }

  function dbForm(table, cols, row = null) {
    const box = $('#dbBody');
    const overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.id = 'dbFormModal';
    const fields = cols.map((c) => {
      const v = row ? String(row[c.name] ?? '') : '';
      const cur = long_col(c.name) ? 'textarea' : 'input';
      return `<label>${esc(c.name)}${c.pk ? ' (pk)' : ''} <${cur} ${cur === 'input' ? '' : 'rows="2"'} id="df_${esc(c.name)}" spellcheck="false">${esc(v)}</${cur}></label>`;
    }).join('');
    overlay.innerHTML = `<div class="modal-card"><div class="modal-head"><span>${row ? 'Edit' : 'Insert'} row — ${esc(table)}</span><button class="iconbtn" id="dbfmClose">&times;</button></div>
      <form id="dbfm">${fields}
      <div class="modal-actions"><button type="button" class="btn btn-ghost" id="dbfmCancel">Cancel</button><button type="submit" class="btn btn-primary" id="dbfmSave">Save</button></div></form></div>`;
    overlay.querySelector('#dbfmClose').onclick = () => overlay.remove();
    overlay.querySelector('#dbfmCancel').onclick = () => overlay.remove();
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
    overlay.querySelector('#dbfm').onsubmit = async (e) => {
      e.preventDefault();
      const data = {};
      cols.forEach((c) => { data[c.name] = overlay.querySelector('#df_' + c.name)?.value ?? ''; });
      const btn = overlay.querySelector('#dbfmSave');
      btn.disabled = true;
      try {
        await api(row ? 'db-update' : 'db-insert', { method: 'POST', body: { table, id: row?.id, data } });
        overlay.remove();
        toast(row ? 'Row updated' : 'Row inserted');
        loadDbRows(table);
      } catch (ex) {
        toast(ex.message, true);
        btn.disabled = false;
      }
    };
    document.body.appendChild(overlay);
  }

  function long_col(name) {
    return /text|rawl|url|err|ua|message|body|reason|items/i.test(name);
  }

  async function runSql() {
    const sql = $('#sqlBox').value.trim();
    const res = $('#sqlRes');
    if (!sql) return;
    res.innerHTML = '<div class="chat-hint">Running...</div>';
    try {
      const rows = (await api('db-exec', { method: 'POST', body: { sql } })).rows;
      if (!rows.length) { res.innerHTML = '<div class="dim mono" style="font-size:11.5px;padding:8px">0 rows</div>'; return; }
      const cols = Object.keys(rows[0]);
      const h = '<tr>' + cols.map((c) => `<th>${esc(c)}</th>`).join('') + '</tr>';
      const tr = rows.map((r) => '<tr>' + cols.map((c) => `<td class="mono-cell">${esc(String(r[c]))}</td>`).join('') + '</tr>').join('');
      res.innerHTML = `<div style="overflow-x:auto;max-height:280px;overflow-y:auto;border:1px solid var(--line);border-radius:8px"><table class="stat-table"><thead>${h}</thead><tbody>${tr}</tbody></table></div>`;
    } catch (e) {
      res.innerHTML = `<div class="msg err">${esc(e.message)}</div>`;
    }
  }

  /* ================= penempatan (deployments) ================= */
  const DEP_CHIP = {
    passed: ['Completed', 'chip-ok'],
    warn: ['Warning', 'chip-warn'],
    blocked: ['Blocked', 'chip-bad'],
    checking: ['Checking', 'chip-idle'],
  };

  async function renderDeployments() {
    $('#viewPanelTitleHint')?.remove();
    let rows = [];
    try {
      rows = (await api('deployments')).rows;
    } catch (e) { }

    $('#view-panel').classList.remove('hidden');
    $('#view-statistik').classList.add('hidden');
    $('#view-dashboard').classList.add('hidden');

    const head = `<div class="list-head"><h2>Deployments</h2>
      <span class="chip chip-ok"><span></span>Gate active</span>
      <div class="dim" style="font-size:12.5px">webhook GitHub: POST /index.php?action=deploy-hook&secret=CRON_SECRET</div></div>`;

    if (!rows.length) {
      $('#view-panel').innerHTML = head + `<div class="panel-card"><h3>No deployments yet</h3>
        <p>Sambungkan GitHub: buat webhook repository ke URL di atas dengan secret <b>CRON_SECRET</b> dari config.php, atau jalankan gerbang manual dari menu <b>Tingkat lanjut > Gerbang Deploy</b>.</p></div>`;
      return;
    }

    const rowsHtml = rows.map((r) => {
      const c = DEP_CHIP[r.status] || DEP_CHIP.checking;
      const dt = new Date(r.ts * 1000).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
      return `<tr>
        <td>${esc(r.branch || 'main')}</td>
        <td><code class="sha">${esc(r.sha || '----')}</code> ${esc((r.message || '').slice(0, 70))}</td>
        <td>${dt}</td>
        <td><span class="chip ${c[1]}"><span></span>${c[0]}</span></td>
        <td><a href="https://github.com/${esc(r.site)}" target="_blank" rel="noopener" class="sub site-link">${esc(r.site)}</a></td>
      </tr>`;
    }).join('');

    $('#view-panel').innerHTML = head + `
      <div style="overflow-x:auto"><table class="stat-table">
        <thead><tr><th>Cabang</th><th>Commit</th><th>Waktu</th><th>Status</th><th>Repo</th></tr></thead>
        <tbody>${rowsHtml}</tbody>
      </table></div>
      <div class="panel-card"><h3>Workflow</h3>
      <ol>
        <li>A push to GitHub fires the webhook; the pre-flight checklist runs automatically.</li>
        <li>The gate decides: <b>passed</b> (safe to deploy), <b>warn</b> (deploy with notes), <b>blocked</b> (fix first).</li>
        <li>Results + AI notes are stored in this Deployments list.</li>
      </ol></div>`;
  }

  /* ================= gerbang deploy (panel) ================= */
  async function runGate() {
    const url = $('#gateUrl').value.trim();
    const resEl = $('#gateRes');
    if (!url) { toast('Isi URL dulu', true); return; }
    resEl.innerHTML = '<div class="chat-hint">Running pre-flight checklist...</div>';
    try {
      const d = await api('gate', { method: 'POST', body: { url } });
      const cls = { passed: 'chip-ok', warn: 'chip-warn', blocked: 'chip-bad' }[d.verdict];
      const lbl = { passed: 'GO', warn: 'HOLD', blocked: 'STOP' }[d.verdict];
      resEl.innerHTML = `
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px">
          <span class="grade-badge ${gradeCls(d.verdict === 'passed' ? 'A' : d.verdict === 'warn' ? 'C' : 'F')}">${lbl[0]}</span>
          <span class="chip ${cls}"><span></span>${lbl}</span>
          <b>${d.score}/100</b>
        </div>
        ${d.items.map((i) => `<div class="gate-item"><b>${i.pass ? 'OK' : i.warn ? 'WARN' : 'FAIL'}</b> ${esc(i.label)} <span class="dim sub">${esc(i.detail)}</span></div>`).join('')}
        ${d.ai_note ? `<div class="msg ai" style="margin-top:12px">${esc(d.ai_note)}</div>` : ''}`;
    } catch (err) {
      resEl.innerHTML = `<div class="msg err">${esc(err.message)}</div>`;
    }
  }

  /* ================= terminal ================= */
  function initTerminal(el) {
    el.innerHTML = `
      <div class="term">
        <div class="term-bar"><i></i><i></i><i></i><span>Xttack@hosting — bash</span></div>
        <div class="term-out" id="termOut"></div>
        <div class="term-line">
          <span class="term-ps">Xttack@hosting:<span class="tp">~</span>$</span>
          <input class="term-in" spellcheck="false" autocomplete="off" autocapitalize="off">
        </div>
      </div>`;

    const out = el.querySelector('#termOut');
    const input = el.querySelector('.term-in');
    const print = (t, cls = '') => {
      const d = document.createElement('div');
      d.className = 'tl ' + cls;
      d.textContent = t;
      out.appendChild(d);
      out.scrollTop = out.scrollHeight;
    };
    const printHtml = (h) => {
      const d = document.createElement('div');
      d.className = 'tl';
      d.innerHTML = h;
      out.appendChild(d);
      out.scrollTop = out.scrollHeight;
    };

    ['Xttack TTY v1.0 - REAL command execution on the server (safe mode).',
      'Type "help" for the command list.'].forEach(print);

    const hist = [];
    let hi = -1;
    const monId = (s) => {
      const q = String(s || '').toLowerCase();
      return state.monitors.find((m) => String(m.id) === q || m.name.toLowerCase().includes(q) || m.url.toLowerCase().includes(q));
    };

    /* real execution backend */
    async function ttyExec(raw) {
      try {
        const res = await api('tty', { method: 'POST', body: { cmd: raw } });
        const r = res.result || {};
        const text = (r.out || '').replace(/\r/g, '');
        if (text) print(text, r.code ? 'terr' : '');
        if (r.code && !text) print('exit code ' + r.code, 'terr');
      } catch (e) {
        print('error: ' + e.message, 'terr');
      }
    }

    /* ================= realtime security refresh ================= */
    async function secLive() {
      if (state.view !== 'keamanan') return;
      try {
        const d = await api('security');
        secScans = d.scans || {};
        const att = await api('attacks');
        window.__attLive = att;
      } catch { }
    }

    input.addEventListener('keydown', async (e) => {
      if (e.key === 'Enter') {
        const raw = input.value.trim();
        input.value = '';
        print(`$ ${raw}`);
        if (!raw) return;
        hist.unshift(raw);
        hi = -1;
        const [cmd, ...args] = raw.split(/\s+/);
        if (cmd === 'clear') { out.innerHTML = ''; return; }
        if (cmd === 'exit') { print('session closed; refresh to reconnect'); setTimeout(() => switchView('dashboard'), 900); return; }
        await ttyExec(raw);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (hi === -1 && hist.length) hi = 0;
        else if (hi < hist.length - 1) hi++;
        if (hist[hi] !== undefined) input.value = hist[hi];
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (hi > 0) { hi--; input.value = hist[hi] || ''; }
        else { hi = -1; input.value = ''; }
      }
    });
    setTimeout(() => input.focus(), 250);
    el.addEventListener('click', () => input.focus());
  }

  function renderStatistik() {
    const ms = state.monitors;
    if (!ms.length) {
      $('#view-statistik').innerHTML = '<div class="empty"><div class="empty-icon">&#9789;</div><h2>Tidak ada data</h2><p class="dim">Add a monitor first.</p></div>';
      return;
    }
    const rows = ms.map((m) => {
      const up24 = m.uptime_24h >= 0 ? m.uptime_24h.toFixed(2) + '%' : '—';
      const up7 = m.uptime_7d >= 0 ? m.uptime_7d.toFixed(2) + '%' : '—';
      const spark = m.spark.map((p2) => p2.l).filter((v) => v > 0);
      const avg = m.latency_ms ? m.latency_ms : (spark.length ? Math.round(spark.reduce((a, b) => a + b, 0) / spark.length) : 0);
      let worst = 0;
      m.spark.forEach((p2) => { worst = Math.max(worst, p2.l); });
      const errCount = m.bar.filter((c) => c.down).length;
      return `<tr>
        <td><b>${esc(m.name)}</b><div class="sub">${esc(m.url.replace(/^https?:\/\//, ''))}</div></td>
        <td>${chipHtml(m.status)}</td>
        <td>${up24}</td><td>${up7}</td>
        <td>${avg ? avg + ' ms' : '—'}</td>
        <td>${worst ? worst + ' ms' : '—'}</td>
        <td>${errCount ? esc(errCount) + 'x' : '0x'}</td>
      </tr>`;
    }).join('');
    $('#view-statistik').innerHTML = `
      <div class="list-head"><h2>Per-domain statistics</h2><div class="dim" style="font-size:12.5px">Refreshed live via SSE</div></div>
      <div style="overflow-x:auto"><table class="stat-table">
        <thead><tr><th>Website</th><th>Status</th><th>Uptime 24h</th><th>Uptime 7d</th><th>Latensi</th><th>Peak</th><th>Down (last 60)</th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>`;
  }

  function chipHtml(st) {
    const chip = { up: ['Operational', 'chip-ok'], degraded: ['Slow', 'chip-warn'], down: ['DOWN', 'chip-bad'], unknown: ['Pending', 'chip-idle'] }[st];
    return `<span class="chip ${chip[1]}"><span></span>${chip[0]}</span>`;
  }

  function switchViewEnsure(name) {
    switchView(name);
  }

  /* ================= init nav ================= */
  document.querySelectorAll('.nav .nav-item[data-view]').forEach((el) => {
    el.onclick = () => switchView(el.dataset.view);
  });
  document.querySelectorAll('.nav-sub a').forEach((el) => {
    el.onclick = () => switchView(el.dataset.panel);
  });
  $('#toggleAdvanced').onclick = () => {
    const open = $('#subAdvanced').classList.toggle('open');
    $('#toggleAdvanced').setAttribute('aria-expanded', open);
  };
  $('#subAdvanced').classList.add('open');

  /* ================= init ================= */
  $('#loginForm').onsubmit = async (e) => {
    e.preventDefault();
    const err = $('#loginError');
    err.classList.add('hidden');
    try {
      const d = await api('login', { method: 'POST', body: { password: $('#loginPassword').value } });
      state.me = { ...state.me, csrf: d.csrf };
      showApp();
    } catch (ex) {
      err.textContent = ex.message;
      err.classList.remove('hidden');
    }
  };

  $('#btnLogout').onclick = async () => {
    try { await api('logout', { method: 'POST', body: {} }); } catch { }
    clearInterval(state.poll);
    clearInterval(legacyPoll);
    if (state.es) state.es.close();
    state.me = null;
    showLogin();
  };

  $('#btnAdd').onclick = () => openMonModal();
  $('#searchInput').oninput = (e) => { searchQ = e.target.value; renderList(); };
  $('#monForm').onsubmit = submitMon;
  $('#monCancel').onclick = closeMonModal;
  $('#monModalClose').onclick = closeMonModal;
  $('#btnInsights').onclick = openInsight;
  $('#insightClose').onclick = () => $('#modalInsight').classList.add('hidden');
  $('#modalInsight').onclick = (e) => { if (e.target.id === 'modalInsight') $('#modalInsight').classList.add('hidden'); };
  $('#modalMon').onclick = (e) => { if (e.target.id === 'modalMon') closeMonModal(); };
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeMonModal(); $('#modalInsight').classList.add('hidden'); }
  });

  $('#chatFab').onclick = () => {
    chatOpen = !chatOpen;
    $('#chatPanel').classList.toggle('hidden', !chatOpen);
    $('#chatFab').classList.toggle('hidden', chatOpen);
    if (chatOpen) $('#chatField').focus();
  };
  $('#chatClose').onclick = () => {
    chatOpen = false;
    $('#chatPanel').classList.add('hidden');
    $('#chatFab').classList.remove('hidden');
  };
  $('#chatForm').onsubmit = sendChat;

  (async () => {
    try {
      state.me = await api('me');
      $('#brandName').textContent = state.me.site || 'Xttack';
      if (!state.me.ai) {
        $('#chatFab').title = 'AI is not configured in config.php';
      }
      if (state.me.auth) showApp();
      else showLogin();
    } catch {
      showLogin();
    }
  })();
})();



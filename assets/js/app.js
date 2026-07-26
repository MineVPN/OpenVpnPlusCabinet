/* ══════════════════════════════════════════════════════════════
   OVPNPlus — скрипты панели

   Никаких библиотек. Панель живёт на сервере, который сам маршрутизирует
   трафик: когда туннель падает, внешняя сеть может быть недоступна —
   а панель нужна именно в этот момент и обязана работать офлайн.
   ══════════════════════════════════════════════════════════════ */
'use strict';

/* ── Выбор файла конфигурации ────────────────────────────────── */
function ovpInitDrop(dropId, inputId, mainId) {
  var drop  = document.getElementById(dropId),
      input = document.getElementById(inputId),
      main  = document.getElementById(mainId);
  if (!drop || !input || !main) return;

  function show(name) {
    main.className = 'drop__file';
    main.textContent = name;
  }

  ['dragenter', 'dragover'].forEach(function (e) {
    drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.add('drop--hot'); });
  });
  ['dragleave', 'drop'].forEach(function (e) {
    drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.remove('drop--hot'); });
  });
  drop.addEventListener('drop', function (ev) {
    if (ev.dataTransfer.files.length) {
      input.files = ev.dataTransfer.files;
      show(ev.dataTransfer.files[0].name);
    }
  });
  input.addEventListener('change', function () {
    if (input.files.length) show(input.files[0].name);
  });

  // Скрытый input с required браузер не может подсветить и молча отменяет
  // отправку. Поэтому пустую форму перехватываем сами.
  if (input.form) {
    input.form.addEventListener('submit', function (ev) {
      if (!input.files.length) {
        ev.preventDefault();
        main.className = 'drop__main drop__main--err';
        main.textContent = 'Сначала выберите файл .ovpn';
      }
    });
  }
}

/* ── Живой пинг до сервера второго VPN ───────────────────────────
   Проверяем напрямую через канал сервера: адрес второго впн обязан
   быть доступен именно так, иначе туннель к нему не построится. */
function ovpInitRemotePing(host) {
  var dot = document.getElementById('ping-dot'),
      txt = document.getElementById('ping-text');
  if (!host || !dot || !txt) return;

  function check() {
    fetch('api/ping.php?iface=nic&host=' + encodeURIComponent(host),
          { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (t) {
        var ms = parseFloat(t);
        if (t.indexOf('NO PING') === -1 && !isNaN(ms)) {
          dot.className = 'dot dot--ok';
          txt.textContent = 'отвечает за ' + ms.toFixed(0) + ' мс';
        } else if (t.trim() === 'OK') {
          dot.className = 'dot dot--ok';
          txt.textContent = 'отвечает';
        } else {
          dot.className = 'dot dot--err';
          txt.textContent = 'не отвечает';
        }
      })
      .catch(function () {
        dot.className = 'dot dot--warn';
        txt.textContent = 'не удалось проверить';
      });
  }

  check();
  setInterval(check, 10000);
}

/* ── Журнал ──────────────────────────────────────────────────── */
function ovpInitLogs() {
  var box      = document.getElementById('log-box'),
      meta     = document.getElementById('log-meta'),
      problems = document.getElementById('log-problems'),
      auto     = document.getElementById('log-auto'),
      refresh  = document.getElementById('log-refresh');
  if (!box) return;

  var timer = null;

  function render(rows) {
    if (!rows.length) {
      box.innerHTML = '';
      var e = document.createElement('div');
      e.className = 'empty';
      e.textContent = problems && problems.checked
        ? 'Проблем не зафиксировано.'
        : 'Журнал пока пуст.';
      box.appendChild(e);
      return;
    }

    // Держим прокрутку внизу, если пользователь оттуда не уходил.
    var atBottom = box.scrollTop + box.clientHeight >= box.scrollHeight - 40;

    box.innerHTML = '';
    rows.forEach(function (r) {
      var line = document.createElement('div');
      line.className = 'logline' + (r.level ? ' logline--' + r.level : '');

      if (r.time) {
        var t = document.createElement('span');
        t.className = 'logline__t';
        t.textContent = r.time;
        line.appendChild(t);
      }
      if (r.source) {
        var s = document.createElement('span');
        s.className = 'logline__s';
        s.textContent = r.source;
        line.appendChild(s);
      }
      var m = document.createElement('span');
      m.className = 'logline__m';
      m.textContent = r.text;
      line.appendChild(m);

      box.appendChild(line);
    });

    if (atBottom) box.scrollTop = box.scrollHeight;
  }

  function load() {
    var url = 'api/logs.php?lines=600';
    if (problems && problems.checked) url += '&only=problems';

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 403) { window.location = 'login.php'; return null; }
        return r.json();
      })
      .then(function (d) {
        if (!d) return;
        if (!d.ok) { return; }
        render(d.rows || []);
        if (meta) {
          meta.textContent = (d.count || 0) + ' строк · ' + (d.at || '');
        }
      })
      .catch(function () { /* сеть моргнула — покажем на следующем цикле */ });
  }

  function retime() {
    if (timer) { clearInterval(timer); timer = null; }
    if (auto && auto.checked) timer = setInterval(load, 5000);
  }

  if (problems) problems.addEventListener('change', load);
  if (auto)     auto.addEventListener('change', retime);
  if (refresh)  refresh.addEventListener('click', load);
  window.addEventListener('beforeunload', function () { if (timer) clearInterval(timer); });

  load();
  retime();
}

/* ── Пинг-монитор ────────────────────────────────────────────── */
function ovpInitPinger() {
  var hostEl  = document.getElementById('ping-host'),
      ifaceEl = document.getElementById('ping-iface'),
      startEl = document.getElementById('ping-start'),
      stopEl  = document.getElementById('ping-stop'),
      logEl   = document.getElementById('ping-log');
  if (!hostEl || !startEl || !stopEl || !logEl) return;

  var timer = null, s = null;

  function reset() {
    s = { all: 0, ok: 0, fail: 0, min: Infinity, max: -Infinity, sum: 0 };
  }

  function set(id, v) {
    var el = document.getElementById(id);
    if (el) el.textContent = v;
  }

  function line(text, kind) {
    var p = document.createElement('div');
    p.className = 'logline' + (kind ? ' logline--' + kind : '');
    p.textContent = text;
    logEl.appendChild(p);
    // Не даём журналу расти бесконечно при многочасовом мониторинге.
    while (logEl.childElementCount > 500) logEl.removeChild(logEl.firstElementChild);
    logEl.scrollTop = logEl.scrollHeight;
  }

  function render(last) {
    set('p-all', s.all);
    set('p-ok', s.ok);
    set('p-fail', s.fail);
    var total = s.ok + s.fail;
    set('p-loss', (total ? (s.fail / total * 100) : 0).toFixed(1) + '%');
    set('p-min', s.min === Infinity ? '—' : s.min.toFixed(1));
    set('p-max', s.max === -Infinity ? '—' : s.max.toFixed(1));
    var avg = s.sum / s.ok;
    set('p-avg', isNaN(avg) ? '—' : avg.toFixed(1));
    set('p-last', isNaN(last) ? '—' : last.toFixed(1));
  }

  function tick() {
    var host  = hostEl.value.trim();
    var iface = ifaceEl ? ifaceEl.value : '';
    var url   = 'api/ping.php?host=' + encodeURIComponent(host);
    if (iface) url += '&iface=' + encodeURIComponent(iface);

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 403) { window.location = 'login.php'; return null; }
        return r.text();
      })
      .then(function (t) {
        if (t === null) return;
        var now = new Date().toLocaleTimeString();
        var ms  = parseFloat(t);
        s.all++;

        if (t.indexOf('NO PING') !== -1 || isNaN(ms)) {
          s.fail++;
          line('[' + now + '] ' + host + ' — нет ответа', 'err');
          render(NaN);
          return;
        }

        s.ok++;
        s.sum += ms;
        s.min = Math.min(s.min, ms);
        s.max = Math.max(s.max, ms);
        var avg = s.sum / s.ok;
        line('[' + now + '] ' + host + ' — ' + ms.toFixed(1) + ' мс',
             (!isNaN(avg) && ms > avg + 20) ? 'warn' : 'ok');
        render(ms);
      })
      .catch(function () {
        s.all++; s.fail++;
        line('[' + new Date().toLocaleTimeString() + '] ошибка запроса', 'err');
        render(NaN);
      });
  }

  startEl.addEventListener('click', function () {
    if (!hostEl.value.trim()) return;
    if (timer) clearInterval(timer);
    logEl.innerHTML = '';
    reset();
    render(NaN);
    tick();
    timer = setInterval(tick, 1000);
  });

  stopEl.addEventListener('click', function () {
    if (timer) { clearInterval(timer); timer = null; }
  });

  // Уходя со страницы, гасим таймер, чтобы не слать запросы в фоне.
  window.addEventListener('beforeunload', function () { if (timer) clearInterval(timer); });

  reset();
}

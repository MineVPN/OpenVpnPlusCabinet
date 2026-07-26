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

/* ── Журнал событий ──────────────────────────────────────────── */
function ovpInitLogs() {
  var $ = function (id) { return document.getElementById(id); };

  var box      = $('log'),
      linesSel = $('lines'),
      onlyBad  = $('only-problems'),
      auto     = $('auto'),
      btn      = $('refresh');
  if (!box) return;

  var timer = null, busy = false;

  function human(b) {
    if (b < 1024) return b + ' Б';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' КБ';
    return (b / 1048576).toFixed(1) + ' МБ';
  }

  function render(rows) {
    box.textContent = '';
    if (!rows.length) {
      var e = document.createElement('div');
      e.className = 'faint';
      e.textContent = (onlyBad && onlyBad.checked)
        ? 'Проблем не зафиксировано.'
        : 'Пока ничего не записано.';
      box.appendChild(e);
      return;
    }

    // Собираем во фрагменте: при 1500 строках вставка по одной
    // заставляет браузер пересчитывать раскладку на каждой.
    var frag = document.createDocumentFragment();
    rows.forEach(function (r) {
      var el = document.createElement('div');
      el.className = 'line' + (r.level ? ' line--' + r.level : '');

      var t = document.createElement('span');
      t.className = 'line__t';
      t.textContent = r.time;

      var s = document.createElement('span');
      s.className = 'line__src';
      s.textContent = r.source;

      var m = document.createElement('span');
      m.className = 'line__m';
      m.textContent = r.text;

      el.appendChild(t); el.appendChild(s); el.appendChild(m);
      frag.appendChild(el);
    });
    box.appendChild(frag);
    box.scrollTop = box.scrollHeight;
  }

  function set(id, v) {
    var el = $(id);
    if (el) el.textContent = v;
  }

  function load() {
    // Защита от наложения запросов: на большом журнале ответ может
    // прийти позже следующего тика автообновления.
    if (busy) return;
    busy = true;

    var url = 'api/logs.php?lines=' + encodeURIComponent(linesSel ? linesSel.value : 500) +
              ((onlyBad && onlyBad.checked) ? '&only=problems' : '');

    fetch(url, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 403) { window.location = 'login.php'; return null; }
        return r.json();
      })
      .then(function (d) {
        if (!d) return;
        if (!d.ok) throw new Error();
        set('count', d.count);
        set('size', human(d.size));
        set('at', d.at);
        render(d.rows || []);
      })
      .catch(function () {
        box.innerHTML = '';
        var el = document.createElement('div');
        el.className = 'line line--err';
        var m = document.createElement('span');
        m.className = 'line__m';
        m.textContent = 'Не удалось загрузить события.';
        el.appendChild(document.createElement('span'));
        el.appendChild(document.createElement('span'));
        el.appendChild(m);
        box.appendChild(el);
      })
      .then(function () { busy = false; });
  }

  function retime() {
    if (timer) { clearInterval(timer); timer = null; }
    if (auto && auto.checked) timer = setInterval(load, 5000);
  }

  if (linesSel) linesSel.addEventListener('change', load);
  if (onlyBad)  onlyBad.addEventListener('change', load);
  if (auto)     auto.addEventListener('change', retime);
  if (btn)      btn.addEventListener('click', load);
  window.addEventListener('beforeunload', function () { if (timer) clearInterval(timer); });

  load();
  retime();
}

/* ── Пинг-монитор ────────────────────────────────────────────── */
function ovpInitPinger() {
  var $ = function (id) { return document.getElementById(id); };

  var hostEl = $('host'), pathEl = $('path'),
      goEl   = $('go'),   stopEl = $('stop'),
      logEl  = $('pinglog');
  if (!hostEl || !goEl || !stopEl || !logEl) return;

  var timer = null,
      all = 0, ok = 0, lost = 0,
      min = Infinity, max = -Infinity, sum = 0;

  function set(id, v) {
    var el = $(id);
    if (el) el.textContent = v;
  }

  function reset() {
    all = ok = lost = sum = 0;
    min = Infinity; max = -Infinity;
    set('s-all', '0'); set('s-ok', '0'); set('s-lost', '0'); set('s-loss', '0%');
    set('s-min', '—'); set('s-avg', '—'); set('s-max', '—'); set('s-last', '—');
    logEl.innerHTML = '';
  }

  function add(text, cls) {
    var p = document.createElement('p');
    p.textContent = text;
    if (cls) p.className = cls;
    logEl.appendChild(p);
    // Ограничение памяти: держим последние 500 замеров.
    // Считаем и удаляем именно элементы — с firstChild любой текстовый узел
    // в начале контейнера превратил бы это в вечный цикл.
    while (logEl.childElementCount > 500) logEl.removeChild(logEl.firstElementChild);
    logEl.scrollTop = logEl.scrollHeight;
  }

  function measure(target, via) {
    var url = 'api/ping.php?host=' + encodeURIComponent(target);
    if (via) url += '&iface=' + encodeURIComponent(via);

    fetch(url, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 403) { window.location = 'login.php'; return null; }
        return r.text();
      })
      .then(function (data) {
        if (data === null) return;
        var now = new Date().toLocaleTimeString();
        all++;
        var ms = NaN;

        if (data.indexOf('NO PING') === -1) {
          ok++;
          ms = parseFloat(data);
          if (!isNaN(ms)) {
            min = Math.min(min, ms);
            max = Math.max(max, ms);
            sum += ms;
            var avg = sum / ok;
            // Всплеск: заметно выше среднего — помечаем жёлтым.
            add(now + '  ·  ping → ' + target + '  ·  ' + ms.toFixed(1) + ' мс',
                ms > avg + 20 ? 'slow' : 'ok');
          } else {
            add(now + '  ·  ping → ' + target + '  ·  ответ есть', 'ok');
          }
        } else {
          lost++;
          add(now + '  ·  ping → ' + target + '  ·  нет ответа', 'fail');
        }

        set('s-all', all);
        set('s-ok', ok);
        set('s-lost', lost);
        set('s-loss', (all === 0 ? 0 : lost / all * 100).toFixed(1) + '%');
        set('s-min', (min === Infinity) ? '—' : min.toFixed(1));
        set('s-max', (max === -Infinity) ? '—' : max.toFixed(1));
        var a = sum / ok;
        set('s-avg', isNaN(a) ? '—' : a.toFixed(1));
        set('s-last', isNaN(ms) ? '—' : ms.toFixed(1));
      })
      .catch(function () {
        all++; lost++;
        set('s-all', all);
        set('s-lost', lost);
        add(new Date().toLocaleTimeString() + '  ·  панель не ответила', 'fail');
      });
  }

  goEl.addEventListener('click', function () {
    var target = hostEl.value.trim();
    if (!target) { add('Введите адрес для проверки', 'fail'); return; }
    if (timer) clearInterval(timer);
    reset();
    var via = pathEl ? pathEl.value : '';
    measure(target, via);
    timer = setInterval(function () { measure(target, via); }, 1000);
  });

  stopEl.addEventListener('click', function () {
    if (timer) { clearInterval(timer); timer = null; add('остановлено'); }
  });

  // Enter в поле адреса запускает проверку.
  hostEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); goEl.click(); }
  });

  // Уходя со страницы, гасим таймер, чтобы не слать запросы в фоне.
  window.addEventListener('beforeunload', function () { if (timer) clearInterval(timer); });
}

<?php
/**
 * OVPNPlus — страница «Пинг».
 *
 * Замер задержки до адреса раз в секунду с накоплением статистики.
 *
 * По умолчанию пинг идёт с адреса шлюза — то есть тем же путём, что
 * и трафик конечного клиента. Два остальных варианта задают путь жёстко:
 * сравнение их результатов сразу показывает, где именно рвётся связь.
 */

require_once __DIR__ . '/../includes/ovp_helpers.php';
ovp_require_auth();

$net = ovp_server_net();
?>

<div class="page-head">
  <div class="page-head__title"><h1>Пинг</h1></div>
  <p class="page-head__note">
    По умолчанию пинг идёт тем же путём, что и трафик клиента: через второй впн,
    если он подключён, и напрямую — если нет или адрес в списке обхода.
    Запрос отправляется с адреса <span class="data"><?= htmlspecialchars($net['gw']) ?></span>.
    Остальные варианты — чтобы сравнить и понять, где именно рвётся связь.
  </p>
</div>

<div class="stack">

  <!-- ══ Управление ══ -->
  <div class="card">
    <div class="ping-controls">
      <div class="field">
        <label class="label" for="host">Адрес или домен</label>
        <input type="text" id="host" class="input" value="8.8.8.8" placeholder="8.8.8.8 или google.com">
      </div>
      <div class="field">
        <label class="label" for="path">Путь</label>
        <select id="path" class="select">
          <option value="">По умолчанию (как у клиента)</option>
          <option value="tun1">Только через второй впн</option>
          <option value="nic">Только напрямую</option>
        </select>
      </div>
      <div class="field">
        <span class="label">&nbsp;</span>
        <div class="ping-controls__btns">
          <button type="button" id="go" class="btn btn--primary">Старт</button>
          <button type="button" id="stop" class="btn btn--danger">Стоп</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ Показатели ══ -->
  <div class="card">
    <div class="stats stats--top">
      <div class="stat">
        <span class="stat__k">Отправлено</span>
        <span class="stat__v" id="s-all">0</span>
      </div>
      <div class="stat stat--ok">
        <span class="stat__k">Успешно</span>
        <span class="stat__v" id="s-ok">0</span>
      </div>
      <div class="stat stat--err">
        <span class="stat__k">Потеряно</span>
        <span class="stat__v" id="s-lost">0</span>
      </div>
      <div class="stat stat--warn">
        <span class="stat__k">Потери</span>
        <span class="stat__v" id="s-loss">0%</span>
      </div>
    </div>

    <div class="stats">
      <div class="stat stat--info">
        <span class="stat__k">Минимум</span>
        <span><span class="stat__v" id="s-min">—</span><span class="stat__u">мс</span></span>
      </div>
      <div class="stat stat--info">
        <span class="stat__k">Средний</span>
        <span><span class="stat__v" id="s-avg">—</span><span class="stat__u">мс</span></span>
      </div>
      <div class="stat stat--info">
        <span class="stat__k">Максимум</span>
        <span><span class="stat__v" id="s-max">—</span><span class="stat__u">мс</span></span>
      </div>
      <div class="stat">
        <span class="stat__k">Последний</span>
        <span><span class="stat__v" id="s-last">—</span><span class="stat__u">мс</span></span>
      </div>
    </div>
  </div>

  <!-- ══ Поток замеров ══ -->
  <div class="card card--pinglog">
    <div id="pinglog" class="pinglog"></div>
  </div>
</div>

<script>ovpInitPinger();</script>

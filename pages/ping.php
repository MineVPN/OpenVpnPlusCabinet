<?php
/**
 * OVPNPlus — страница «Пинг».
 *
 * Три режима проверки отвечают на три разных вопроса:
 *   «как у клиента» — что реально увидит подключённый пользователь
 *   «через второй впн» — жив ли сам туннель
 *   «напрямую» — жив ли канал самого сервера
 * Сравнение этих трёх ответов сразу показывает, где именно обрыв.
 */

require_once __DIR__ . '/../includes/ovp_helpers.php';

ovp_require_auth();

$net = ovp_server_net();
?>

<div class="page-head">
  <div class="page-head__title"><h1>Пинг</h1></div>
  <p class="page-head__note">
    Проверка задержки и потерь. Режим «как у клиента» отправляет запрос
    с адреса <span class="data"><?= htmlspecialchars($net['gw']) ?></span> —
    по тому же пути, которым идёт трафик подключённых пользователей.
  </p>
</div>

<div class="stack">

  <div class="card">
    <form class="row row--wrap" id="ping-form" onsubmit="return false">
      <input type="text" id="ping-host" class="input grow" value="8.8.8.8"
             placeholder="адрес или имя хоста">
      <select id="ping-iface" class="input input--select">
        <option value="">как у клиента</option>
        <option value="tun1">через второй впн</option>
        <option value="nic">напрямую с сервера</option>
      </select>
      <button type="button" class="btn btn--ok" id="ping-start">Старт</button>
      <button type="button" class="btn btn--danger" id="ping-stop">Стоп</button>
    </form>
  </div>

  <div class="card">
    <div class="facts facts--grid">
      <div class="fact"><span class="fact__k">Отправлено</span><span class="fact__v" id="p-all">0</span></div>
      <div class="fact"><span class="fact__k">Успешно</span><span class="fact__v fact__v--ok" id="p-ok">0</span></div>
      <div class="fact"><span class="fact__k">Потеряно</span><span class="fact__v fact__v--err" id="p-fail">0</span></div>
      <div class="fact"><span class="fact__k">Потери</span><span class="fact__v" id="p-loss">0%</span></div>
      <div class="fact"><span class="fact__k">Мин, мс</span><span class="fact__v" id="p-min">—</span></div>
      <div class="fact"><span class="fact__k">Среднее, мс</span><span class="fact__v" id="p-avg">—</span></div>
      <div class="fact"><span class="fact__k">Макс, мс</span><span class="fact__v" id="p-max">—</span></div>
      <div class="fact"><span class="fact__k">Последний, мс</span><span class="fact__v" id="p-last">—</span></div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2 class="card__title">Отклики</h2></div>
    <div class="log log--ping" id="ping-log">
      <div class="empty">Нажмите «Старт», чтобы начать проверку.</div>
    </div>
  </div>
</div>

<script>ovpInitPinger();</script>

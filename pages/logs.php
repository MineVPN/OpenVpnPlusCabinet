<?php
/**
 * OVPNPlus — страница «События».
 *
 * Один список вместо вкладок. Панель и healthcheck-демон пишут в общий
 * файл с меткой источника, поэтому всё идёт одной хронологией — при
 * разборе аварии важен порядок, а не категория.
 */

require_once __DIR__ . '/../includes/ovp_helpers.php';
ovp_require_auth();
?>

<div class="page-head">
  <div class="page-head__title"><h1>События</h1></div>
  <p class="page-head__note">
    Что делали вы и что делал сервер сам — одной лентой, новые записи снизу.
    Здесь видно, когда падал второй впн, когда восстанавливался и почему клиенты
    оставались без туннеля.
  </p>
</div>

<div class="card">
  <div class="card__head">
    <div class="row">
      <label class="check">
        <input type="checkbox" id="only-problems"> Только проблемы
      </label>
      <select id="lines" class="select select--auto">
        <option value="200">200 строк</option>
        <option value="500" selected>500 строк</option>
        <option value="1500">1500 строк</option>
      </select>
    </div>
    <div class="row">
      <label class="check"><input type="checkbox" id="auto" checked> Обновлять</label>
      <button id="refresh" class="btn btn--sm">Обновить</button>
    </div>
  </div>

  <div id="log" class="console">
    <div class="faint">Загружаем…</div>
  </div>

  <div class="log-meta">
    <span>Записей: <span id="count" class="data">0</span></span>
    <span>Размер: <span id="size" class="data">0</span></span>
    <span>Обновлено: <span id="at" class="data">—</span></span>
  </div>
</div>

<script>ovpInitLogs();</script>

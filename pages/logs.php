<?php
/**
 * OVPNPlus — страница «События».
 *
 * Один хронологический поток: панель и healthcheck-демон пишут в общий
 * файл журнала, различаясь только меткой источника. Вкладок и разделения
 * по типам событий нет сознательно — при разборе аварии важен порядок,
 * а не категория.
 */

require_once __DIR__ . '/../includes/ovp_helpers.php';

ovp_require_auth();

$size = is_readable(OVP_LOG_FILE) ? (int) @filesize(OVP_LOG_FILE) : 0;
?>

<div class="page-head">
  <div class="page-head__title"><h1>События</h1></div>
  <p class="page-head__note">
    Что делали вы и что делал сервер. Здесь видно, когда падал второй впн,
    когда восстанавливался и почему клиенты оставались без туннеля.
  </p>
</div>

<div class="card">
  <div class="card__head">
    <h2 class="card__title">Журнал</h2>
    <span class="dim data" id="log-meta"><?= $size > 0 ? round($size / 1024) . ' КБ' : '—' ?></span>
  </div>

  <div class="row row--wrap">
    <label class="check">
      <input type="checkbox" id="log-problems">
      <span>Только проблемы</span>
    </label>
    <label class="check">
      <input type="checkbox" id="log-auto" checked>
      <span>Обновлять автоматически</span>
    </label>
    <span class="grow"></span>
    <button type="button" class="btn btn--sm" id="log-refresh">Обновить</button>
  </div>

  <div class="log" id="log-box">
    <div class="empty">Загружаем журнал…</div>
  </div>
</div>

<script>ovpInitLogs();</script>

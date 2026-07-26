<?php
/**
 * OVPNPlus — состояние второго VPN в JSON.
 *
 * Нужен, чтобы плашка и подпись на странице «Подключение» обновлялись сами.
 * Без этого они отрисовывались один раз при загрузке страницы: туннель уже
 * поднялся, а панель продолжала показывать «Проверяется» до нажатия F5.
 *
 * Расчёт состояния не дублируется — он живёт в ovp_state_view().
 */

require_once __DIR__ . '/../includes/ovp_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (ovp_session_invalid_reason() !== '') {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

// Снимаем блокировку сессии: страница опрашивает этот адрес раз в несколько
// секунд, и держать её всё это время значит подвешивать остальные вкладки.
session_write_close();

$view = ovp_state_view();

echo json_encode([
    'ok'         => true,
    'kind'       => $view['kind'],
    'text'       => $view['text'],
    'note'       => $view['note'],
    'connection' => $view['connection'],
    'sig'        => $view['sig'],
], JSON_UNESCAPED_UNICODE);

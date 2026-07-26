<?php
/**
 * OpenVPN+ — выход из панели.
 *
 * Токен в query обязателен: выход — единственная изменяющая состояние
 * операция, доступная по обычной ссылке. Без токена посторонняя страница
 * могла бы разлогинить администратора картинкой <img src="…/logout.php">.
 */

require_once __DIR__ . '/includes/ovp_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    $token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
    if (empty($_SESSION['csrf_logout']) || !hash_equals($_SESSION['csrf_logout'], $token)) {
        http_response_code(400);
        exit('Недействительный токен выхода. Вернитесь в панель и повторите.');
    }
    ovp_log('OK', 'Выход из панели');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: login.php');
exit();

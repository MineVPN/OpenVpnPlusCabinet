<?php
/**
 * OVPNPlus — проверка доступности адреса.
 *
 * GET:
 *   host  — IPv4 или имя хоста
 *   iface — пусто (как у клиента) | tun1 (через второй впн) | nic (напрямую)
 *
 * Возвращает число миллисекунд, OK или NO PING.
 *
 * Безопасность: в прошлой версии панели $host уходил в exec() без проверок
 * и без авторизации — это было выполнение произвольных команд на сервере
 * любым, кто знал адрес панели. Теперь: сессия обязательна, host валидируется,
 * имя интерфейса берётся из фиксированного набора, всё экранируется.
 */

require_once __DIR__ . '/../includes/ovp_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (ovp_session_invalid_reason() !== '') {
    http_response_code(403);
    die('NO PING');
}

// Сессия больше не нужна, а файловый обработчик держит на ней эксклюзивную
// блокировку до конца запроса. Без этого страница «Пинг» с опросом раз
// в секунду встала бы в очередь сама к себе, а на время загрузки конфига
// второго VPN (до полуминуты) замерла бы вся панель целиком.
session_write_close();

// is_string обязателен: ?host[]=x на PHP 8 даёт TypeError в trim()
// и HTTP 500 вместо честного отказа.
$host  = is_string($_GET['host']  ?? null) ? trim($_GET['host'])  : '';
$which = is_string($_GET['iface'] ?? null) ? trim($_GET['iface']) : '';

if ($host === '') die('NO PING');

$isIp   = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
$isHost = strlen($host) <= 255
       && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]{0,253}[a-zA-Z0-9])?$/', $host) === 1;
if (!$isIp && !$isHost) die('NO PING');

// Интерфейс не берём из ввода напрямую — только фиксированный выбор.
//
// ПО УМОЛЧАНИЮ пингуем С АДРЕСА ШЛЮЗА (10.6.0.1 или другой — берётся
// из server.conf). Это воспроизводит путь конечного клиента: адрес шлюза
// лежит в клиентской подсети, поэтому пакет попадает под те же правила
// policy routing, что и трафик клиентов:
//
//   • есть второй впн  → from <подсеть> table 120 → уходит в туннель
//   • второго впн нет  → таблица 120 пуста → провал в main → напрямую
//   • адрес в обходе   → to <адрес> table main preference 30000
//                        срабатывает раньше (30000 < 32765) → напрямую
//
// Без -I пинг ушёл бы по маршруту по умолчанию САМОГО СЕРВЕРА — всегда
// напрямую через NIC, что не отражает реальность клиента.
$via = '';
if ($which === 'tun1') {
    $via = 'tun1';                      // жёстко через туннель
} elseif ($which === 'nic') {
    $via = ovp_wan_iface();             // жёстко напрямую
} else {
    $net = ovp_server_net();
    $via = $net['gw'];                  // как у клиента
}

$cmd = 'ping -n -c 1 -W 1';
if ($via !== '') $cmd .= ' -I ' . escapeshellarg($via);
$cmd .= ' ' . escapeshellarg($host);

exec($cmd . ' 2>/dev/null', $out, $rc);

if ($rc === 0) {
    foreach ($out as $l) {
        if (preg_match('/time[=<]\s*([0-9.]+)\s*ms/i', $l, $m)) {
            echo $m[1];
            exit;
        }
    }
    echo 'OK';
} else {
    echo 'NO PING';
}

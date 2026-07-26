<?php
/**
 * OVPNPlus — Shared helpers
 *
 * Общая библиотека для всех файлов панели. Префикс ovp_ — чтобы
 * `grep -r "ovp_"` находил всё использование без ложных совпадений
 * со встроенными функциями PHP.
 *
 * Группы:
 *   • СЕТЬ      — ovp_server_net (динамическое определение подсети, БЕЗ хардкода)
 *   • ИНТЕРФЕЙС — ovp_iface_exists, ovp_bring_down, ovp_wait_up
 *   • STATE     — ovp_state_set (мьютекс с healthcheck-демоном)
 *   • КОНФИГ    — ovp_sanitize_config (вырезание опасных директив)
 *   • ЛОГИ      — ovp_log, ovp_tail
 *
 * НЕ вызывать напрямую через HTTP.
 */

// Защита от прямого вызова по HTTP (не зависит от .htaccess/AllowOverride).
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// ══════════════════════════════════════════════════════════════════
// ПАРАМЕТРЫ COOKIE СЕССИИ
// ══════════════════════════════════════════════════════════════════
//
// Выставлять ОБЯЗАТЕЛЬНО до session_start() — потом поздно.
// Поэтому все точки входа делают require_once этого файла ПЕРВЫМ.
//
//   HttpOnly — JS не видит cookie, то есть XSS не уводит сессию
//   SameSite=Strict — второй рубеж против CSRF: браузер не приложит
//                     cookie к запросу со стороннего сайта вообще
//   Secure — только под HTTPS. При работе по HTTP его ставить НЕЛЬЗЯ —
//            браузер перестанет слать cookie и вход сломается.
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['SERVER_PORT'] ?? '') === '443')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

// ══════════════════════════════════════════════════════════════════
// КОНСТАНТЫ
// ══════════════════════════════════════════════════════════════════

if (!defined('OVP_SRV_CONF'))  define('OVP_SRV_CONF',  '/etc/openvpn/server/server.conf');
if (!defined('OVP_UP_DIR'))    define('OVP_UP_DIR',    '/etc/openvpn/upstream');
if (!defined('OVP_UP_CONF'))   define('OVP_UP_CONF',   OVP_UP_DIR . '/tun1.conf');
if (!defined('OVP_UP_BAK'))    define('OVP_UP_BAK',    OVP_UP_DIR . '/tun1.conf.bak');
if (!defined('OVP_UP_AUTH'))   define('OVP_UP_AUTH',   OVP_UP_DIR . '/auth.txt');
if (!defined('OVP_UP_SVC'))    define('OVP_UP_SVC',    'ovpnplus-upstream');

// Каталог данных панели: root:www-data, 2770 (g+s).
//
// ЗАЧЕМ ОТДЕЛЬНЫЙ КАТАЛОГ: атомарная запись (tmp -> rename) требует права
// записи в КАТАЛОГ, а не в файл. Если файлы лежат в /var/www или
// /var/www/html (оба root:root 755), www-data может писать в сами файлы,
// но НЕ может создать *.tmp рядом. Запись молча проваливается:
//   • маршруты обхода не сохраняются (список остаётся пустым)
//   • мьютекс busy/running никогда не работает
// Плюс routes.txt не лежит в docroot — его не скачать по HTTP.
if (!defined('OVP_DATA_DIR'))     define('OVP_DATA_DIR',     '/var/www/ovpnplus');
if (!defined('OVP_STATE_FILE'))   define('OVP_STATE_FILE',   OVP_DATA_DIR . '/state');
if (!defined('OVP_ROUTES_FILE'))  define('OVP_ROUTES_FILE',  OVP_DATA_DIR . '/routes.txt');
if (!defined('OVP_SETTINGS_FILE'))define('OVP_SETTINGS_FILE',OVP_DATA_DIR . '/settings');
// Признак реального состояния туннеля, который пишет healthcheck-демон.
// Панель по одному лишь наличию интерфейса отличить рабочий туннель
// от режима обхода не может: интерфейс есть в обоих случаях.
if (!defined('OVP_HEALTH_FILE'))  define('OVP_HEALTH_FILE',  OVP_DATA_DIR . '/health');
if (!defined('OVP_TABLE_ID'))     define('OVP_TABLE_ID',     '120');
if (!defined('OVP_BYPASS_PREF'))  define('OVP_BYPASS_PREF',  30000);

// Адреса, которыми демон проверяет связь через туннель.
//
// Добавлять их в обход НЕЛЬЗЯ: тогда проверочный пинг уйдёт напрямую
// и будет успешен всегда — даже когда второй VPN лежит. Демон перестанет
// видеть аварии, а клиенты останутся без интернета без всякой реакции.
//
// Список обязан совпадать с PING_HOSTS в ovpn-healthcheck.sh.
if (!defined('OVP_PROBE_HOSTS')) {
    define('OVP_PROBE_HOSTS', '8.8.8.8,8.8.4.4,1.1.1.1,1.0.0.1,9.9.9.9');
}

if (!defined('OVP_LOG_DIR'))  define('OVP_LOG_DIR',  '/var/log/ovpnplus');
// ОДИН журнал на всю систему: панель и демон пишут в один файл,
// различаясь только меткой источника. Поэтому в интерфейсе один
// хронологический список без вкладок.
// Формат: [время] [уровень] [источник] текст по-русски.
if (!defined('OVP_LOG_FILE')) define('OVP_LOG_FILE', OVP_LOG_DIR . '/ovpnplus.log');
if (!defined('OVP_LOG_MAX'))  define('OVP_LOG_MAX',  2097152); // 2 MB
if (!defined('OVP_LOG_KEEP')) define('OVP_LOG_KEEP', 1500);    // строк после ротации
if (!defined('OVP_AUTH_FILE'))define('OVP_AUTH_FILE','/var/www/ovpnplus-auth');

// Срок жизни сессии — ОДИН понятный параметр.
// Привязки к IP нет сознательно: админ подключается к своему же VPN,
// исходный адрес меняется — и сессия падала бы при каждом подключении.
if (!defined('OVP_SESSION_MAX')) define('OVP_SESSION_MAX', 7 * 24 * 3600); // 7 суток

// ══════════════════════════════════════════════════════════════════
// АУТЕНТИФИКАЦИЯ
// ══════════════════════════════════════════════════════════════════

/**
 * Проверка пароля панели.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ФАЙЛ С ХЕШЕМ:
 * в прошлой версии пароль лежал открытым текстом прямо в login.php — то есть
 * В DOCROOT. Стоит PHP один раз не отработать (сломанный модуль, опечатка
 * в конфиге Apache, .php отдан как text/plain) — и пароль уезжает в браузер
 * целиком. Плюс его видно любому, кто получил чтение файлов.
 *
 * Теперь: хеш в /var/www/ovpnplus-auth — ВНЕ docroot, недоступен по HTTP.
 */
function ovp_check_password(string $input): bool {
    if ($input === '' || strlen($input) > 256) return false;
    if (!is_readable(OVP_AUTH_FILE)) return false;
    $hash = trim((string) @file_get_contents(OVP_AUTH_FILE));
    if ($hash === '') return false;
    return password_verify($input, $hash);
}

/**
 * Проверка сессии: залогинен ли и не истёк ли срок.
 *
 * @return string '' если сессия валидна, иначе причина для редиректа
 */
function ovp_session_invalid_reason(): string {
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return 'auth';
    }
    if (isset($_SESSION['login_time'])
        && (time() - (int) $_SESSION['login_time']) > OVP_SESSION_MAX) {
        return 'timeout';
    }
    return '';
}

/** Завершает сессию и уводит на логин. */
function ovp_session_kill(string $reason = ''): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    $q = ($reason !== '' && $reason !== 'auth') ? '?reason=' . urlencode($reason) : '';
    header('Location: login.php' . $q);
    exit();
}

/**
 * Гарантия авторизации для ЛЮБОЙ страницы — и когда она включена из
 * cabinet.php, и когда к ней обратились напрямую по HTTP.
 *
 * КРИТИЧНО: все файлы панели лежат в docroot, то есть доступны напрямую.
 * В прошлой версии openvpn.php не проверял авторизацию вовсе — POST на
 * /openvpn.php позволял без входа залить свой конфиг, то есть завернуть
 * весь трафик клиентов на чужой сервер.
 */
function ovp_require_auth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $reason = ovp_session_invalid_reason();
    if ($reason !== '') {
        ovp_session_kill($reason);
    }
}

// ══════════════════════════════════════════════════════════════════
// CSRF
// ══════════════════════════════════════════════════════════════════

/**
 * Токен на сессию. Без него любой сторонний сайт мог отправить форму
 * на панель от имени залогиненного админа — удалить конфиг, добавить
 * маршрут обхода, перезапустить туннель.
 */
function ovp_csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Скрытое поле для вставки в <form>. */
function ovp_csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(ovp_csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Отдельный токен для ссылки выхода.
 *
 * ЗАЧЕМ НЕ ОБЩИЙ: выход — единственная изменяющая состояние операция,
 * доступная по обычной ссылке, поэтому токен уезжает в query-строку,
 * а оттуда — в access.log Apache, в историю браузера и в заголовок
 * Referer. Общий CSRF-токен защищает загрузку конфига, перезапуск
 * туннеля и маршруты обхода: утечь он не должен.
 */
function ovp_logout_token(): string {
    if (empty($_SESSION['csrf_logout'])) {
        $_SESSION['csrf_logout'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_logout'];
}

/** Валиден ли токен в текущем POST. */
function ovp_csrf_valid(): bool {
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $sent);
}

/**
 * Жёсткая проверка для обработчиков POST: при провале — 403 и стоп.
 * Вызывать ДО любых side effects.
 */
function ovp_csrf_require(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    // При превышении post_max_size PHP обнуляет $_POST и $_FILES целиком.
    // Без этой ветки пользователь получил бы «неверный CSRF» вместо
    // настоящей причины — слишком большой файл.
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        exit('Файл слишком большой для загрузки на сервер.');
    }

    if (ovp_csrf_valid()) return;
    ovp_log('WARN', 'Отклонён POST без валидного CSRF-токена: ' . ($_SERVER['REQUEST_URI'] ?? '?'));
    http_response_code(403);
    exit('Forbidden: invalid CSRF token');
}

// ══════════════════════════════════════════════════════════════════
// СЕТЬ — динамическое определение подсети клиентов
// ══════════════════════════════════════════════════════════════════

/**
 * Определяет подсеть клиентов из server.conf — единственного источника правды.
 *
 * ЗАЧЕМ: если подсеть прописать строкой в инсталляторе, панели и демоне,
 * то стоит поставить сервер на другой адресации — и панель продолжит писать
 * правила для чужой подсети, а цепочка tun0 -> tun1 просто не заработает.
 *
 * OpenVPN задаёт сеть директивой 'server <сеть> <маска>', то есть маской,
 * а не префиксом — переводим честной битовой арифметикой.
 *
 * @return array{gw:string, network:string, prefix:int, cidr:string}
 */
function ovp_server_net(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    // Fallback — значение по умолчанию. Используется только если конфиг нечитаем.
    $fallback = [
        'gw'      => '10.6.0.1',
        'network' => '10.6.0.0',
        'prefix'  => 24,
        'cidr'    => '10.6.0.0/24',
    ];

    if (!is_readable(OVP_SRV_CONF)) return $cache = $fallback;
    $conf = @file_get_contents(OVP_SRV_CONF);
    if ($conf === false) return $cache = $fallback;

    if (!preg_match('/^\s*server\s+(\d{1,3}(?:\.\d{1,3}){3})\s+(\d{1,3}(?:\.\d{1,3}){3})/mi', $conf, $m)) {
        return $cache = $fallback;
    }

    $network = $m[1];
    $mask    = $m[2];

    if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return $cache = $fallback;
    }

    $prefix = ovp_mask_to_prefix($mask);
    if ($prefix < 8 || $prefix > 30) return $cache = $fallback;

    // Адрес сервера в туннеле — первый адрес подсети.
    $gw = long2ip((ip2long($network) + 1) & 0xFFFFFFFF);

    return $cache = [
        'gw'      => $gw,
        'network' => $network,
        'prefix'  => $prefix,
        'cidr'    => $network . '/' . $prefix,
    ];
}

/** 255.255.255.0 -> 24. Возвращает -1 для несплошной маски. */
function ovp_mask_to_prefix(string $mask): int {
    $long = ip2long($mask);
    if ($long === false) return -1;
    $long &= 0xFFFFFFFF;
    $bits = 0;
    for ($i = 31; $i >= 0; $i--) {
        if (($long >> $i) & 1) { $bits++; } else { break; }
    }
    // Проверяем, что после нулей не идут единицы (маска сплошная).
    $expected = $bits === 0 ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
    return $long === $expected ? $bits : -1;
}

/** Проверочный ли это адрес — такие нельзя класть в обход. */
function ovp_is_probe_host(string $ip): bool {
    return in_array($ip, explode(',', OVP_PROBE_HOSTS), true);
}

/**
 * WAN-интерфейс: из NIC.txt, с фолбэком на живое состояние ядра.
 * Возвращает '' если определить не удалось.
 */
function ovp_wan_iface(): string {
    $nic = @file_get_contents(__DIR__ . '/../NIC.txt');
    $nic = is_string($nic) ? trim($nic) : '';
    if ($nic !== '' && preg_match('/^[a-zA-Z0-9_:@.-]{1,20}$/', $nic) === 1) {
        return $nic;
    }
    // Исключаем tun/wg — иначе при поднятом туннеле примем VPN за WAN.
    $out = shell_exec("ip route show default 2>/dev/null | grep -v 'dev tun\|dev wg' | grep -oP 'dev \\K\\S+' | head -1");
    $out = is_string($out) ? trim($out) : '';
    return preg_match('/^[a-zA-Z0-9_:@.-]{1,20}$/', $out) === 1 ? $out : '';
}

// ══════════════════════════════════════════════════════════════════
// ИНТЕРФЕЙС tun1
// ══════════════════════════════════════════════════════════════════

/**
 * Выполняет привилегированную команду и возвращает признак успеха.
 *
 * ЗАЧЕМ ОТДЕЛЬНАЯ ОБЁРТКА: shell_exec молча выбрасывает код возврата.
 * Если правило sudoers не встало (ручное редактирование, отказ visudo),
 * панель рапортовала бы об успехе, а не делала ничего — и единственным
 * признаком было бы то, что туннель не поднимается «без причины».
 */
function ovp_sudo(string $cmd): bool {
    $out  = [];
    $code = 0;
    exec('sudo -n ' . $cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        ovp_log('WARN', 'sudo ' . $cmd . ' -> код ' . $code . ': ' . trim(implode(' ', $out)));
    }
    return $code === 0;
}

/** Наличие интерфейса по коду возврата (надёжнее парсинга вывода ifconfig). */
function ovp_iface_exists(string $iface = 'tun1'): bool {
    exec('ip link show ' . escapeshellarg($iface) . ' 2>/dev/null', $o, $rc);
    return $rc === 0;
}

/** Есть ли на интерфейсе IPv4-адрес. */
function ovp_iface_has_ip(string $iface = 'tun1'): bool {
    exec('ip -4 addr show ' . escapeshellarg($iface) . ' 2>/dev/null | grep -q "inet "', $o, $rc);
    return $rc === 0;
}

/**
 * Гарантированно опускает tun1.
 *
 * Сначала штатный systemctl stop. Если интерфейс всё равно висит — значит он
 * осиротел (служба убита по SIGKILL, хук route-pre-down не отработал) и сносим
 * напрямую через ip link delete. Без этого повторный запуск падает с
 * "RTNETLINK answers: File exists" и продолжает работать старый туннель.
 */
function ovp_bring_down(): bool {
    ovp_sudo('systemctl stop ' . OVP_UP_SVC);
    for ($i = 0; $i < 10; $i++) {
        if (!ovp_iface_exists()) return true;
        usleep(500000);
    }
    if (ovp_iface_exists()) {
        ovp_log('WARN', 'tun1 не опустился штатно — принудительное ip link delete');
        ovp_sudo('ip link delete dev tun1');
        for ($i = 0; $i < 6; $i++) {
            if (!ovp_iface_exists()) return true;
            usleep(500000);
        }
    }
    return !ovp_iface_exists();
}

/**
 * Ждёт поднятия tun1 с IP (polling вместо слепого sleep).
 *
 * Таймаут по умолчанию заметно больше, чем понадобился бы WireGuard:
 * OpenVPN устанавливает связь через полноценное TLS-рукопожатие,
 * и на медленном канале до второго VPN это занимает больше десяти секунд.
 */
function ovp_wait_up(int $timeoutSec = 20): bool {
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        if (ovp_iface_exists() && ovp_iface_has_ip()) return true;
        usleep(500000);
    }
    return false;
}

// ══════════════════════════════════════════════════════════════════
// STATE — мьютекс с healthcheck-демоном
// ══════════════════════════════════════════════════════════════════

/**
 * Выставляет состояние для демона.
 *   busy    — панель выполняет операцию, демон не вмешивается
 *   running — туннель должен работать, демон мониторит
 *   stopped — туннель намеренно выключен
 *
 * BUSY_SINCE нужен демону, чтобы снять зависший busy, если PHP упал
 * посреди операции. Запись атомарная: tmp -> rename.
 * chmod ПОСЛЕ rename обязателен — rename переносит владельца tmp-файла.
 */
function ovp_state_set(string $state): bool {
    $ts  = ($state === 'busy') ? time() : 0;
    $tmp = OVP_STATE_FILE . '.php.tmp';
    if (@file_put_contents($tmp, "STATE={$state}\nBUSY_SINCE={$ts}\n") === false) {
        // Молчаливый отказ здесь означал бы, что мьютекс не работает,
        // а никто об этом не знает.
        ovp_log('ERR', 'Не удалось записать ' . $tmp . ' — проверьте права на ' . OVP_DATA_DIR);
        return false;
    }
    if (!@rename($tmp, OVP_STATE_FILE)) {
        @unlink($tmp);
        ovp_log('ERR', 'Не удалось обновить ' . OVP_STATE_FILE);
        return false;
    }
    @chmod(OVP_STATE_FILE, 0664);
    return true;
}

// ══════════════════════════════════════════════════════════════════
// KILL SWITCH
// ══════════════════════════════════════════════════════════════════

/**
 * Включён ли Kill Switch — блокировка интернета при падении второго VPN.
 *
 * По умолчанию ВЫКЛЮЧЕН: если второй VPN ляжет, клиенты продолжат работать
 * через этот сервер. Остаться без интернета вообще хуже для большинства
 * сценариев, чем временно выйти с другого адреса.
 */
function ovp_killswitch_on(): bool {
    if (!is_readable(OVP_SETTINGS_FILE)) return false;
    $raw = (string) @file_get_contents(OVP_SETTINGS_FILE);
    return preg_match('/^killswitch=true$/m', $raw) === 1;
}

/** Атомарно сохраняет настройку. Демон подхватит её в течение 5 секунд. */
function ovp_killswitch_set(bool $on): bool {
    $tmp = OVP_SETTINGS_FILE . '.tmp';
    $val = $on ? 'true' : 'false';
    if (@file_put_contents($tmp, "killswitch={$val}\n") === false) {
        ovp_log('ERR', 'Не удалось записать настройки — проверьте права на ' . OVP_DATA_DIR);
        return false;
    }
    if (!@rename($tmp, OVP_SETTINGS_FILE)) {
        @unlink($tmp);
        ovp_log('ERR', 'Не удалось обновить ' . OVP_SETTINGS_FILE);
        return false;
    }
    @chmod(OVP_SETTINGS_FILE, 0664);
    return true;
}

// ══════════════════════════════════════════════════════════════════
// ПОДГОТОВКА КОНФИГА ВТОРОГО VPN
// ══════════════════════════════════════════════════════════════════

/**
 * Директивы, способные выполнить произвольный код или увести маршруты.
 *
 * ЭТО ГЛАВНОЕ ОТЛИЧИЕ ОТ WIREGUARD. У WireGuard опасны ровно четыре ключа
 * (PostUp/PostDown/PreUp/PreDown), и хватает одной регулярки. У OpenVPN
 * исполнение кода дают полтора десятка директив, а маршруты может увести
 * ещё столько же — поэтому здесь явный чёрный список.
 *
 * Конфиг присылает поставщик второго VPN, то есть посторонний человек,
 * а OpenVPN запускается от root. Без вырезания это прямая выдача root
 * тому, кто дал вам файл.
 */
function ovp_blocked_directives(): array {
    return [
        // Исполнение кода
        'up', 'down', 'up-restart', 'route-up', 'route-pre-down', 'down-pre',
        'ipchange', 'client-connect', 'client-disconnect', 'learn-address',
        'auth-user-pass-verify', 'tls-verify', 'tls-crypt-v2-verify',
        'plugin', 'script-security', 'setenv', 'setenv-safe', 'tls-export-cert',
        // Подмена окружения и путей
        'config', 'cd', 'chroot', 'daemon', 'log', 'log-append', 'status',
        'management', 'management-client-user', 'user', 'group',
        'askpass', 'writepid', 'iproute',
        // Маршрутизация: её задаём мы, в своей таблице
        'dev', 'dev-type', 'dev-node', 'route', 'route-gateway', 'route-metric',
        'redirect-gateway', 'redirect-private', 'pull-filter',
    ];
}

/**
 * Вырезает опасные директивы, не трогая содержимое inline-блоков.
 *
 * Возвращает очищенный текст; вырезанные строки превращаются в комментарии,
 * чтобы администратор видел в файле, что именно было удалено.
 *
 * ТОНКОСТИ, о которые легко споткнуться:
 *
 *  1. CRLF. Конфиги провайдеров почти всегда с \r\n. Невидимый \r прилипает
 *     к имени директивы, и 'daemon\r' не совпадает с 'daemon' — строка
 *     проходит фильтр насквозь. Поэтому переводы строк нормализуются первыми.
 *
 *  2. Inline-блоки. Внутри <ca>/<cert>/<key> лежит base64, который нельзя
 *     трогать. Но пропускать без фильтрации можно ТОЛЬКО блоки с ключами:
 *     <connection> — это обычные директивы, и они обязаны пройти проверку.
 *
 *  3. Незакрытый блок. Если файл содержит <ca> без </ca>, весь остаток
 *     попал бы в проходной режим — поэтому счётчик глубины не используется,
 *     а закрывающий тег обязан совпасть с открывающим.
 */
function ovp_sanitize_config(string $raw): string {
    $blocked = array_flip(ovp_blocked_directives());

    $raw   = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = explode("\n", $raw);

    $passthru = ['ca', 'cert', 'key', 'dh', 'secret', 'tls-auth', 'tls-crypt',
                 'tls-crypt-v2', 'extra-certs', 'pkcs12', 'crl-verify',
                 'http-proxy-user-pass'];

    $out    = [];
    $inTag  = '';

    foreach ($lines as $line) {
        $t = ltrim($line);

        if ($inTag !== '') {
            $out[] = $line;
            if (preg_match('#^</([A-Za-z0-9_-]+)>#', $t, $m) && strtolower($m[1]) === $inTag) {
                $inTag = '';
            }
            continue;
        }

        if (preg_match('#^<([A-Za-z0-9_-]+)>#', $t, $m)) {
            $tag = strtolower($m[1]);
            if (in_array($tag, $passthru, true)) $inTag = $tag;
            $out[] = $line;
            continue;
        }

        if ($t === '' || $t[0] === '#' || $t[0] === ';') {
            $out[] = $line;
            continue;
        }

        // Кавычки снимаем перед сравнением: OpenVPN принимает "up" /tmp/x
        // наравне с up /tmp/x, а без этого ключ '"up' не совпал бы с чёрным
        // списком и директива прошла бы насквозь.
        $key = strtolower(preg_split('/\s+/', $t)[0]);
        $key = trim($key, "-\"'");

        if (isset($blocked[$key])) {
            $out[] = '# [ovpnplus] удалено: ' . $line;
            continue;
        }
        $out[] = $line;
    }

    // Незакрытый inline-блок — это не «почти корректный» файл, а дыра
    // в фильтре: всё, что идёт после открывающего тега, попало в вывод
    // без проверки чёрного списка. Возвращаем признак, чтобы вызывающий
    // отклонил конфиг целиком.
    if ($inTag !== '') {
        throw new RuntimeException('Не закрыт блок <' . $inTag . '>');
    }

    return implode("\n", $out);
}

/**
 * Дописывает то, что нужно нам, поверх очищенного конфига.
 *
 * Маршрутизацию НЕ прописываем в самом файле: за неё отвечают ключи
 * командной строки в systemd-юните (--route-nopull и хуки route-up).
 * Юнит принадлежит root и не может быть изменён панелью — значит
 * маршрутизацию нельзя подменить содержимым загруженного файла.
 */
function ovp_prepare_config(string $raw, bool $withAuth): string {
    $out = rtrim(ovp_sanitize_config($raw));

    if ($withAuth) {
        // Приводим все варианты auth-user-pass к нашему файлу.
        $out = preg_replace('/^[ \t]*auth-user-pass.*$/mi', '', $out);
        $out = rtrim($out) . "\nauth-user-pass " . OVP_UP_AUTH;
    }

    $extra = [];

    /*
     * data-ciphers появился только в OpenVPN 2.5 — на 2.4 эта директива
     * не даёт туннелю подняться вовсе («Unrecognized option»). И дописывать
     * её поверх собственного списка провайдера тоже нельзя: наша строка идёт
     * последней и перекрыла бы его выбор, а провайдер мог согласовать,
     * например, только CHACHA20-POLY1305.
     *
     * Поэтому: добавляем лишь тогда, когда версия поддерживает И в конфиге
     * ничего своего не задано. Это нужно для обратного случая — OpenVPN 2.6
     * выкинул AES-256-CBC из списка по умолчанию, и старый конфиг
     * с одной директивой cipher без fallback перестал бы работать.
     */
    if (ovp_supports_data_ciphers()
        && preg_match('/^[ \t]*data-ciphers\b/mi', $out) !== 1) {

        $extra[] = 'data-ciphers AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305:AES-256-CBC:AES-128-CBC';

        // Запасной шифр берём из самого конфига, если он там указан:
        // так мы не навязываем свой выбор, а лишь страхуем от удаления
        // старых шифров из списка по умолчанию.
        if (preg_match('/^[ \t]*cipher[ \t]+([A-Za-z0-9-]+)/mi', $out, $m)) {
            $extra[] = 'data-ciphers-fallback ' . $m[1];
        } else {
            $extra[] = 'data-ciphers-fallback AES-128-CBC';
        }
    }

    if ($extra) {
        $out .= "\n\n# --- добавлено OVPNPlus ---\n" . implode("\n", $extra) . "\n";
    }

    return $out . "\n";
}

/**
 * Поддерживает ли установленный OpenVPN директиву data-ciphers (версия 2.5+).
 * Результат кешируется: вызов openvpn --version стоит недёшево.
 */
function ovp_supports_data_ciphers(): bool {
    static $cache = null;
    if ($cache !== null) return $cache;

    $out = @shell_exec('openvpn --version 2>/dev/null');
    if (!is_string($out) || !preg_match('/OpenVPN\s+(\d+)\.(\d+)/i', $out, $m)) {
        // Версию определить не удалось — не добавляем ничего.
        // Лишняя директива ломает туннель, её отсутствие лишь оставляет
        // настройки провайдера как есть.
        return $cache = false;
    }
    return $cache = ((int) $m[1] > 2 || ((int) $m[1] === 2 && (int) $m[2] >= 5));
}

/**
 * Требует ли конфиг логин и пароль.
 *
 * Совпадает с ЛЮБОЙ формой директивы, а не только с голой. Вариант
 * 'auth-user-pass creds.txt' ссылается на файл с машины поставщика,
 * которого у нас нет: OpenVPN не найдёт его и всё равно потребует
 * ввод с консоли — служба зависнет в запуске вместо понятной ошибки.
 */
function ovp_needs_credentials(string $raw): bool {
    return preg_match('/^\s*auth-user-pass\b/mi', $raw) === 1;
}

/**
 * Проверяет, не пересекается ли адресация второго VPN с подсетью клиентов.
 *
 * ЗАЧЕМ: любая адресация второго VPN работает — кроме случая, когда она
 * перекрывается с подсетью наших клиентов. Ядро создаёт connected route
 * на адрес интерфейса, и в main-таблице появляются ДВА одинаковых маршрута:
 *
 *   10.6.0.0/24 dev tun0   ← наши клиенты
 *   10.6.0.0/24 dev tun1   ← адрес от второго VPN
 *
 * Какой выберет ядро — вопрос порядка добавления. Сразу после загрузки
 * обычно выигрывает tun0 и всё работает, но после перезагрузки порядок
 * может смениться, и клиенты станут недоступны без понятной причины.
 *
 * У OpenVPN адрес не написан в клиентском конфиге (его выдаёт сервер),
 * поэтому проверяем то, что доступно до подключения: директиву ifconfig,
 * если она есть, и подсети из локальных route-директив.
 *
 * @return string '' если конфликта нет, иначе проблемный адрес
 */
function ovp_addr_conflict(string $raw, array $net): string {
    // Смотрим ТОЛЬКО статическую директиву ifconfig.
    //
    // Директивы route сюда включать нельзя по двум причинам. Во-первых,
    // они и так вырезаются санитайзером и не доходят до OpenVPN, так что
    // конфликта из них возникнуть не может. Во-вторых, маска 0.0.0.0
    // (строка 'route 0.0.0.0 0.0.0.0' встречается сплошь и рядом) даёт
    // общий префикс 0, при котором любые две сети «совпадают» — и панель
    // отклоняла бы совершенно рабочие конфиги с советом, который не поможет.
    if (!preg_match_all('/^[ \t]*ifconfig[ \t]+(\d{1,3}(?:\.\d{1,3}){3})/mi', $raw, $m)) {
        return '';
    }

    $ourNet = ip2long($net['network']);
    $ourPfx = (int) $net['prefix'];

    foreach ($m[1] as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) continue;
        // Адрес из ifconfig — это один хост, поэтому сравниваем его
        // с нашей подсетью по НАШЕЙ маске.
        $mask = (0xFFFFFFFF << (32 - $ourPfx)) & 0xFFFFFFFF;
        if (((ip2long($ip) & $mask) & 0xFFFFFFFF) === (($ourNet & $mask) & 0xFFFFFFFF)) {
            return $ip;
        }
    }
    return '';
}

/**
 * Проверка адреса ПОСЛЕ подъёма туннеля.
 *
 * Для OpenVPN это основная проверка, а ovp_addr_conflict — лишь ранний
 * отсев. Причина в различии протоколов: у WireGuard адрес интерфейса
 * записан прямо в клиентском конфиге, а OpenVPN получает его от сервера
 * через push уже после подключения. То есть до первого успешного
 * соединения узнать адрес нельзя в принципе.
 *
 * Если адрес tun1 попал в подсеть клиентов, в основной таблице появляются
 * два одинаковых connected-маршрута (через tun0 и через tun1). Какой
 * выберет ядро — вопрос порядка добавления: сразу после загрузки обычно
 * работает, а после перезагрузки клиенты становятся недоступны без
 * видимой причины.
 *
 * @return string '' если конфликта нет, иначе фактический адрес tun1
 */
function ovp_running_addr_conflict(array $net): string {
    $out = shell_exec("ip -4 -o addr show tun1 2>/dev/null | awk '{print $4}'");
    if (!is_string($out)) return '';

    $ourNet = ip2long($net['network']);
    $ourPfx = (int) $net['prefix'];
    $mask   = (0xFFFFFFFF << (32 - $ourPfx)) & 0xFFFFFFFF;

    foreach (preg_split('/\s+/', trim($out)) as $cidr) {
        if ($cidr === '') continue;
        $ip = explode('/', $cidr)[0];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) continue;
        if (((ip2long($ip) & $mask) & 0xFFFFFFFF) === (($ourNet & $mask) & 0xFFFFFFFF)) {
            return $ip;
        }
    }
    return '';
}

/** Адрес сервера второго VPN из директивы remote. */
function ovp_upstream_remote(): array {
    if (!is_readable(OVP_UP_CONF)) return ['host' => '', 'port' => ''];
    $conf = @file_get_contents(OVP_UP_CONF);
    if ($conf === false) return ['host' => '', 'port' => ''];
    if (preg_match('/^\s*remote\s+([\w\.\-]+)(?:\s+(\d+))?/mi', $conf, $m)) {
        return ['host' => $m[1], 'port' => $m[2] ?? ''];
    }
    return ['host' => '', 'port' => ''];
}

// ══════════════════════════════════════════════════════════════════
// ЛОГИРОВАНИЕ
// ══════════════════════════════════════════════════════════════════

/**
 * Реальное состояние туннеля по данным демона.
 *
 * @return array{known:bool, alive:bool, bypass:bool, age:int}
 *   known  — демон писал файл и данные свежие
 *   alive  — последняя проверка связи прошла
 *   bypass — трафик клиентов сейчас идёт мимо туннеля
 *
 * Если файла нет или он старше двух минут, считаем сведения неизвестными:
 * лучше показать «состояние неизвестно», чем уверенно соврать по данным,
 * оставшимся от остановленного демона.
 */
function ovp_health(): array {
    $unknown = ['known' => false, 'alive' => false, 'bypass' => false, 'age' => -1];
    if (!is_readable(OVP_HEALTH_FILE)) return $unknown;

    $raw = (string) @file_get_contents(OVP_HEALTH_FILE);
    if ($raw === '') return $unknown;

    $kv = [];
    foreach (explode("\n", $raw) as $line) {
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $kv[trim($k)] = trim($v);
    }

    $at  = isset($kv['AT']) ? (int) $kv['AT'] : 0;
    $age = $at > 0 ? (time() - $at) : -1;
    if ($age < 0 || $age > 120) return $unknown;

    return [
        'known'  => true,
        'alive'  => ($kv['TUNNEL'] ?? '0') === '1',
        'bypass' => ($kv['BYPASS'] ?? '0') === '1',
        'age'    => $age,
    ];
}

/** Ротация: при превышении лимита оставляем последние OVP_LOG_KEEP строк. */
function ovp_rotate(string $file): void {
    $size = @filesize($file);
    if ($size === false || $size <= OVP_LOG_MAX) return;
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || count($lines) <= OVP_LOG_KEEP) return;
    $keep = array_slice($lines, -OVP_LOG_KEEP);
    @file_put_contents($file, implode("\n", $keep) . "\n", LOCK_EX);
}

/**
 * Запись в общий журнал.
 *
 * Формат тот же, что у healthcheck-демона — оба пишут в один файл,
 * различаясь только меткой источника. Поэтому в интерфейсе один
 * хронологический список без вкладок.
 *
 * @param string $level OK | INFO | WARN | ERR
 * @param string $message текст по-русски, понятный без знания кода
 */
function ovp_log(string $level, string $message): void {
    if (!is_dir(OVP_LOG_DIR)) @mkdir(OVP_LOG_DIR, 0775, true);
    $line = sprintf(
        "[%s] [%s] [%s] %s\n",
        date('Y-m-d H:i:s'), $level, 'панель',
        trim(str_replace(["\n", "\r"], ' ', $message))
    );
    @file_put_contents(OVP_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    ovp_rotate(OVP_LOG_FILE);
}

/**
 * Последние N строк файла без загрузки его целиком.
 * Читаем с конца блоками — на журнале в 2 МБ это заметно дешевле file().
 *
 * @return string[] строки в исходном порядке (старые сверху)
 */
function ovp_tail(string $file, int $lines = 200): array {
    if (!is_readable($file)) return [];
    $fp = @fopen($file, 'rb');
    if (!$fp) return [];

    fseek($fp, 0, SEEK_END);
    $size   = ftell($fp);
    $buffer = '';
    $pos    = 0;
    $chunk  = 8192;

    while ($size + $pos > 0 && substr_count($buffer, "\n") <= $lines) {
        $read = (int) min($chunk, $size + $pos);
        $pos -= $read;
        fseek($fp, $pos, SEEK_END);
        $buffer = fread($fp, $read) . $buffer;
    }
    fclose($fp);

    $all = explode("\n", $buffer);
    $all = array_values(array_filter($all, fn($l) => trim($l) !== ''));
    return array_slice($all, -$lines);
}

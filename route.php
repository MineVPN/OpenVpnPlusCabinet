<?php
// ======================================================================
// ВЕСЬ ТВОЙ PHP-КОД ОСТАЕТСЯ ЗДЕСЬ БЕЗ КАКИХ-ЛИБО ИЗМЕНЕНИЙ
// ======================================================================
// session_start() здесь не нужен, так как он уже есть в cabinet.php

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

function safeReadFile($filename) {
    return file_exists($filename) ? trim(file_get_contents($filename)) : '';
}
function cleanUpstreamRouteFile($file) {
    if (!file_exists($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_filter($lines, 'trim');
    file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
}
function updateUpstreamRouteFile($file, $route, $rule) {
    if (!file_exists($file)) {
        file_put_contents($file, "#!/bin/bash\n\nexit 0\n");
        chmod($file, 0755);
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!in_array($route, $lines) && !in_array($rule, $lines)) {
        $newLines = [];
        foreach ($lines as $line) {
            if (trim($line) === "exit 0") {
                $newLines[] = $route;
                $newLines[] = $rule;
            }
            $newLines[] = $line;
        }
        file_put_contents($file, implode(PHP_EOL, $newLines) . PHP_EOL);
    }
}
function removeUpstreamRoute($file, $route, $rule) {
    if (!file_exists($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $newLines = [];
    foreach ($lines as $line) {
        if (trim($line) !== trim($route) && trim($line) !== trim($rule)) {
            $newLines[] = $line;
        }
    }
    file_put_contents($file, implode(PHP_EOL, $newLines) . PHP_EOL);
}

$nic = safeReadFile('NIC.txt');
$gateway = safeReadFile('gateway.txt');
$routesFile = 'routes.txt';
$routes = file_exists($routesFile) ? file($routesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$upstreamRouteFile = '/etc/openvpn/upstream-route.sh';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_ip'])) {
    $new_ip = trim($_POST['new_ip']);
    if (filter_var($new_ip, FILTER_VALIDATE_IP) && !in_array($new_ip, $routes)) {
        $route = "ip route add $new_ip via $gateway dev $nic table $nic";
        $rule = "ip rule add to $new_ip table $nic";
        updateUpstreamRouteFile($upstreamRouteFile, $route, $rule);
        exec("sudo $route");
        exec("sudo $rule");
        $routes[] = $new_ip;
        file_put_contents($routesFile, implode(PHP_EOL, $routes) . PHP_EOL, LOCK_EX);
        echo "<script>Notice('Маршрут для $new_ip успешно добавлен!', 'success');</script>";
        echo "<script>window.location = 'cabinet.php?menu=route';</script>";
        exit();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ip'])) {
    $delete_ip = trim($_POST['delete_ip']);
    if (in_array($delete_ip, $routes)) {
        $route = "ip route add $delete_ip via $gateway dev $nic table $nic";
        $rule = "ip rule add to $delete_ip table $nic";
        $routedel = "ip route del $delete_ip via $gateway dev $nic table $nic";
        $ruledel = "ip rule del to $delete_ip table $nic";
        removeUpstreamRoute($upstreamRouteFile, $route, $rule);
        exec("sudo $routedel");
        exec("sudo $ruledel");
        $routes = array_filter($routes, fn($ip) => $ip !== $delete_ip);
        file_put_contents($routesFile, implode(PHP_EOL, $routes) . PHP_EOL, LOCK_EX);
        echo "<script>Notice('Маршрут для $delete_ip удален.', 'success');</script>";
        echo "<script>window.location = 'cabinet.php?menu=route';</script>";
        exit();
    }
}
cleanUpstreamRouteFile($upstreamRouteFile);
?>

<div class="space-y-8">

    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-2xl font-bold text-orange-500 mb-2">Обход VPN</h2>
        <p class="text-slate-400 mb-6">Трафик на эти IP-адреса будет идти напрямую, игнорируя туннель.</p>
        
        <div class="space-y-3">
            <?php if (!empty($routes)): ?>
                <?php foreach ($routes as $route): ?>
                    <div class="flex items-center justify-between bg-slate-800/50 p-3 rounded-lg hover:bg-slate-800 transition-colors">
                        <code class="text-lg text-sky-300 font-mono"><?= htmlspecialchars($route) ?></code>
                        <form method="POST" class="m-0">
                            <input type="hidden" name="delete_ip" value="<?= htmlspecialchars($route) ?>">
                            <button type="submit" class="bg-red-500/20 text-red-400 hover:bg-red-500/40 hover:text-white rounded-md px-3 py-1 text-sm font-medium transition-colors">
                                Удалить
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center text-slate-500 py-8">
                    Список IP-адресов для обхода пуст.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-2xl font-bold text-white mb-6">Добавить IP для обхода</h2>
        <form method="POST" class="flex flex-col sm:flex-row items-center gap-4">
            <input type="text" name="new_ip" placeholder="Введите IP-адрес" required
                   pattern="^(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])$"
                   title="Введите корректный IP-адрес" 
                   class="flex-grow w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-orange-500 focus:outline-none transition">
            <button type="submit" class="w-full sm:w-auto bg-orange-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-orange-700 transition-all neon-button-orange">
                Добавить
            </button>
        </form>
    </div>

</div>
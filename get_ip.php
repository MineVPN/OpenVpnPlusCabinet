<?php
// Твоя PHP-логика остается здесь без изменений
session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}
?>

<div class="glassmorphism rounded-2xl p-6 flex flex-col">
    <?php
    // ----- НАЧАЛО ТВОЕЙ ЛОГИКИ (БЕЗ ИЗМЕНЕНИЙ) -----
    $openvpn_config_path = '/etc/openvpn/tun1.conf';
    $wireguard_config_path = '/etc/wireguard/tun1.conf';

    $type = null;
    $tun = null;

    // Вместо простого H2, делаем стилизованный заголовок
    echo '<h2 class="text-2xl font-bold text-orange-500 mb-6">Статус VPN</h2>';
    echo '<div class="space-y-4 text-slate-300 flex-grow">';

    $ip_to_display = 'Не определен';
    $config_to_display = 'Нет';

    if (file_exists($openvpn_config_path)) {
        $openvpn_config_content = file_get_contents($openvpn_config_path);
        if (preg_match('/^\s*remote\s+([^\s]+)/m', $openvpn_config_content, $matcheso)) {
            $ip_to_display = $matcheso[1];
            $config_to_display = "OpenVPN";
            $type = "openvpn";
        }
    }


    // Выводим информацию в новом формате
    echo '<div class="flex justify-between"><span class="font-medium">Конфигурация:</span><span class="text-white font-semibold">' . htmlspecialchars($config_to_display) . '</span></div>';
    echo '<div class="flex justify-between"><span class="font-medium">IP-адрес:</span><span class="text-white font-semibold font-mono">' . htmlspecialchars($ip_to_display) . '</span></div>';

    $status = shell_exec("ifconfig tun1 2>&1");
    
    echo '<div class="flex justify-between items-center"><span class="font-medium">Соединение:</span>';
    if (strpos($status, 'Device not found') !== false) {
        echo '<span class="bg-red-500/20 text-red-300 px-3 py-1 rounded-full text-sm font-semibold">Разорвано</span>';
        $tun = "yes";
    } else {
        echo '<span class="bg-green-500/20 text-green-300 px-3 py-1 rounded-full text-sm font-semibold">Установлено</span>';
        $tun = "no";
    }
    echo '</div></div>'; // Закрываем .space-y-4

    if(isset($_POST['openvpn_start']) && $type == "openvpn") {
        shell_exec("sudo systemctl start openvpn@tun1");
        sleep(5);
        echo "<script>window.location = 'cabinet.php?menu=openvpn';</script>";
        exit();
    }
    if(isset($_POST['openvpn_stop']) && $type == "openvpn") {
        shell_exec("sudo systemctl stop openvpn@tun1");
        sleep(3);
        echo "<script>window.location = 'cabinet.php?menu=openvpn';</script>";
        exit();
    }
    ?>

    <form method="post" class="mt-8">
        <?php if ($type == "openvpn" && $tun == "yes"): ?>
            <button type="submit" name="openvpn_start" class="w-full bg-green-600 text-white font-bold py-3 rounded-lg hover:bg-green-700 transition-all">Запустить OpenVPN</button>
        <?php elseif ($type == "openvpn" && $tun == "no"): ?>
            <button type="submit" name="openvpn_stop" class="w-full bg-red-600 text-white font-bold py-3 rounded-lg hover:bg-red-700 transition-all">Остановить OpenVPN</button>
        <?php endif; ?>
        
    </form>
</div>
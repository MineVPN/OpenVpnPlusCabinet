<?php
session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["config_file"])) {
    $allowed_extensions = array('ovpn');
    $file_extension = strtolower(pathinfo($_FILES["config_file"]["name"], PATHINFO_EXTENSION));

    if (!in_array($file_extension, $allowed_extensions)) {
        echo "Разрешены только файлы с расширением .ovpn";
        exit();
    }

    shell_exec('sudo systemctl stop openvpn@tun1');
    shell_exec('rm /etc/openvpn/tun1.conf');
    //shell_exec('rm /etc/wireguard/*.conf');

    $upload_dir = '/etc/openvpn/';
    $config_file_ovpn = $upload_dir . "tun1.conf";

    if (move_uploaded_file($_FILES["config_file"]["tmp_name"], $config_file_ovpn)) {
        $file_content = file_get_contents($config_file_ovpn);

        // Замена "dev tun" на "dev tun1"
        $file_content = preg_replace('/\bdev tun\b/', 'dev tun1', $file_content);

        // Вставка строк перед <ca>
        $insert_text = "pull-filter ignore \"redirect-gateway\"\n" .
                       "script-security 2\n" .
                       "up /etc/openvpn/upstream-route.sh";

        $file_content = preg_replace('/(<ca>)/', $insert_text . "\n$1", $file_content, 1);

        file_put_contents($config_file_ovpn, $file_content);

        shell_exec('sudo systemctl start openvpn@tun1');

        sleep(4);
        echo "<script>Notice('OpenVPN конфигурация успешно установлена и готова к работе!');</script>";
    } else {
        echo "Ошибка при загрузке файла.";
    }
}
include_once 'get_ip.php';
?>

<div class="container">
    <h2>Установка и запуск OpenVPN</h2>
    <form method="post" enctype="multipart/form-data" class="container-form">
        <label for="config_file">Выберите файл конфигурации (только *.ovpn):</label><br>
        <input type="file" id="config_file" name="config_file" accept=".ovpn">
        <input type="hidden" name="menu" value="openvpn">
        <input type="submit" class="green-button" value="Установить и запустить">
    </form>
</div>

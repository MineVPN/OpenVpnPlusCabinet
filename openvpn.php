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
        // Используем нашу новую функцию уведомлений из cabinet.php
        //echo "<script>Notice('OpenVPN конфигурация успешно установлена!', 'success');</script>";
    } else {
        //echo "<script>Notice('Ошибка при загрузке файла.', 'error');</script>";
    }
}
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

    <div class="glassmorphism rounded-2xl p-6">
        <?php
        // Твой include остается здесь, чтобы показывать статус
        include_once 'get_ip.php';
        ?>
    </div>

    <div class="glassmorphism rounded-2xl p-6 flex flex-col">
        <h2 class="text-2xl font-bold text-orange-500 mb-6">Установка второго OpenVPN</h2>
        <form id="upload-form" method="post" enctype="multipart/form-data" class="flex flex-col flex-grow">
            <div class="flex-grow">
                <label id="drop-zone" for="config_file" class="flex flex-col items-center justify-center w-full h-full border-2 border-dashed border-slate-600 rounded-xl cursor-pointer hover:border-orange-500 transition-colors">
                    <div class="flex flex-col items-center justify-center pt-5 pb-6">
                        <p id="drop-zone-text" class="mb-2 text-sm text-slate-400"><span class="font-semibold">Кликните для выбора</span> или перетащите файл</p>
                        <p class="text-xs text-slate-500">только *.ovpn</p>
                    </div>
                    <input type="file" id="config_file" name="config_file" accept=".ovpn" class="hidden">
                </label>
            </div>
            <input type="hidden" name="menu" value="openvpn">
            <button type="submit" class="w-full bg-orange-600 text-white font-bold py-3 mt-8 rounded-lg hover:bg-orange-700 transition-all neon-button-orange">
                Установить и запустить
            </button>
        </form>
    </div>
</div>

<script>
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('config_file');
    const dropZoneText = document.getElementById('drop-zone-text');

    if(dropZone) {
        dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('border-orange-500'); dropZone.classList.remove('border-slate-600'); });
        dropZone.addEventListener('dragleave', (e) => { e.preventDefault(); dropZone.classList.remove('border-orange-500'); dropZone.classList.add('border-slate-600'); });
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('border-orange-500');
            dropZone.classList.add('border-slate-600');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                dropZoneText.innerHTML = `<span class="font-semibold text-green-400">Файл выбран:</span> ${files[0].name}`;
            }
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                dropZoneText.innerHTML = `<span class="font-semibold text-green-400">Файл выбран:</span> ${fileInput.files[0].name}`;
            }
        });
    }
</script>
<?php
session_start(); // Начало сессии

// Проверяем, установлена ли сессия
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    // Сессия не установлена или пользователь не аутентифицирован, перенаправляем на страницу входа
    header("Location: login.php");
    exit(); // Важно вызвать exit() после перенаправления, чтобы предотвратить дальнейшее выполнение кода
}

if(isset($_POST['menu'])){
    $_GET['menu'] = $_POST['menu'];
}

// Проверяем, был ли передан параметр меню
$menu_item = isset($_GET['menu']) ? $_GET['menu'] : 'openvpn'; // По умолчанию открывается страница OpenVPN



// Пути к страницам меню
$menu_pages = [
    'openvpn' => 'openvpn.php',
    'ping' => 'pinger.php',
    'route' => 'route.php'
];

// Проверяем, существует ли запрошенная страница в меню
if (!array_key_exists($menu_item, $menu_pages)) {
    // Если страница не найдена, перенаправляем на страницу OpenVPN
    $menu_item = 'openvpn';
}

// Весь ваш код для страницы кабинета может быть добавлен здесь
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>OpenVPN+</title>
    <link rel="stylesheet" href="styles.css">
    <script>
    function Notice(text) {
        // Находим все элементы с классом 'block'
        var elements = document.querySelectorAll('.notice');

        // Перебираем найденные элементы
        elements.forEach(function(element) {
            // Изменяем текстовое содержимое элемента
            element.textContent = text;

            // Удаляем класс 'hidden', чтобы элемент стал видимым
            if (element.classList.contains('hidden')) {
                element.classList.remove('hidden');
            }
        });
    }
</script>
</head>
<body>
    <!-- Боковое меню -->
    <div class="sidebar">
        <img src="logo.png" class="logo">
        <a class="menu-item" href="cabinet.php?menu=openvpn">OpenVPN</a>
        <a class="menu-item" href="cabinet.php?menu=route">Route</a>
        <a class="menu-item" href="cabinet.php?menu=ping">Ping</a>
        <a class="menu-item" href="logout.php">Выход</a>
    </div>

    <!-- Основной контент -->
    <div class="notice hidden"></div>
    <div class="page">

        <?php
        // Подключаем выбранную страницу из меню
        
        
        echo "<br>";
        include_once $menu_pages[$menu_item];

        ?>
        
        
    </div>
    
</body>
</html>

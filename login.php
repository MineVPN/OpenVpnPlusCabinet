<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SERVER Login</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class='login-page'>
        <div class="login-container">
            <h2>Вход</h2>
            <form class="login-form" action="login.php" method="POST">
                <label for="username">Пользователь:</label>
                <input type="text" id="username" name="username" required value='root'>
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" required>
                <input type="submit" class="green-button" value="Войти">
            </form>
            <?php
        // Проверка, была ли отправлена форма
            if ($_SERVER["REQUEST_METHOD"] == "POST") {
            // Получение пароля из формы
                $password = $_POST["password"];

            // Команда для проверки аутентификации пользователя root
                $command = "echo '$password' | su -c 'id'";

            // Выполнение команды и получение результата
                exec($command, $output, $return_var);

            // Проверка успешности выполнения команды
                if ($return_var == 0) {
                // Стартуем сессию
                    session_start();
                // Устанавливаем флаг авторизации
                    $_SESSION["authenticated"] = true;
                // Перенаправляем на защищенную страницу
                    header("Location: index.php");
                    exit();
                } else {
                // Авторизация неуспешна, показываем сообщение об ошибке
                    echo "<p class='error-message'>Неверный пароль.</p>";
                }
            }
            ?>
        </div>
    </div>
</body>
</html>

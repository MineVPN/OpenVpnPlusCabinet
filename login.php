<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>OpenVPN+ Login</title>
    
    <script src="tailwindcss.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #0F172A; }
        /* ИЗМЕНЕНИЕ: Добавляем оранжевую окантовку */
        .glassmorphism { 
            background: rgba(30, 41, 59, 0.6); 
            backdrop-filter: blur(16px); 
            border: 1px solid rgba(249, 115, 22, 0.2); /* Полупрозрачный оранжевый */
        }
        .neon-button-orange:hover { box-shadow: 0 0 8px #f97316, 0 0 16px #f97316; }
    </style>
</head>
<body class="text-slate-300">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-sm glassmorphism rounded-2xl p-8">
            <div class="flex justify-center mb-6">
                <img src="logo.png" alt="OpenVPN+ Logo" class="w-48 h-48">
            </div>
            <h2 class="text-3xl font-bold text-orange-500 mb-6 text-center">Вход</h2>
            
            <form class="space-y-6" action="login.php" method="POST">
                <div>
                    <label for="password" class="block mb-2 text-sm font-medium text-slate-400">Пароль:</label>
                    <input type="password" id="password" name="password" required class="w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-orange-500 focus:outline-none transition">
                </div>
                <button type="submit" class="w-full bg-orange-600 text-white font-bold py-3 rounded-lg hover:bg-orange-700 transition-all duration-300 neon-button-orange">
                    Войти
                </button>
            </form>
            
            <?php
            // PHP логика без изменений
            if ($_SERVER["REQUEST_METHOD"] == "POST") {
                $password = $_POST["password"];
                $truepassword = 'HZsNkyoc0W359kT4';
                if ($password == $truepassword) {
                    session_start();
                    $_SESSION["authenticated"] = true;
                    header("Location: index.php");
                    exit();
                } else {
                    echo "<p class='text-red-400 text-center mt-4'>Неверный пароль.</p>";
                }
            }
            ?>
        </div>
    </div>
</body>
</html>
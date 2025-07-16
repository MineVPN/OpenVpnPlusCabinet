<?php
// PHP логика без изменений
session_start();
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) { header("Location: login.php"); exit(); }
if(isset($_POST['menu'])){ $_GET['menu'] = $_POST['menu']; }
$menu_item = isset($_GET['menu']) ? $_GET['menu'] : 'openvpn';
$menu_pages = [
    'openvpn' => 'openvpn.php',
    'ping' => 'pinger.php',
    'route' => 'route.php'
];
if (!array_key_exists($menu_item, $menu_pages)) { $menu_item = 'openvpn'; }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>OpenVPN+</title>
    <script src="tailwindcss.js"></script>
    <style>
         body { font-family: 'Inter', sans-serif; background-color: #0F172A; }
        .glassmorphism { background: rgba(30, 41, 59, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.1); }
        /* Стилизация скроллбара */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #1e293b; }
        ::-webkit-scrollbar-thumb { background-color: #475569; border-radius: 10px; border: 2px solid #1e293b; }
        ::-webkit-scrollbar-thumb:hover { background-color: #64748b; }
        * { scrollbar-width: thin; scrollbar-color: #475569 #1e293b; }
        }
    </style>
    </head>
<body class="text-slate-300">
    <div class="flex min-h-screen">
        <aside class="w-64 flex-shrink-0 bg-slate-900 p-4 flex flex-col border-r border-slate-700">
            <a href="cabinet.php" class="p-4 mb-6 text-center block">
                <img src="logo.png" alt="Logo" class="w-48 h-48 mx-auto rounded-full shadow-lg ring-2 ring-slate-700/50 hover:ring-orange-500 transition-all">
            </a>
            <nav class="flex flex-col gap-2">
                <a href="cabinet.php?menu=openvpn" class="flex items-center gap-4 px-4 py-3 rounded-lg transition-colors <?php echo ($menu_item == 'openvpn') ? 'bg-orange-500/10 text-orange-400 shadow-inner' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'; ?>">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    <span class="font-medium">OpenVPN</span>
                </a>
                <a href="cabinet.php?menu=route" class="flex items-center gap-4 px-4 py-3 rounded-lg transition-colors <?php echo ($menu_item == 'route') ? 'bg-orange-500/10 text-orange-400 shadow-inner' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'; ?>">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path></svg>
                    <span class="font-medium">Route</span>
                </a>
                <a href="cabinet.php?menu=ping" class="flex items-center gap-4 px-4 py-3 rounded-lg transition-colors <?php echo ($menu_item == 'ping') ? 'bg-orange-500/10 text-orange-400 shadow-inner' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'; ?>">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    <span class="font-medium">Ping</span>
                </a>
                <a href="logout.php" class="flex items-center gap-4 px-4 py-3 rounded-lg text-slate-400 hover:bg-red-500/20 hover:text-red-400 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    <span class="font-medium">Выход</span>
                </a>
            </nav>
        </aside>

        <main class="flex-grow p-4 sm:p-8 w-full">
            <div class="glassmorphism rounded-2xl p-6 sm:p-8 h-full">
                <?php include_once $menu_pages[$menu_item]; ?>
            </div>
        </main>
    </div>
</body>
</html>
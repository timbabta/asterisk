<?php
/**
 * Asterisk Auto-Dialer & IVR Smart Console
 * Полное решение: Веб-панель + API реального времени + Бэкенд импорта + Поддержка Excel/CSV + Мониторинг для Оператора
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Конфигурация подключения к MySQL/MariaDB
$db_host = 'localhost';
$db_user = 'root';        // Укажи здесь пользователя БД
$db_pass = '';            // Укажи здесь пароль БД (например, StrongPass13!)
$db_name = 'asterisk_dialer';

// Попытка подключения к MySQL
$db = @new mysqli($db_host, $db_user, $db_pass);
if ($db->connect_error) {
    $db_connected = false;
    $db_error = "Ошибка подключения к MySQL: " . $db->connect_error;
} else {
    $db_connected = true;
    
    // Создаем БД, если её нет
    $db->query("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($db_name);
    
    $db->query("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB;");

    $db->query("CREATE TABLE IF NOT EXISTS `campaigns` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `audio_main` VARCHAR(255) NOT NULL DEFAULT 'welcome.wav',
        `opt1_action` VARCHAR(50) NOT NULL DEFAULT 'transfer',
        `opt1_param` VARCHAR(255) NOT NULL DEFAULT 'SIP/QueueSales',
        `opt2_action` VARCHAR(50) NOT NULL DEFAULT 'play',
        `opt2_param` VARCHAR(255) NOT NULL DEFAULT 'blacklist_feedback.wav',
        `status` ENUM('active', 'paused', 'completed') DEFAULT 'paused'
    ) ENGINE=InnoDB;");

    $db->query("CREATE TABLE IF NOT EXISTS `subscribers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `campaign_id` INT,
        `phone` VARCHAR(20) NOT NULL,
        `status` ENUM('ready', 'processing', 'success', 'failed') DEFAULT 'ready',
        `attempts` INT DEFAULT 0,
        `dtmf_result` VARCHAR(10) DEFAULT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_target` (`campaign_id`, `phone`),
        INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB;");

    // Создаем администратора по умолчанию (пароль: admin) если таблицы пустые
    $check_user = $db->query("SELECT id FROM users LIMIT 1");
    if ($check_user && $check_user->num_rows == 0) {
        $pwd = password_hash('admin', PASSWORD_DEFAULT);
        $db->query("INSERT INTO users (username, password) VALUES ('admin', '$pwd')");
    }
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if (!$db_connected) {
        echo json_encode(['error' => 'База данных не подключена']);
        exit;
    }

    if ($_GET['action'] === 'get_realtime_data') {
        // Вычисляем показатели для дашборда
        $total_res = $db->query("SELECT COUNT(*) as cnt FROM subscribers");
        $total = $total_res ? $total_res->fetch_assoc()['cnt'] : 0;

        $success_res = $db->query("SELECT COUNT(*) as cnt FROM subscribers WHERE status = 'success'");
        $success = $success_res ? $success_res->fetch_assoc()['cnt'] : 0;

        $failed_res = $db->query("SELECT COUNT(*) as cnt FROM subscribers WHERE status = 'failed'");
        $failed = $failed_res ? $failed_res->fetch_assoc()['cnt'] : 0;

        $proc_res = $db->query("SELECT COUNT(*) as cnt FROM subscribers WHERE status = 'processing'");
        $proc = $proc_res ? $proc_res->fetch_assoc()['cnt'] : 0;

        $ready_res = $db->query("SELECT COUNT(*) as cnt FROM subscribers WHERE status = 'ready'");
        $ready = $ready_res ? $ready_res->fetch_assoc()['cnt'] : 0;

        // Нажатия клавиш
        $key1_res = $db->query("SELECT COUNT(*) as cnt FROM subscribers WHERE dtmf_result = '1'");
        $key1 = $key1_res ? $key1_res->fetch_assoc()['cnt'] : 0;

        $key2_res = $db->query("SELECT COUNT(*) as cnt FROM subscribers WHERE dtmf_result = '2'");
        $key2 = $key2_res ? $key2_res->fetch_assoc()['cnt'] : 0;

        // Последние 10 звонков для лога в реальном времени
        $logs = [];
        $log_res = $db->query("SELECT phone, status, dtmf_result, updated_at FROM subscribers ORDER BY updated_at DESC LIMIT 10");
        if ($log_res) {
            while ($row = $log_res->fetch_assoc()) {
                $logs[] = [
                    'phone' => $row['phone'],
                    'status' => $row['status'],
                    'dtmf' => $row['dtmf_result'],
                    'time' => date('H:i:s', strtotime($row['updated_at']))
                ];
            }
        }

        echo json_encode([
            'total' => (int)$total,
            'success' => (int)$success,
            'failed' => (int)$failed,
            'processing' => (int)$proc,
            'ready' => (int)$ready,
            'key1' => (int)$key1,
            'key2' => (int)$key2,
            'logs' => $logs
        ]);
        exit;
    }

    if ($_GET['action'] === 'get_subscribers_list') {
        $search = isset($_GET['search']) ? $db->real_escape_string($_GET['search']) : '';
        $status = isset($_GET['status']) ? $db->real_escape_string($_GET['status']) : '';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $where = "WHERE campaign_id = 1";
        if ($search !== '') {
            $where .= " AND phone LIKE '%$search%'";
        }
        if ($status !== '' && $status !== 'all') {
            $where .= " AND status = '$status'";
        }

        $count_res = $db->query("SELECT COUNT(*) as cnt FROM subscribers $where");
        $total_records = $count_res ? $count_res->fetch_assoc()['cnt'] : 0;

        $res = $db->query("SELECT phone, status, attempts, dtmf_result, updated_at FROM subscribers $where ORDER BY updated_at DESC LIMIT $limit OFFSET $offset");
        $list = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $list[] = [
                    'phone' => $row['phone'],
                    'status' => $row['status'],
                    'attempts' => (int)$row['attempts'],
                    'dtmf' => $row['dtmf_result'],
                    'time' => date('d.m H:i:s', strtotime($row['updated_at']))
                ];
            }
        }

        echo json_encode([
            'list' => $list,
            'total' => $total_records,
            'page' => $page,
            'limit' => $limit
        ]);
        exit;
    }

    if ($_GET['action'] === 'get_all_subscribers') {
        $res = $db->query("SELECT phone, status, attempts, dtmf_result, updated_at FROM subscribers WHERE campaign_id = 1 ORDER BY updated_at DESC");
        $list = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $status_ru = 'Ожидание';
                if ($row['status'] === 'success') $status_ru = 'Дозвонились (Успех)';
                if ($row['status'] === 'failed') $status_ru = 'Не дозвонились / Занято';
                if ($row['status'] === 'processing') $status_ru = 'Идет вызов';

                $list[] = [
                    'Номер телефона' => $row['phone'],
                    'Текущий статус' => $status_ru,
                    'Попыток вызова' => (int)$row['attempts'],
                    'Ввод клавиши (DTMF)' => $row['dtmf_result'] ? 'Клавиша [' . $row['dtmf_result'] . ']' : 'Нет ввода',
                    'Время обновления' => date('d.m.Y H:i:s', strtotime($row['updated_at']))
                ];
            }
        }
        echo json_encode($list);
        exit;
    }

    if ($_GET['action'] === 'save_ivr') {
        $main_audio = $db->real_escape_string($_POST['mainAudio'] ?? 'welcome.wav');
        $opt1_action = $db->real_escape_string($_POST['opt1Action'] ?? 'transfer');
        $opt1_param = $db->real_escape_string($_POST['opt1Param'] ?? 'SIP/QueueSales');
        $opt2_action = $db->real_escape_string($_POST['opt2Action'] ?? 'play');
        $opt2_param = $db->real_escape_string($_POST['opt2Param'] ?? 'blacklist_feedback.wav');

        $db->query("INSERT INTO campaigns (id, name, audio_main, opt1_action, opt1_param, opt2_action, opt2_param, status) 
                    VALUES (1, 'Главная кампания IVR', '$main_audio', '$opt1_action', '$opt1_param', '$opt2_action', '$opt2_param', 'active')
                    ON DUPLICATE KEY UPDATE 
                    audio_main='$main_audio', opt1_action='$opt1_action', opt1_param='$opt1_param', opt2_action='$opt2_action', opt2_param='$opt2_param'");

        echo json_encode(['status' => 'success', 'message' => 'Сценарий IVR успешно обновлен в Asterisk DB']);
        exit;
    }

    if ($_GET['action'] === 'import_phones') {
        $raw_text = $_POST['phones'] ?? '';
        $lines = explode("\n", $raw_text);
        $imported = 0;
        
        $db->query("INSERT IGNORE INTO campaigns (id, name, audio_main) VALUES (1, 'Главная кампания IVR', 'welcome.wav')");
        $db->query("DELETE FROM subscribers WHERE campaign_id = 1");

        foreach ($lines as $line) {
            $phone = preg_replace('/[^0-9]/', '', trim($line));
            
            if (strlen($phone) === 11 && $phone[0] === '8') {
                $phone = '7' . substr($phone, 1);
            }
            if (strlen($phone) >= 10) {
                $db->query("INSERT IGNORE INTO subscribers (campaign_id, phone, status) VALUES (1, '$phone', 'ready')");
                if ($db->affected_rows > 0) {
                    $imported++;
                }
            }
        }

        echo json_encode(['status' => 'success', 'count' => $imported]);
        exit;
    }

    if ($_GET['action'] === 'toggle_campaign') {
        $status = $_POST['status'] ?? 'paused';
        $db->query("UPDATE campaigns SET status = '$status' WHERE id = 1");
        echo json_encode(['status' => 'success', 'current_status' => $status]);
        exit;
    }

    if ($_GET['action'] === 'reset_stats') {
        $db->query("UPDATE subscribers SET status = 'ready', dtmf_result = NULL, attempts = 0 WHERE campaign_id = 1");
        echo json_encode(['status' => 'success']);
        exit;
    }
}

$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $user = trim($_POST['username']);
    $pass = trim($_POST['password']);

    if ($db_connected) {
        $stmt = $db->prepare("SELECT id, password FROM users WHERE username = ?");
        $stmt->bind_param("s", $user);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (password_verify($pass, $row['password'])) {
                $_SESSION['user'] = $user;
            } else {
                $login_error = 'Неверный пароль!';
            }
        } else {
            $login_error = 'Пользователь не найден!';
        }
    } else {
        if ($user === 'admin' && $pass === 'admin') {
            $_SESSION['user'] = 'admin';
        } else {
            $login_error = 'Неверный логин или пароль (БД оффлайн)';
        }
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['user']);
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

$is_logged_in = isset($_SESSION['user']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asterisk Real-time Dialer Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: {
                            50: '#eef2ff', 100: '#e0e7ff', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 900: '#312e81'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .bar-anim { animation: bounce-wave 1s ease-in-out infinite alternate; }
        @keyframes bounce-wave { 0% { height: 4px; } 100% { height: 32px; } }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-800 min-h-screen font-sans flex flex-col">

    <?php if (!$is_logged_in): ?>
    <div class="fixed inset-0 bg-slate-900 flex items-center justify-center z-50 transition-all duration-300">
        <div class="bg-white p-8 rounded-2xl shadow-2xl max-w-md w-full mx-4 border border-slate-100">
            <div class="text-center mb-8">
                <div class="inline-flex p-3 bg-brand-50 text-brand-600 rounded-2xl mb-4">
                    <i class="fa-solid fa-phone-volume text-3xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-slate-900">Asterisk Realtime Dialer</h2>
                <p class="text-sm text-slate-500 mt-1">Управление обзвоном & интерактивным IVR</p>
                <?php if ($login_error): ?>
                    <div class="mt-3 p-2 bg-rose-50 border border-rose-200 text-rose-600 rounded-lg text-xs font-semibold">
                        <?php echo $login_error; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <form action="" method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">Пользователь</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400">
                            <i class="fa-solid fa-user"></i>
                        </span>
                        <input name="username" type="text" value="admin" required class="w-full pl-10 pr-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">Пароль защиты</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400">
                            <i class="fa-solid fa-lock"></i>
                        </span>
                        <input name="password" type="password" value="admin" required class="w-full pl-10 pr-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition text-sm">
                    </div>
                </div>
                <button type="submit" name="login" class="w-full bg-brand-600 hover:bg-brand-700 text-white font-semibold py-3 px-4 rounded-xl transition duration-150 shadow-lg shadow-brand-100 flex items-center justify-center gap-2">
                    Авторизоваться <i class="fa-solid fa-arrow-right text-xs"></i>
                </button>
            </form>
            <div class="mt-6 text-center text-xs text-slate-400">
                Связка по умолчанию: <span class="font-semibold text-slate-500">admin / admin</span>
            </div>
        </div>
    </div>
    <?php else: ?>

    <div id="main-app" class="flex flex-col min-h-screen">
        
        <header class="bg-white border-b border-slate-200 sticky top-0 z-40">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center gap-3">
                        <div class="bg-brand-600 text-white p-2.5 rounded-xl">
                            <i class="fa-solid fa-headset text-lg"></i>
                        </div>
                        <div>
                            <span class="font-extrabold text-lg text-slate-900 tracking-tight">ASTERISK REALTIME</span>
                            <?php if ($db_connected): ?>
                                <span class="text-xs bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded-md font-semibold border border-emerald-100 ml-2">MySQL Connect: Online</span>
                            <?php else: ?>
                                <span class="text-xs bg-rose-50 text-rose-700 px-2 py-0.5 rounded-md font-semibold border border-rose-100 ml-2">MySQL Connect: Offline</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <nav class="hidden md:flex space-x-1">
                        <button onclick="switchTab('dashboard')" class="tab-btn px-4 py-2 rounded-xl text-sm font-medium text-brand-600 bg-brand-50" id="tab-dashboard">
                            <i class="fa-solid fa-chart-line mr-2"></i>Дашборд Real-time
                        </button>
                        <button onclick="switchTab('ivr')" class="tab-btn px-4 py-2 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900" id="tab-ivr">
                            <i class="fa-solid fa-diagram-project mr-2"></i>Конструктор IVR
                        </button>
                        <button onclick="switchTab('contacts')" class="tab-btn px-4 py-2 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900" id="tab-contacts">
                            <i class="fa-solid fa-users mr-2"></i>База номеров
                        </button>
                        <button onclick="switchTab('export')" class="tab-btn px-4 py-2 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900" id="tab-export">
                            <i class="fa-solid fa-code mr-2"></i>Конфигурационные файлы
                        </button>
                    </nav>

                    <div class="flex items-center gap-4">
                        <div class="text-right hidden sm:block">
                            <div class="text-sm font-semibold text-slate-800">Администратор</div>
                            <div class="text-xs text-slate-400">Asterisk Web Module</div>
                        </div>
                        <a href="?logout=1" class="text-slate-400 hover:text-red-500 p-2 rounded-lg hover:bg-red-50 transition duration-150">
                            <i class="fa-solid fa-right-from-bracket text-lg"></i>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
            
            <!-- РАЗДЕЛ 1: ДАШБОРД -->
            <section id="sec-dashboard" class="space-y-6">
                
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900">Реальный поток обзвона</h1>
                        <p class="text-sm text-slate-500">Автоматический мониторинг БД Asterisk. Информация обновляется каждую секунду.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button onclick="openResetModal()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl text-xs transition flex items-center gap-1.5">
                            <i class="fa-solid fa-arrows-rotate"></i> Сбросить статистику
                        </button>
                        <button id="campaign-status-btn" onclick="toggleCampaign()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-4 rounded-xl text-sm shadow-md flex items-center gap-2 transition duration-150">
                            <i class="fa-solid fa-play"></i>Запустить обзвон
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white p-6 rounded-2xl border border-slate-150 shadow-sm flex items-center justify-between">
                        <div>
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Всего в базе</span>
                            <h3 id="stat-total" class="text-2xl font-extrabold text-slate-900 mt-1">0</h3>
                            <span class="text-xs text-slate-500 mt-2 block"><i class="fa-solid fa-database text-brand-500 mr-1"></i> Контактов в очереди</span>
                        </div>
                        <div class="p-4 bg-brand-50 text-brand-600 rounded-2xl"><i class="fa-solid fa-address-book text-xl"></i></div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-2xl border border-slate-150 shadow-sm flex items-center justify-between">
                        <div>
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Дозвонились (Успех)</span>
                            <h3 id="stat-success" class="text-2xl font-extrabold text-slate-900 mt-1">0</h3>
                            <span id="stat-pct-success" class="text-xs text-emerald-600 font-medium mt-2 block"><i class="fa-solid fa-circle-check mr-1"></i> 0% ответов</span>
                        </div>
                        <div class="p-4 bg-emerald-50 text-emerald-600 rounded-2xl"><i class="fa-solid fa-phone-flip text-xl"></i></div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl border border-slate-150 shadow-sm flex items-center justify-between">
                        <div>
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Ответов в IVR</span>
                            <h3 id="stat-ivr" class="text-2xl font-extrabold text-slate-900 mt-1">0</h3>
                            <span id="stat-pct-ivr" class="text-xs text-indigo-600 font-medium mt-2 block"><i class="fa-solid fa-keyboard mr-1"></i> 0% нажатий кнопок</span>
                        </div>
                        <div class="p-4 bg-indigo-50 text-indigo-600 rounded-2xl"><i class="fa-solid fa-network-wired text-xl"></i></div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl border border-slate-150 shadow-sm flex items-center justify-between">
                        <div>
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Не дозвонились</span>
                            <h3 id="stat-failed" class="text-2xl font-extrabold text-slate-900 mt-1">0</h3>
                            <span id="stat-pct-failed" class="text-xs text-rose-600 font-medium mt-2 block"><i class="fa-solid fa-phone-slash mr-1"></i> 0% неответов</span>
                        </div>
                        <div class="p-4 bg-rose-50 text-rose-600 rounded-2xl"><i class="fa-solid fa-circle-exclamation text-xl"></i></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex flex-col h-[400px]">
                        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                            <div>
                                <h3 class="font-bold text-slate-800 text-base">Текущий живой поток Asterisk</h3>
                                <p class="text-xs text-slate-500">Последние обновления статусов на сервере</p>
                            </div>
                            <span class="bg-slate-200 text-slate-700 text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded">БД ЛОГ</span>
                        </div>
                        <div id="live-call-stream" class="p-4 overflow-y-auto flex-grow space-y-3 font-mono text-xs bg-slate-950 text-slate-300">
                            <div class="text-slate-500 italic">Ожидание запуска прозвона...</div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-6">
                        <div>
                            <h3 class="font-bold text-slate-800 text-base mb-1">Сводный статус обзвона</h3>
                            <p class="text-xs text-slate-400">Графическое представление базы данных</p>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <div class="flex justify-between text-xs font-semibold text-slate-600 mb-1">
                                    <span>Успешно дозвонились (Success)</span>
                                    <span id="progress-success-val">0%</span>
                                </div>
                                <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                    <div id="progress-success-bar" class="bg-emerald-500 h-full w-0 transition-all duration-300"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between text-xs font-semibold text-slate-600 mb-1">
                                    <span>В обработке (Queue/Processing)</span>
                                    <span id="progress-proc-val">0%</span>
                                </div>
                                <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                    <div id="progress-proc-bar" class="bg-brand-500 h-full w-0 transition-all duration-300"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between text-xs font-semibold text-slate-600 mb-1">
                                    <span>Очередь / Не дозвонились</span>
                                    <span id="progress-fail-val">0%</span>
                                </div>
                                <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                    <div id="progress-fail-bar" class="bg-rose-500 h-full w-0 transition-all duration-300"></div>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-slate-100 pt-6">
                            <h4 class="font-bold text-slate-800 text-sm mb-4">Нажатия клавиш IVR (Результат)</h4>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-indigo-50/50 border border-indigo-100 p-4 rounded-xl text-center">
                                    <div class="text-xs font-semibold text-indigo-700">Клавиша [1]</div>
                                    <div id="key1-count" class="text-2xl font-extrabold text-indigo-900 mt-1">0</div>
                                    <div class="text-[10px] text-indigo-600 mt-1" id="key1-action-label">Действие 1</div>
                                </div>

                                <div class="bg-amber-50/50 border border-amber-100 p-4 rounded-xl text-center">
                                    <div class="text-xs font-semibold text-amber-700">Клавиша [2]</div>
                                    <div id="key2-count" class="text-2xl font-extrabold text-amber-900 mt-1">0</div>
                                    <div class="text-[10px] text-amber-600 mt-1" id="key2-action-label">Действие 2</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden space-y-4 p-6">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 pb-4 border-b border-slate-100">
                        <div>
                            <h3 class="font-bold text-slate-800 text-lg">Детальный мониторинг абонентов</h3>
                            <p class="text-xs text-slate-500">Поиск, фильтрация и выгрузка результатов кампании</p>
                        </div>
                        <div class="flex flex-wrap gap-2 items-center">
                            <!-- Кнопка выгрузки Excel -->
                            <button onclick="exportSubscribersToExcel()" class="bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2 px-4 rounded-xl text-xs transition flex items-center gap-1.5 shadow-md shadow-brand-100">
                                <i class="fa-solid fa-file-excel text-sm"></i> Выгрузить отчет в Excel
                            </button>
                        </div>
                    </div>

                    <!-- Фильтры и Поиск -->
                    <div class="flex flex-col sm:flex-row gap-4 justify-between items-center bg-slate-50/60 p-4 rounded-xl">
                        <!-- Поиск -->
                        <div class="relative w-full sm:w-80">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400">
                                <i class="fa-solid fa-magnifying-glass text-xs"></i>
                            </span>
                            <input id="sub-table-search" type="text" oninput="handleTableSearch()" placeholder="Поиск по телефону..." class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition bg-white">
                        </div>

                        <!-- Фильтр по статусу -->
                        <div class="flex gap-1.5 flex-wrap">
                            <button onclick="filterTableStatus('all')" class="sub-filter-btn px-3 py-1.5 text-xs font-semibold rounded-lg bg-brand-600 text-white" id="filter-all">Все</button>
                            <button onclick="filterTableStatus('ready')" class="sub-filter-btn px-3 py-1.5 text-xs font-semibold rounded-lg bg-white text-slate-600 hover:bg-slate-100" id="filter-ready">Ожидают</button>
                            <button onclick="filterTableStatus('processing')" class="sub-filter-btn px-3 py-1.5 text-xs font-semibold rounded-lg bg-white text-slate-600 hover:bg-slate-100" id="filter-processing">Идет вызов</button>
                            <button onclick="filterTableStatus('success')" class="sub-filter-btn px-3 py-1.5 text-xs font-semibold rounded-lg bg-white text-slate-600 hover:bg-slate-100" id="filter-success">Дозвонились</button>
                            <button onclick="filterTableStatus('failed')" class="sub-filter-btn px-3 py-1.5 text-xs font-semibold rounded-lg bg-white text-slate-600 hover:bg-slate-100" id="filter-failed">Не дозвонились</button>
                        </div>
                    </div>

                    <!-- Таблица -->
                    <div class="overflow-x-auto border border-slate-150 rounded-xl">
                        <table class="min-w-full divide-y divide-slate-200 text-xs">
                            <thead class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider text-[10px]">
                                <tr>
                                    <th class="px-6 py-3 text-left">Абонент (Телефон)</th>
                                    <th class="px-6 py-3 text-left">Статус вызова</th>
                                    <th class="px-6 py-3 text-left">Попыток набора</th>
                                    <th class="px-6 py-3 text-left">Нажатая кнопка IVR</th>
                                    <th class="px-6 py-3 text-right">Время активности</th>
                                </tr>
                            </thead>
                            <tbody id="subscribers-table-body" class="bg-white divide-y divide-slate-150 text-slate-700">
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-slate-400 italic">Загрузка данных...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Пагинация -->
                    <div class="flex items-center justify-between pt-2">
                        <span id="table-showing-text" class="text-xs text-slate-500">Показано 0 строк из 0</span>
                        <div class="flex gap-2">
                            <button id="btn-table-prev" onclick="tablePrevPage()" class="p-2 border border-slate-200 rounded-lg hover:bg-slate-50 text-slate-600 disabled:opacity-50 transition" disabled>
                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </button>
                            <button id="btn-table-next" onclick="tableNextPage()" class="p-2 border border-slate-200 rounded-lg hover:bg-slate-50 text-slate-600 disabled:opacity-50 transition" disabled>
                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>

            </section>

            <section id="sec-ivr" class="hidden space-y-6">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">Визуальный конструктор IVR</h1>
                    <p class="text-sm text-slate-500">Задайте сценарий обработки входящих сигналов (DTMF/нажатий кнопок на телефоне)</p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm p-6 flex flex-col gap-6">
                        <div class="border-2 border-dashed border-slate-200 rounded-2xl p-6 bg-slate-50 flex flex-col items-center text-center relative">
                            <span class="absolute -top-3 left-6 bg-brand-600 text-white text-[10px] font-bold uppercase px-3 py-1 rounded-full">Шаг 1: Приветствие</span>
                            <div class="w-12 h-12 bg-brand-100 text-brand-700 rounded-full flex items-center justify-center text-xl mb-3">
                                <i class="fa-solid fa-microphone"></i>
                            </div>
                            <h3 class="font-bold text-slate-800 text-base">Главный аудиофайл</h3>
                            <p class="text-xs text-slate-400 mt-1 max-w-sm">Этот файл воспроизводится сразу после того, как абонент поднял трубку (Answer)</p>
                            
                            <div class="mt-4 flex flex-col sm:flex-row items-center gap-3 w-full max-w-md">
                                <select id="ivr-main-audio" onchange="updateIVRConfig()" class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm bg-white outline-none focus:ring-2 focus:ring-brand-500">
                                    <option value="welcome.wav">welcome.wav (Основное предложение)</option>
                                    <option value="promo_call.wav">promo_call.wav (Акция май)</option>
                                    <option value="alert.wav">alert.wav (Экстренное оповещение)</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-center text-slate-300 text-2xl -my-2">
                            <i class="fa-solid fa-arrow-down-long"></i>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="border border-slate-200 rounded-2xl p-5 bg-white relative">
                                <span class="absolute -top-3 left-4 bg-emerald-500 text-white text-[10px] font-bold uppercase px-3 py-1 rounded-full">Клавиша [1]</span>
                                <div class="flex items-center gap-3 mb-4 mt-1">
                                    <div class="w-10 h-10 bg-emerald-50 text-emerald-700 rounded-xl flex items-center justify-center text-lg"><i class="fa-solid fa-headset"></i></div>
                                    <div>
                                        <h4 class="font-bold text-slate-800 text-sm">Ветка при нажатии "1"</h4>
                                        <p class="text-[10px] text-slate-400">Например, перевод звонка в колл-центр</p>
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <div>
                                        <label class="text-[10px] uppercase font-bold text-slate-400">Действие</label>
                                        <select id="ivr-opt1-action" onchange="updateIVRConfig()" class="w-full border border-slate-200 rounded-xl px-2.5 py-1.5 text-xs mt-1">
                                            <option value="transfer">Перевод на внутренний номер SIP</option>
                                            <option value="play">Проиграть аудио-файл</option>
                                            <option value="hangup">Положить трубку (Завершить)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-[10px] uppercase font-bold text-slate-400">Параметр действия</label>
                                        <input id="ivr-opt1-param" oninput="updateIVRConfig()" type="text" value="SIP/QueueSales" class="w-full border border-slate-200 rounded-xl px-2.5 py-1.5 text-xs mt-1 font-mono">
                                    </div>
                                </div>
                            </div>

                            <div class="border border-slate-200 rounded-2xl p-5 bg-white relative">
                                <span class="absolute -top-3 left-4 bg-rose-500 text-white text-[10px] font-bold uppercase px-3 py-1 rounded-full">Клавиша [2]</span>
                                <div class="flex items-center gap-3 mb-4 mt-1">
                                    <div class="w-10 h-10 bg-rose-50 text-rose-700 rounded-xl flex items-center justify-center text-lg"><i class="fa-solid fa-ban"></i></div>
                                    <div>
                                        <h4 class="font-bold text-slate-800 text-sm">Ветка при нажатии "2"</h4>
                                        <p class="text-[10px] text-slate-400">Например, отказ или автоинформатор</p>
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <div>
                                        <label class="text-[10px] uppercase font-bold text-slate-400">Действие</label>
                                        <select id="ivr-opt2-action" onchange="updateIVRConfig()" class="w-full border border-slate-200 rounded-xl px-2.5 py-1.5 text-xs mt-1">
                                            <option value="play">Проиграть аудио-файл</option>
                                            <option value="transfer">Перевод на внутренний номер</option>
                                            <option value="hangup">Положить трубку (Завершить)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-[10px] uppercase font-bold text-slate-400">Параметр действия (файл/SIP)</label>
                                        <input id="ivr-opt2-param" oninput="updateIVRConfig()" type="text" value="blacklist_feedback.wav" class="w-full border border-slate-200 rounded-xl px-2.5 py-1.5 text-xs mt-1 font-mono">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end pt-2">
                            <button onclick="saveIVRToServer()" class="bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2.5 px-6 rounded-xl text-sm transition shadow-md">
                                <i class="fa-solid fa-floppy-disk mr-2"></i> Сохранить настройки в БД Asterisk
                            </button>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                        <h3 class="font-bold text-slate-800 text-base">Как это работает?</h3>
                        <div class="text-xs text-slate-500 space-y-3 leading-relaxed">
                            <p class="font-semibold text-slate-700">Что такое DTMF-сценарий?</p>
                            <p>В процессе обзвона Asterisk непрерывно слушает аудиопоток от абонента. Нажатие клавиши посылает сигнал, который улавливает Asterisk и передает логику в диалплан.</p>
                            <p class="font-semibold text-slate-700">Ограничение по времени (WaitExten):</p>
                            <p>По завершении аудиозаписи система ожидает нажатия кнопки в течение 5 секунд. Если абонент ничего не нажимает, вызов завершается и помечается как прослушанный.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="sec-contacts" class="hidden space-y-6">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">Умный импорт базы (Excel / CSV / TXT)</h1>
                    <p class="text-sm text-slate-500">Загрузите Excel-файл (.xlsx, .xls) или перетащите файл прямо в форму. Система найдет номера телефонов сама!</p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-6">
                        <div id="drop-zone" class="border-2 border-dashed border-slate-200 hover:border-brand-500 rounded-2xl p-8 bg-slate-50/50 hover:bg-brand-50/20 text-center transition cursor-pointer relative group">
                            <input type="file" id="file-input" accept=".xlsx, .xls, .csv, .txt" class="hidden">
                            <div class="w-16 h-16 bg-white shadow-sm border border-slate-100 text-slate-400 group-hover:text-brand-500 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-4 transition">
                                <i class="fa-solid fa-file-excel"></i>
                            </div>
                            <h3 class="font-bold text-slate-800 text-base">Перетащите Excel-файл или кликните здесь</h3>
                            <p class="text-xs text-slate-400 mt-1">Поддерживаются форматы Excel (.xlsx, .xls), CSV, TXT</p>
                            <span class="mt-3 inline-block text-xs bg-slate-200 text-slate-700 px-3 py-1 rounded-full font-semibold group-hover:bg-brand-500 group-hover:text-white transition">Выбрать файл</span>
                        </div>

                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <label class="block text-sm font-semibold text-slate-700">Или вставьте номера телефонов текстом</label>
                                <span class="text-xs text-slate-400 font-medium">Каждый номер с новой строки</span>
                            </div>
                            <textarea id="raw-phones" rows="6" oninput="analyzePhones()" class="w-full border border-slate-200 rounded-xl p-4 text-sm font-mono focus:ring-2 focus:ring-brand-500 outline-none" placeholder="77017778899&#10;+7 (702) 111-22-33&#10;87034445566"></textarea>
                        </div>

                        <div class="flex justify-between items-center text-xs text-slate-500 border-t border-slate-100 pt-4">
                            <span>Автоматическое удаление пробелов, дубликатов и букв.</span>
                            <button onclick="cleanAndLoadPhones()" class="bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2.5 px-5 rounded-xl text-sm shadow-md transition duration-150">
                                Загрузить в базу MySQL
                            </button>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-6">
                        <div>
                            <h3 class="font-bold text-slate-800 text-base mb-1">Анализатор Базы</h3>
                            <p class="text-xs text-slate-400">Анализ телефонного списка до импорта</p>
                        </div>

                        <div class="space-y-3 text-sm">
                            <div class="flex justify-between p-2 rounded-lg bg-slate-50">
                                <span class="text-slate-500">Всего строк / ячеек:</span>
                                <span id="raw-count" class="font-bold text-slate-800">0</span>
                            </div>
                            <div class="flex justify-between p-2 rounded-lg bg-emerald-50">
                                <span class="text-emerald-700">Уникальных валидных номеров:</span>
                                <span id="valid-count" class="font-bold text-emerald-800">0</span>
                            </div>
                            <div class="flex justify-between p-2 rounded-lg bg-rose-50">
                                <span class="text-rose-700">Дубликатов или ошибок:</span>
                                <span id="fail-count" class="font-bold text-rose-800">0</span>
                            </div>
                        </div>

                        <div id="file-info-box" class="hidden p-3 bg-brand-50/50 border border-brand-100 rounded-xl">
                            <div class="text-xs font-semibold text-brand-800">Загруженный файл:</div>
                            <div id="file-name" class="text-xs text-slate-600 truncate mt-1 font-mono">-</div>
                        </div>

                        <div class="border-t border-slate-100 pt-6 space-y-4">
                            <h4 class="font-bold text-slate-800 text-sm">Прогноз времени обзвона</h4>
                            <div class="text-xs text-slate-500 space-y-2">
                                <div class="flex justify-between">
                                    <span>SIP транк лимит:</span>
                                    <span class="font-semibold text-slate-700">20 одновременных линий</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Средняя длина вызова:</span>
                                    <span class="font-semibold text-slate-700">45 секунд</span>
                                </div>
                                <div class="flex justify-between border-t border-dashed border-slate-100 pt-2 font-bold text-slate-800">
                                    <span>Оцененное время обзвона:</span>
                                    <span id="estimated-time" class="text-brand-600">0 мин.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="sec-export" class="hidden space-y-6">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">Центр Разработчика (Файлы Бэкенда)</h1>
                    <p class="text-sm text-slate-500">Скопируйте эти файлы и разместите их на сервере Asterisk в соответствующие директории.</p>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex flex-col h-[550px]">
                    <div class="flex border-b border-slate-150 bg-slate-50">
                        <button onclick="switchCodeTab('cron')" class="code-tab-btn px-6 py-3 font-semibold text-xs uppercase tracking-wider text-brand-600 border-b-2 border-brand-500 bg-white" id="codetab-cron">
                            PHP Cron Script (/var/www/html/dialer_cron.php)
                        </button>
                        <button onclick="switchCodeTab('asterisk')" class="code-tab-btn px-6 py-3 font-semibold text-xs uppercase tracking-wider text-slate-500 hover:text-slate-800" id="codetab-asterisk">
                            Asterisk Dialplan (/etc/asterisk/extensions.conf)
                        </button>
                    </div>

                    <div class="p-6 flex-grow overflow-auto bg-slate-900 text-slate-300 font-mono text-xs relative">
                        <button onclick="copyCurrentCode()" class="absolute top-4 right-4 bg-slate-800 hover:bg-slate-700 text-white px-3 py-1.5 rounded-lg flex items-center gap-1.5 font-sans font-bold transition">
                            <i class="fa-solid fa-copy"></i> Скопировать
                        </button>
                        <pre id="code-output" class="whitespace-pre"></pre>
                    </div>
                </div>
            </section>

        </main>

        <div id="reset-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 hidden transition-all duration-300">
            <div class="bg-white p-6 rounded-2xl shadow-xl max-w-sm w-full mx-4 border border-slate-100 text-center space-y-4">
                <div class="inline-flex p-3 bg-rose-50 text-rose-600 rounded-full">
                    <i class="fa-solid fa-circle-exclamation text-2xl animate-bounce"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Обнулить все звонки?</h3>
                    <p class="text-xs text-slate-500 mt-1">Все статусы абонентов вернутся в READY. Статистика сбросится. Это действие необратимо.</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="closeResetModal()" class="w-1/2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 rounded-xl text-xs transition">Отмена</button>
                    <button onclick="confirmResetStats()" class="w-1/2 bg-rose-600 hover:bg-rose-700 text-white font-semibold py-2.5 rounded-xl text-xs transition">Да, сбросить</button>
                </div>
            </div>
        </div>

        <footer class="bg-white border-t border-slate-200 py-6 mt-12 text-center text-xs text-slate-400">
            <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row justify-between items-center gap-4">
                <div>&copy; 2026 Asterisk Telephony Real-time Suite.</div>
                <div class="flex gap-4">
                    <span class="font-semibold text-emerald-600">Система активна и готова к работе.</span>
                </div>
            </div>
        </footer>
    </div>
    <?php endif; ?>

    <script>
        // Инициализация данных
        let activeTab = 'dashboard';
        let campaignStatus = 'paused';
        let autoRefreshInterval = null;

        // Пагинация таблицы абонентов
        let tableCurrentPage = 1;
        let tableSearchQuery = '';
        let tableSelectedStatus = 'all';

        // Настройки IVR по умолчанию
        let ivrConfig = {
            mainAudio: 'welcome.wav',
            opt1Action: 'transfer',
            opt1Param: 'SIP/QueueSales',
            opt2Action: 'play',
            opt2Param: 'blacklist_feedback.wav'
        };

        // Загрузка настроек при старте
        window.onload = function() {
            <?php if ($is_logged_in): ?>
                updateIVRConfig();
                // Запускаем real-time опрос базы данных каждые 1500 мс
                startRealtimePolling();
                // Загружаем первый раз таблицу абонентов
                loadSubscribersTable();
                // Инициализация Drag and Drop обработчиков
                initDragAndDrop();
            <?php endif; ?>
        };

        function loadSubscribersTable() {
            const tbody = document.getElementById('subscribers-table-body');
            const searchVal = encodeURIComponent(tableSearchQuery);
            
            fetch(`?action=get_subscribers_list&page=${tableCurrentPage}&search=${searchVal}&status=${tableSelectedStatus}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.list || data.list.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="5" class="px-6 py-6 text-center text-slate-400 italic">Записи не найдены или база пуста</td></tr>`;
                        document.getElementById('table-showing-text').innerText = 'Показано 0 строк';
                        document.getElementById('btn-table-prev').disabled = true;
                        document.getElementById('btn-table-next').disabled = true;
                        return;
                    }

                    let html = '';
                    data.list.forEach(row => {
                        let badgeColor = 'bg-slate-100 text-slate-700';
                        let statusText = 'Ожидание';

                        if (row.status === 'success') {
                            badgeColor = 'bg-emerald-100 text-emerald-800';
                            statusText = 'Дозвонились (Успех)';
                        } else if (row.status === 'failed') {
                            badgeColor = 'bg-rose-100 text-rose-800';
                            statusText = 'Не ответил';
                        } else if (row.status === 'processing') {
                            badgeColor = 'bg-amber-100 text-amber-800 animate-pulse';
                            statusText = 'Идет вызов';
                        }

                        let dtmfText = row.dtmf ? `<span class="px-2 py-1 bg-indigo-50 border border-indigo-100 rounded-md font-bold text-indigo-700">Клавиша [${row.dtmf}]</span>` : '<span class="text-slate-400">-</span>';

                        html += `<tr class="hover:bg-slate-50 transition duration-150">
                            <td class="px-6 py-3.5 font-semibold text-slate-800 font-mono">${row.phone}</td>
                            <td class="px-6 py-3.5"><span class="px-2.5 py-1 text-[10px] font-bold rounded-full ${badgeColor}">${statusText}</span></td>
                            <td class="px-6 py-3.5 text-center font-mono font-bold text-slate-500">${row.attempts}</td>
                            <td class="px-6 py-3.5">${dtmfText}</td>
                            <td class="px-6 py-3.5 text-right text-slate-400 font-mono">${row.time}</td>
                        </tr>`;
                    });

                    tbody.innerHTML = html;

                    // Пагинация текста
                    const totalPages = Math.ceil(data.total / data.limit);
                    const showingFrom = (data.page - 1) * data.limit + 1;
                    const showingTo = Math.min(data.page * data.limit, data.total);
                    document.getElementById('table-showing-text').innerText = `Показано с ${showingFrom} по ${showingTo} из ${data.total}`;

                    // Кнопки управления
                    document.getElementById('btn-table-prev').disabled = (data.page <= 1);
                    document.getElementById('btn-table-next').disabled = (data.page >= totalPages);
                })
                .catch(err => console.error('Ошибка загрузки таблицы абонентов:', err));
        }

        function handleTableSearch() {
            tableSearchQuery = document.getElementById('sub-table-search').value;
            tableCurrentPage = 1;
            loadSubscribersTable();
        }

        function filterTableStatus(status) {
            tableSelectedStatus = status;
            tableCurrentPage = 1;
            
            // Смена стилей кнопок фильтров
            document.querySelectorAll('.sub-filter-btn').forEach(btn => {
                btn.className = "sub-filter-btn px-3 py-1.5 text-xs font-semibold rounded-lg bg-white text-slate-600 hover:bg-slate-100";
            });
            document.getElementById(`filter-${status}`).className = "sub-filter-btn px-3 py-1.5 text-xs font-semibold rounded-lg bg-brand-600 text-white";

            loadSubscribersTable();
        }

        function tablePrevPage() {
            if (tableCurrentPage > 1) {
                tableCurrentPage--;
                loadSubscribersTable();
            }
        }

        function tableNextPage() {
            tableCurrentPage++;
            loadSubscribersTable();
        }

        function exportSubscribersToExcel() {
            fetch('?action=get_all_subscribers')
                .then(res => res.json())
                .then(data => {
                    if (data.length === 0) {
                        alert('Нет данных для выгрузки. База номеров пуста.');
                        return;
                    }

                    // Используем SheetJS для экспорта в XLSX на клиенте
                    const worksheet = XLSX.utils.json_to_sheet(data);
                    const workbook = XLSX.utils.book_new();
                    XLSX.utils.book_append_sheet(workbook, worksheet, "Результаты обзвона");

                    // Настройка ширины колонок для красивого вида
                    worksheet["!cols"] = [
                        { wch: 20 }, // Телефон
                        { wch: 25 }, // Статус
                        { wch: 15 }, // Попыток
                        { wch: 25 }, // Клавиша
                        { wch: 22 }  // Дата обновления
                    ];

                    // Генерация и запуск скачивания
                    XLSX.writeFile(workbook, "Otchet_Asterisk_Dialer.xlsx");
                })
                .catch(err => console.error('Ошибка экспорта в Excel:', err));
        }

        function openResetModal() {
            document.getElementById('reset-modal').classList.remove('hidden');
        }

        function closeResetModal() {
            document.getElementById('reset-modal').classList.add('hidden');
        }

        function confirmResetStats() {
            closeResetModal();
            fetch('?action=reset_stats')
                .then(res => res.json())
                .then(() => {
                    loadSubscribersTable();
                    alert('База успешно возвращена в исходное состояние!');
                });
        }

        // Инициализация Drag and Drop
        function initDragAndDrop() {
            const dropZone = document.getElementById('drop-zone');
            const fileInput = document.getElementById('file-input');

            if (!dropZone) return;

            dropZone.addEventListener('click', () => fileInput.click());

            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('border-brand-500', 'bg-brand-50/20');
            });

            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('border-brand-500', 'bg-brand-50/20');
            });

            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('border-brand-500', 'bg-brand-50/20');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    handleUploadedFile(files[0]);
                }
            });

            fileInput.addEventListener('change', (e) => {
                if (e.target.files.length > 0) {
                    handleUploadedFile(e.target.files[0]);
                }
            });
        }

        // Парсинг загруженного файла на лету
        function handleUploadedFile(file) {
            const ext = file.name.split('.').pop().toLowerCase();
            document.getElementById('file-name').innerText = file.name;
            document.getElementById('file-info-box').classList.remove('hidden');

            const reader = new FileReader();

            if (ext === 'xlsx' || ext === 'xls') {
                reader.onload = function(e) {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                    const csv = XLSX.utils.sheet_to_csv(firstSheet);
                    parseAndFillTextarea(csv);
                };
                reader.readAsArrayBuffer(file);
            } else {
                reader.onload = function(e) {
                    parseAndFillTextarea(e.target.result);
                };
                reader.readAsText(file);
            }
        }

        function parseAndFillTextarea(text) {
            const matches = text.match(/[+\d]{1,4}?[\s-]?\(?\d{3}\)?[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}/g) || text.match(/\d{10,15}/g);
            
            if (matches && matches.length > 0) {
                const cleanPhones = matches.map(num => {
                    let clean = num.replace(/[^0-9]/g, '');
                    if (clean.length === 11 && clean.startsWith('8')) {
                        clean = '7' + clean.substring(1);
                    }
                    return clean;
                }).filter(num => num.length >= 10);

                document.getElementById('raw-phones').value = cleanPhones.join('\n');
                analyzePhones();
            } else {
                alert('Не удалось распознать номера телефонов в этом файле.');
            }
        }

        function switchTab(tabId) {
            activeTab = tabId;
            document.querySelectorAll('main > section').forEach(sec => sec.classList.add('hidden'));
            document.getElementById(`sec-${tabId}`).classList.remove('hidden');

            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.className = "tab-btn px-4 py-2 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900";
            });
            document.getElementById(`tab-${tabId}`).className = "tab-btn px-4 py-2 rounded-xl text-sm font-medium text-brand-600 bg-brand-50";

            if (tabId === 'dashboard') {
                loadSubscribersTable();
            }
        }

        function startRealtimePolling() {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
            
            autoRefreshInterval = setInterval(() => {
                fetch('?action=get_realtime_data')
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) return;
                        
                        document.getElementById('stat-total').innerText = data.total.toLocaleString();
                        document.getElementById('stat-success').innerText = data.success.toLocaleString();
                        document.getElementById('stat-failed').innerText = data.failed.toLocaleString();
                        document.getElementById('stat-ivr').innerText = (data.key1 + data.key2).toLocaleString();

                        const totalCallsDone = data.success + data.failed + data.processing;
                        const pctSuccess = totalCallsDone > 0 ? Math.round((data.success / totalCallsDone) * 100) : 0;
                        const pctFailed = totalCallsDone > 0 ? Math.round((data.failed / totalCallsDone) * 100) : 0;
                        const pctIvr = data.success > 0 ? Math.round(((data.key1 + data.key2) / data.success) * 100) : 0;

                        document.getElementById('stat-pct-success').innerHTML = `<i class="fa-solid fa-circle-check mr-1"></i> ${pctSuccess}% дозвона`;
                        document.getElementById('stat-pct-failed').innerHTML = `<i class="fa-solid fa-phone-slash mr-1"></i> ${pctFailed}% неотвеченных`;
                        document.getElementById('stat-pct-ivr').innerHTML = `<i class="fa-solid fa-computer-mouse mr-1"></i> ${pctIvr}% конверсия кнопок`;

                        document.getElementById('key1-count').innerText = data.key1;
                        document.getElementById('key2-count').innerText = data.key2;

                        document.getElementById('progress-success-val').innerText = `${pctSuccess}%`;
                        document.getElementById('progress-success-bar').style.width = `${pctSuccess}%`;

                        const pctProc = data.total > 0 ? Math.round((data.processing / data.total) * 100) : 0;
                        document.getElementById('progress-proc-val').innerText = `${pctProc}%`;
                        document.getElementById('progress-proc-bar').style.width = `${pctProc}%`;

                        document.getElementById('progress-fail-val').innerText = `${pctFailed}%`;
                        document.getElementById('progress-fail-bar').style.width = `${pctFailed}%`;

                        // Обновление потока лога
                        const logStream = document.getElementById('live-call-stream');
                        if (data.logs.length === 0) {
                            logStream.innerHTML = `<div class="text-slate-500 italic">База пуста или звонков еще не было.</div>`;
                        } else {
                            let html = '';
                            data.logs.forEach(log => {
                                let statusColor = 'text-slate-400';
                                let statusText = log.status.toUpperCase();
                                if (log.status === 'success') statusColor = 'text-emerald-400';
                                if (log.status === 'failed') statusColor = 'text-rose-400';
                                if (log.status === 'processing') statusColor = 'text-amber-400 animate-pulse';

                                let dtmfText = log.dtmf ? ` [КЛАВИША: ${log.dtmf}]` : '';

                                html += `<div class="border-b border-slate-900 pb-1.5">
                                    <span class="text-slate-500">[${log.time}]</span> 
                                    <span class="font-bold text-slate-100">${log.phone}</span> 
                                    --> <span class="${statusColor} font-semibold">${statusText}</span>
                                    <span class="text-indigo-300 font-bold">${dtmfText}</span>
                                </div>`;
                            });
                            logStream.innerHTML = html;
                        }

                        // Если кампания активна, мягко обновляем детальную таблицу оператора на текущей странице
                        if (campaignStatus === 'active') {
                            loadSubscribersTable();
                        }
                    })
                    .catch(err => console.error('Ошибка AJAX Real-time поллинга:', err));
            }, 1000);
        }

        function saveIVRToServer() {
            let formData = new FormData();
            formData.append('mainAudio', ivrConfig.mainAudio);
            formData.append('opt1Action', ivrConfig.opt1Action);
            formData.append('opt1Param', ivrConfig.opt1Param);
            formData.append('opt2Action', ivrConfig.opt2Action);
            formData.append('opt2Param', ivrConfig.opt2Param);

            fetch('?action=save_ivr', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                generateCodes();
            });
        }

        function updateIVRConfig() {
            ivrConfig.mainAudio = document.getElementById('ivr-main-audio').value;
            ivrConfig.opt1Action = document.getElementById('ivr-opt1-action').value;
            ivrConfig.opt1Param = document.getElementById('ivr-opt1-param').value;
            ivrConfig.opt2Action = document.getElementById('ivr-opt2-action').value;
            ivrConfig.opt2Param = document.getElementById('ivr-opt2-param').value;

            document.getElementById('key1-action-label').innerText = ivrConfig.opt1Action === 'transfer' ? `Перевод на ${ivrConfig.opt1Param}` : `Файл: ${ivrConfig.opt1Param}`;
            document.getElementById('key2-action-label').innerText = ivrConfig.opt2Action === 'play' ? `Файл: ${ivrConfig.opt2Param}` : `Перевод на ${ivrConfig.opt2Param}`;

            generateCodes();
        }

        function analyzePhones() {
            const val = document.getElementById('raw-phones').value;
            const lines = val.split('\n').map(l => l.trim()).filter(l => l.length > 0);
            
            let uniquePhones = [];
            lines.forEach(line => {
                let clean = line.replace(/[^0-9]/g, '');
                if (clean.length === 11 && clean.startsWith('8')) {
                    clean = '7' + clean.substring(1);
                }
                if (clean.length >= 10 && !uniquePhones.includes(clean)) {
                    uniquePhones.push(clean);
                }
            });

            document.getElementById('raw-count').innerText = lines.length;
            document.getElementById('valid-count').innerText = uniquePhones.length;
            document.getElementById('fail-count').innerText = Math.max(0, lines.length - uniquePhones.length);

            const min = Math.round((uniquePhones.length / 20) * 0.75);
            document.getElementById('estimated-time').innerText = `${min} мин.`;
        }

        function cleanAndLoadPhones() {
            const val = document.getElementById('raw-phones').value;
            let formData = new FormData();
            formData.append('phones', val);

            fetch('?action=import_phones', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                alert(`Успешно очищено и загружено в MySQL: ${data.count} номеров!`);
                switchTab('dashboard');
                loadSubscribersTable();
            });
        }

        function toggleCampaign() {
            campaignStatus = campaignStatus === 'paused' ? 'active' : 'paused';
            
            let formData = new FormData();
            formData.append('status', campaignStatus);

            fetch('?action=toggle_campaign', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                const btn = document.getElementById('campaign-status-btn');
                if (data.current_status === 'active') {
                    btn.className = "bg-rose-600 hover:bg-rose-700 text-white font-semibold py-2 px-4 rounded-xl text-sm shadow-md flex items-center gap-2 transition duration-150";
                    btn.innerHTML = '<i class="fa-solid fa-pause"></i> Поставить на паузу';
                } else {
                    btn.className = "bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-4 rounded-xl text-sm shadow-md flex items-center gap-2 transition duration-150";
                    btn.innerHTML = '<i class="fa-solid fa-play"></i> Запустить обзвон';
                }
            });
        }

        // КОД-ЭКСПОРТ (ГЕНЕРАЦИЯ НА ЛЕТУ)
        let activeCodeTab = 'cron';
        let generatedCodes = { asterisk: '', cron: '' };

        function switchCodeTab(subtabId) {
            activeCodeTab = subtabId;
            document.querySelectorAll('.code-tab-btn').forEach(btn => {
                btn.className = "code-tab-btn px-6 py-3 font-semibold text-xs uppercase tracking-wider text-slate-500 hover:text-slate-800";
            });
            document.getElementById(`codetab-${subtabId}`).className = "code-tab-btn px-6 py-3 font-semibold text-xs uppercase tracking-wider text-brand-600 border-b-2 border-brand-500 bg-white";
            document.getElementById('code-output').innerText = generatedCodes[subtabId];
        }

        function generateCodes() {
            let asteriskAction1 = ivrConfig.opt1Action === 'transfer' ? `Dial(${ivrConfig.opt1Param},30,rt)` : `Playback(ru/${ivrConfig.opt1Param})`;
            let asteriskAction2 = ivrConfig.opt2Action === 'transfer' ? `Dial(${ivrConfig.opt2Param},30,rt)` : `Playback(ru/${ivrConfig.opt2Param})`;

            generatedCodes.asterisk = `; Добавьте этот фрагмент в конец вашего /etc/asterisk/extensions_custom.conf\n\n` +
                `[auto-dialer-ivr]\n` +
                `exten => s,1,NoOp(=== Обзвон. Абонент ID \${SUB_ID} снял трубку ===)\n` +
                `exten => s,n,Answer()\n` +
                `; Безопасность: Как только клиент ответил, сразу помечаем в БД статус Success,\n` +
                `; чтобы полностью исключить любые повторные попытки вызова номера!\n` +
                `exten => s,n,System(mysql -u ${db_user} -p'${db_pass}' ${db_name} -e "UPDATE subscribers SET status='success' WHERE id=\${SUB_ID}")\n` +
                `exten => s,n,Background(\${MAIN_AUDIO})\n` +
                `exten => s,n,WaitExten(5) ; Ждем нажатия клавиши 5 секунд\n` +
                `exten => s,n,Hangup()\n\n` +
                `; --- Обработка клавиши [1] ---\n` +
                `exten => 1,1,NoOp(=== Клиент выбрал Клавишу 1 ===)\n` +
                `exten => 1,n,System(mysql -u ${db_user} -p'${db_pass}' ${db_name} -e "UPDATE subscribers SET dtmf_result='1' WHERE id=\${SUB_ID}")\n` +
                `exten => 1,n,${asteriskAction1}\n` +
                `exten => 1,n,Hangup()\n\n` +
                `; --- Обработка клавиши [2] ---\n` +
                `exten => 2,1,NoOp(=== Клиент выбрал Клавишу 2 ===)\n` +
                `exten => 2,n,System(mysql -u ${db_user} -p'${db_pass}' ${db_name} -e "UPDATE subscribers SET dtmf_result='2' WHERE id=\${SUB_ID}")\n` +
                `exten => 2,n,${asteriskAction2}\n` +
                `exten => 2,n,Hangup()\n\n` +
                `exten => t,1,Hangup()\n` +
                `exten => i,1,Hangup()`;

            generatedCodes.cron = `<?php\n` +
                `/**\n` +
                ` * Smart Asterisk Call Generator (dialer_cron.php)\n` +
                ` * Разместите в /var/www/dialer_cron.php\n` +
                ` * Настройте Cron: * * * * * php /var/www/dialer_cron.php > /dev/null 2>&1\n` +
                ` */\n\n` +
                `$db_host = '${db_host}';\n` +
                `$db_user = '${db_user}';\n` +
                `$db_pass = '${db_pass}';\n` +
                `$db_name = '${db_name}';\n\n` +
                `$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);\n` +
                `if ($conn->connect_error) {\n` +
                `    die("DB Connection failed: " . $conn->connect_error);\n` +
                `}\n\n` +
                `// Ограничитель емкости (Тайминг транка: 20 одновременных звонков)\n` +
                `$max_channels = 20;\n` +
                `$temp_dir = "/tmp/";\n` +
                `$spool_dir = "/var/spool/asterisk/outgoing/";\n\n` +
                `// Считаем активные .call файлы в спулере Asterisk\n` +
                `$current_files = glob($spool_dir . "*.call");\n` +
                `$active_calls = count($current_files);\n\n` +
                `if ($active_calls >= $max_channels) {\n` +
                `    exit(); // Буфер полон, ждем\n` +
                `}\n\n` +
                `$slots_available = $max_channels - $active_calls;\n\n` +
                `// Получаем свежие номера к прозвону\n` +
                `$sql = "SELECT s.id, s.phone, c.audio_main \n` +
                `        FROM subscribers s \n` +
                `        JOIN campaigns c ON s.campaign_id = c.id \n` +
                `        WHERE s.status = 'ready' AND c.status = 'active' \n` +
                `        LIMIT $slots_available";\n\n` +
                `$result = $conn->query($sql);\n` +
                `if ($result && $result->num_rows > 0) {\n` +
                `    while ($row = $result->fetch_assoc()) {\n` +
                `        $id = $row['id'];\n` +
                `        $phone = $row['phone'];\n` +
                `        $audio_name = pathinfo($row['audio_main'], PATHINFO_FILENAME);\n\n` +
                `        $call_file_name = "autocall_{$id}.call";\n` +
                `        $temp_file = $temp_dir . $call_file_name;\n\n` +
                `        // Создаем тело Call-файла\n` +
                `        // Для FreePBX отправляем через Local Channel для задействования исходящих маршрутов (Outbound Routes)\n` +
                `        $content = "Channel: Local/{$phone}@from-internal\\n";\n` +
                `        $content .= "MaxRetries: 1\\n";\n` +
                `        $content .= "RetryTime: 300\\n";\n` +
                `        $content .= "WaitTime: 40\\n";\n` +
                `        $content .= "Context: auto-dialer-ivr\\n";\n` +
                `        $content .= "Extension: s\\n";\n` +
                `        $content .= "Priority: 1\\n";\n` +
                `        $content .= "Set: SUB_ID={$id}\\n";\n` +
                `        $content .= "Set: MAIN_AUDIO={$audio_name}\\n";\n\n` +
                `        // Лочим статус в БД (processing), чтобы исключить параллельный захват\n` +
                `        $conn->query("UPDATE subscribers SET status='processing', attempts = attempts + 1 WHERE id=$id");\n\n` +
                `        // Безопасная запись через tmp во избежание захвата недозаписанного файла Asterisk'ом\n` +
                `        file_put_contents($temp_file, $content);\n` +
                `        rename($temp_file, $spool_dir . $call_file_name);\n` +
                `    }\n` +
                `}\n` +
                `$conn->close();\n` +
                `?>`;

            switchCodeTab(activeCodeTab);
        }

        function copyCurrentCode() {
            const text = document.getElementById('code-output').innerText;
            navigator.clipboard.writeText(text).then(() => {
                alert('Код успешно скопирован в буфер обмена!');
            }).catch(err => {
                alert('Ошибка копирования: ', err);
            });
        }
    </script>
</body>
</html>
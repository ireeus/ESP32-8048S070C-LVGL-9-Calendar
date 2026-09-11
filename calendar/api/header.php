<?php
// header.php - Unified header and navigation
include 'config.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$is_logged_in = isset($_SESSION['user_id']);
$page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo ucfirst(str_replace('.php', '', $page)); ?> - Custom Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/weather-icons/2.0.12/css/weather-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #6B7280, #3B82F6);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
        }
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 1rem;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            padding: 1.5rem;
            max-width: 90vw;
            width: 100%;
            margin: 0 auto;
        }
        .btn {
            transition: background-color 0.3s ease, transform 0.2s ease;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            touch-action: manipulation;
        }
        .btn:hover {
            transform: scale(1.05);
        }
        .code-box {
            font-family: 'Courier New', monospace;
            letter-spacing: 0.05em;
            background: linear-gradient(45deg, #E5E7EB, #F3F4F6);
            border: 2px dashed #3B82F6;
            padding: 1rem;
            word-break: break-all;
        }
        #eventPopup {
            display: none;
            position: fixed;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            max-height: 80vh;
            overflow-y: auto;
            width: 90vw;
            max-width: 400px;
        }
        #eventPopupOverlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
        #calendar {
            margin-top: 1.5rem;
        }
        input, textarea {
            font-size: 1rem !important;
            padding: 0.75rem !important;
        }
        .event-table {
            font-size: 0.875rem;
        }
        .event-table th, .event-table td {
            padding: 0.5rem 0.75rem;
            white-space: nowrap;
        }
        .event-table th {
            font-size: 0.875rem;
            font-weight: 600;
        }
        .event-table td {
            font-size: 0.75rem;
        }
        .event-table .btn {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }
        .fc-header-toolbar {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }
        .fc-toolbar-title {
            font-size: 1.25rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        .fc-toolbar-chunk {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
        }
        .nav-bar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .nav-link {
            @apply text-blue-600 hover:text-blue-800 font-semibold px-3 py-2 rounded transition-colors;
        }
        .nav-link.active {
            @apply text-blue-800 bg-blue-100;
        }
        .weather-styles {
            font-family: 'Poppins', sans-serif;
        }
        .weather-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
            width: 100%;
            max-width: none;
            animation: fadeInUp 1s ease-out;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .weather-icon { font-size: 5rem; color: #74b9ff; margin-bottom: 1rem; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
        .weather-temp { font-size: 4rem; font-weight: 700; color: #2d3436; margin-bottom: 0.5rem; }
        .weather-desc { font-size: 1.5rem; color: #636e72; margin-bottom: 1.5rem; text-transform: capitalize; }
        .weather-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 1rem; margin-top: 1.5rem; }
        .weather-detail-item { background: linear-gradient(135deg, #fdcb6e 0%, #e17055 100%); color: white; padding: 1rem; border-radius: 10px; font-weight: 600; }
        .weather-detail-label { font-size: 0.8rem; opacity: 0.9; display: block; }
        .weather-detail-value { font-size: 1.2rem; }
        .feels-like { background: linear-gradient(135deg, #a29bfe 0%, #6c5ce7 100%); }
        .humidity { background: linear-gradient(135deg, #00b894 0%, #00a085 100%); }
        .wind { background: linear-gradient(135deg, #fd79a8 0%, #e84393 100%); }
        .precip { background: linear-gradient(135deg, #55a3ff 0%, #007acc 100%); }
        .update-time { font-size: 0.8rem; color: #b2bec3; margin-top: 1rem; }
        .forecast { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-radius: 20px; padding: 2rem; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1); text-align: center; width: 100%; max-width: none; margin-top: 2rem; }
        .forecast h2 { font-size: 1.5rem; margin-bottom: 1rem; color: #2d3436; }
        .forecast-grid { display: flex; overflow-x: auto; gap: 1rem; justify-content: flex-start; }
        .forecast-day { background: linear-gradient(135deg, #dfe6e9 0%, #b2bec3 100%); border-radius: 10px; padding: 1rem; min-width: 120px; text-align: center; }
        .forecast-day .day { font-weight: 600; margin-bottom: 0.5rem; }
        .forecast-day i { font-size: 2rem; color: #74b9ff; margin-bottom: 0.5rem; }
        .forecast-day .temps { font-size: 1.2rem; font-weight: 600; margin-bottom: 0.5rem; }
        .forecast-day .desc { font-size: 0.9rem; text-transform: capitalize; margin-bottom: 0.5rem; }
        .forecast-day .precip { font-size: 0.8rem; color: #636e72; }
        @media (max-width: 640px) {
            .card { padding: 1rem; }
            h1 { font-size: 1.5rem; }
            h2 { font-size: 1.25rem; }
            .btn { width: 100%; padding: 0.75rem; }
            .event-table { display: table; width: 100%; overflow-x: auto; }
            .event-table th, .event-table td { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
            .event-table th { font-size: 0.75rem; }
            .event-table .btn { padding: 0.25rem 0.5rem; font-size: 0.675rem; }
            .fc-toolbar-title { font-size: 1rem; }
            .fc-toolbar-chunk .fc-button { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
            .weather-temp { font-size: 3rem; }
            .weather-icon { font-size: 4rem; }
            .forecast { padding: 1.5rem; }
            .forecast h2 { font-size: 1.2rem; }
        }
    </style>
</head>
<body>
    <nav class="nav-bar flex justify-center space-x-6">
        <?php if ($is_logged_in): ?>
            <a href="dashboard.php" class="nav-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
            <a href="calendar.php" class="nav-link <?php echo $page === 'calendar' ? 'active' : ''; ?>">Calendar</a>
            <a href="weather.php" class="nav-link <?php echo $page === 'weather' ? 'active' : ''; ?>">Weather</a>
            <a href="settings.php" class="nav-link <?php echo $page === 'settings' ? 'active' : ''; ?>">Settings</a>
            <a href="?logout=1" class="nav-link">Logout</a>
        <?php endif; ?>
    </nav>
    <div class="card">
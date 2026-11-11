<?php
declare(strict_types=1);

/**
 * ทุกหน้าต้อง require ไฟล์นี้เป็นอย่างแรก
 * โหลด config, autoload, ตั้งค่า error/session
 */

define('APP_ROOT', dirname(__DIR__));

if (!is_file(APP_ROOT . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('ยังไม่ได้ติดตั้ง dependency — รัน "composer install" ในโฟลเดอร์โปรเจกต์ก่อน');
}
require APP_ROOT . '/vendor/autoload.php';

if (!is_file(APP_ROOT . '/.env')) {
    http_response_code(500);
    exit('ไม่พบไฟล์ .env — คัดลอก .env.example เป็น .env แล้วกรอกค่า');
}
try {
    $GLOBALS['APP_CONFIG'] = require APP_ROOT . '/config.php';
} catch (RuntimeException $ex) {
    http_response_code(500);
    exit(htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8'));
}

date_default_timezone_set((string) config('app.timezone', 'Asia/Bangkok'));
mb_internal_encoding('UTF-8');

// ---------------------------------------------------------------- errors
$isDev = config('app.env') === 'development';
error_reporting(E_ALL);
ini_set('display_errors', $isDev ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');

if (!$isDev) {
    set_exception_handler(static function (Throwable $ex): void {
        error_log((string) $ex);
        if (!headers_sent()) {
            abort(500, 'ระบบขัดข้อง กรุณาลองใหม่อีกครั้ง หากยังไม่ได้ กรุณาติดต่อทีม IT');
        }
    });
}

// ---------------------------------------------------------------- headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// ---------------------------------------------------------------- session
if (PHP_SAPI !== 'cli') {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) Auth::LIFETIME);
    session_name('JFSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => rtrim((string) config('app.base_url', ''), '/') . '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

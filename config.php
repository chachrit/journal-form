<?php
/**
 * Journal Form Configuration
 * ค่าจริงมาจาก .env (root โปรเจกต์) — ไฟล์นี้แค่อ่านค่าและกำหนด default
 * เครื่อง dev/prod ใช้ config.php ไฟล์เดียวกันได้ ต่างกันแค่ค่าใน .env (ดูตัวอย่างใน .env.example)
 */

loadEnvFile(__DIR__ . '/.env');

// development | production — production บังคับต้องมีค่าลับครบใน .env
$appEnv = (string) env('APP_ENV', 'production');

return [
    'app' => [
        'name' => env('APP_NAME', 'Journal Form'),
        'env' => $appEnv,
        // path ของโปรเจกต์บนเว็บ เช่น http://server/journal-form → /journal-form
        'base_url' => env('APP_BASE_URL', '/journal-form'),
        // URL เต็มของเว็บ ใช้สร้างลิงก์ในอีเมล
        'public_url' => env('APP_PUBLIC_URL', 'http://localhost'),
        'timezone' => 'Asia/Bangkok',
        // employee_id ที่เป็นผู้ดูแลระบบ คั่นด้วย comma (เพิ่มได้อีกทางในตาราง app_admins)
        'admins' => env_int_list('APP_ADMINS'),
    ],

    // =====================================================
    // Database (SQL Server)
    // =====================================================
    'db' => [
        'server' => env('DB_SERVER', 'localhost'),       // เช่น localhost, localhost\SQLEXPRESS, 10.0.0.5,1433
        'database' => env('DB_DATABASE', 'JournalForm'),
        'user' => env('DB_USERNAME', ''),                 // ว่างไว้ = Windows Authentication
        'pass' => env('DB_PASSWORD', ''),
        'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', true),
    ],

    // =====================================================
    // Employee API (login)
    // =====================================================
    'employee_api' => [
        // ที่อยู่ API อยู่ใน .env เท่านั้น (ไม่เก็บใน repo)
        'base_url' => envRequiredInProduction('EMP_API_URL', $appEnv, ''),
        'api_key' => envRequiredInProduction('EMP_API_KEY', $appEnv, ''),
        'timeout' => (int) env('EMP_API_TIMEOUT', 10),
        // login ได้ทุกรหัสพนักงานด้วยรหัสผ่าน "dev" — ใช้ได้เฉพาะ APP_ENV=development
        'fake_login' => $appEnv === 'development' && env('FAKE_LOGIN', false) === true,
    ],

    // =====================================================
    // Email (Office365 SMTP)
    // =====================================================
    'mail' => [
        // smtp = ส่งจริง, file = เขียนเมลลง storage/logs/mail/ (default ตอน development)
        'transport' => env('MAIL_TRANSPORT', $appEnv === 'production' ? 'smtp' : 'file'),
        'host' => env('EMAIL_HOST', 'smtp.office365.com'),
        'port' => (int) env('EMAIL_PORT', 587),
        'auth' => env('EMAIL_AUTH', true),
        'username' => envRequiredInProduction('EMAIL_USER', $appEnv, ''),
        'password' => envRequiredInProduction('EMAIL_PASS', $appEnv, ''),
        'secure' => env('EMAIL_SECURE', 'tls'),            // tls (STARTTLS, port 587) | ssl (465) | ว่าง
        'auto_tls' => true,
        'allow_self_signed' => env('EMAIL_ALLOW_SELF_SIGNED', false),
        // Office365 ต้องส่งในนามบัญชีที่ login (หรือบัญชีที่ได้สิทธิ์ Send As)
        'from' => env('EMAIL_FROM', env('EMAIL_USER', '')),
        'from_name' => env('EMAIL_FROM_NAME', 'Journal Form'),
    ],

    'upload' => [
        // ขนาดไฟล์แนบสูงสุดต่อไฟล์ (MB) ถ้า schema ไม่ระบุ — ต้องไม่เกิน upload_max_filesize ใน php.ini
        'max_size_mb' => (float) env('UPLOAD_MAX_MB', 10),
    ],
];

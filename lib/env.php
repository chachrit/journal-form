<?php
declare(strict_types=1);

/**
 * อ่านค่าจากไฟล์ .env (รูปแบบเดียวกับโปรเจกต์เก่า)
 * โหลดอัตโนมัติผ่าน composer "files" — ไฟล์นี้แค่ประกาศฟังก์ชัน
 *
 * รูปแบบไฟล์ .env:
 *   # คอมเมนต์
 *   KEY=value
 *   KEY="value ที่มีช่องว่าง"
 */

/** โหลด .env เข้า $_ENV (ไม่ทับค่าที่ตั้งไว้แล้วใน environment จริง) */
function loadEnvFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (str_starts_with($key, 'export ')) {
            $key = trim(substr($key, 7));
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }
        $quoted = strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0];
        if ($quoted) {
            $value = substr($value, 1, -1);
        } elseif (($hash = strpos($value, ' #')) !== false) {
            $value = rtrim(substr($value, 0, $hash));   // คอมเมนต์ท้ายบรรทัด
        }
        if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
    }
}

/** อ่านค่า env — ไม่มีหรือว่าง = $default */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return match (strtolower((string) $value)) {
        'true' => true,
        'false' => false,
        'null' => null,
        default => $value,
    };
}

/** ค่าที่ production ต้องมีใน .env — dev ใช้ $devDefault ได้ */
function envRequiredInProduction(string $key, string $appEnv, mixed $devDefault = null): mixed
{
    $value = env($key);
    if ($value === null) {
        if ($appEnv === 'production') {
            throw new RuntimeException("ต้องตั้งค่า $key ในไฟล์ .env (APP_ENV=production)");
        }
        return $devDefault;
    }
    return $value;
}

/** แปลง "1,2, 3" เป็น [1, 2, 3] */
function env_int_list(string $key): array
{
    $raw = (string) env($key, '');
    return array_values(array_map('intval', array_filter(array_map('trim', explode(',', $raw)), 'ctype_digit')));
}

<?php
declare(strict_types=1);

/**
 * ฟังก์ชันช่วยที่ใช้ทั่วทั้งระบบ (โหลดอัตโนมัติผ่าน composer "files")
 * ไฟล์นี้ต้องไม่มี side effect — แค่ประกาศฟังก์ชัน
 */

/** Escape ค่าก่อนแสดงใน HTML */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** อ่านค่า config ด้วย dot notation เช่น config('db.dsn') */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['APP_CONFIG'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** สร้าง URL ภายในระบบ เช่น url('admin/forms.php') → /journal-form/admin/forms.php */
function url(string $path = '', array $query = []): string
{
    $base = rtrim((string) config('app.base_url', ''), '/');
    $result = $base . '/' . ltrim($path, '/');
    if ($query) {
        $result .= '?' . http_build_query($query);
    }
    return $result;
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . url($path, $query));
    exit;
}

/** ข้อความแจ้งเตือนครั้งเดียว (แสดงหลัง redirect) */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

/** หยุดทำงานพร้อมแสดงหน้า error */
function abort(int $status, string $message = ''): never
{
    // Apache ไม่รู้จัก 419 แล้วจะแปลงเป็น 500 — ส่ง 400 แต่ยังแสดงข้อความ "หน้าหมดอายุ"
    http_response_code($status === 419 ? 400 : $status);
    $titles = [400 => 'คำขอไม่ถูกต้อง', 403 => 'ไม่มีสิทธิ์เข้าถึง', 404 => 'ไม่พบหน้าที่ต้องการ', 419 => 'หน้าหมดอายุ', 500 => 'ระบบขัดข้อง'];
    $title = $titles[$status] ?? 'เกิดข้อผิดพลาด';
    $message = $message !== '' ? $message : $title;
    if (defined('APP_ROOT') && is_file(APP_ROOT . '/templates/error.php')) {
        require APP_ROOT . '/templates/error.php';
    } else {
        echo e($message);
    }
    exit;
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** อ่าน query string แบบ string (ตัดช่องว่าง) */
function query_str(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function query_int(string $key): ?int
{
    $v = $_GET[$key] ?? null;
    return (is_string($v) && ctype_digit($v)) ? (int) $v : null;
}

function post_str(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** แสดงวันเวลาแบบไทยอ่านง่าย เช่น 11/11/2026 14:05 */
function format_datetime(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    try {
        $dt = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string) $value);
        return $dt->format('d/m/Y H:i');
    } catch (Exception) {
        return (string) $value;
    }
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
}

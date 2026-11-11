<?php
declare(strict_types=1);

/** ป้องกัน CSRF: ทุกฟอร์ม POST ต้องมี Csrf::field() และหน้าที่รับต้องเรียก Csrf::check() */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function check(): void
    {
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $sent)) {
            abort(419, 'หน้านี้หมดอายุ กรุณากลับไปโหลดหน้าใหม่แล้วลองอีกครั้ง');
        }
    }
}

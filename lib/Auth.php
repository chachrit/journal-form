<?php
declare(strict_types=1);

/**
 * ผู้ใช้ที่ login อยู่ (เก็บใน session) และการตรวจสิทธิ์
 *
 * session['user'] = [employee_id, name, email, department, division, token, expires_at, is_admin]
 */
final class Auth
{
    /** อายุ session เท่ากับอายุ JWT ของ Employee API */
    public const LIFETIME = 8 * 3600;

    private const MAX_ATTEMPTS = 5;        // ต่อ employee_id
    private const MAX_ATTEMPTS_IP = 20;    // ต่อ IP (กันการไล่เดารหัสหลายบัญชี)
    private const LOCK_SECONDS = 900;

    public static function user(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user)) {
            return null;
        }
        if (($user['expires_at'] ?? 0) < time()) {
            self::logout();
            return null;
        }
        // เช็กสิทธิ์ admin ใหม่ทุก 5 นาที — ถอดสิทธิ์แล้วมีผลโดยไม่ต้องรอ session หมดอายุ
        if (($user['admin_checked_at'] ?? 0) < time() - 300) {
            $user['is_admin'] = self::lookupAdmin((int) $user['employee_id']);
            $user['admin_checked_at'] = time();
            $_SESSION['user'] = $user;
        }
        return $user;
    }

    public static function id(): ?int
    {
        return self::user()['employee_id'] ?? null;
    }

    /** ข้อมูลผู้ใช้สำหรับแทนค่า {user.name} ฯลฯ ใน schema */
    public static function context(): array
    {
        $u = self::user() ?? [];
        $now = new DateTimeImmutable();
        return [
            'user.name' => $u['name'] ?? '',
            'user.email' => $u['email'] ?? '',
            'user.employee_id' => (string) ($u['employee_id'] ?? ''),
            'user.department' => $u['department'] ?? '',
            'user.division' => $u['division'] ?? '',
            'today' => $now->format('Y-m-d'),
            'now' => $now->format('H:i'),
        ];
    }

    /**
     * Login ผ่าน Employee API
     * @throws EmployeeApiException
     */
    public static function attempt(int $employeeId, string $password): array
    {
        $ipKey = 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        if (self::isLocked((string) $employeeId, self::MAX_ATTEMPTS) || self::isLocked($ipKey, self::MAX_ATTEMPTS_IP)) {
            throw new EmployeeApiException('ลองเข้าสู่ระบบผิดหลายครั้ง กรุณารอ 15 นาทีแล้วลองใหม่');
        }

        $api = EmployeeApi::fromConfig();
        try {
            $result = $api->login($employeeId, $password);
        } catch (EmployeeApiException $ex) {
            self::recordFailure((string) $employeeId);
            self::recordFailure($ipKey);
            throw $ex;
        }
        self::clearFailures((string) $employeeId);

        $emp = $api->find($result['token'], $result['employee_id']) ?? [];

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'employee_id' => $result['employee_id'],
            'name' => $result['name'] !== '' ? $result['name'] : trim(($emp['first_name_th'] ?? '') . ' ' . ($emp['last_name_th'] ?? '')),
            'email' => $result['email'] !== '' ? $result['email'] : (string) ($emp['email'] ?? ''),
            'department' => (string) ($emp['department_name'] ?? ''),
            'division' => (string) ($emp['division_name'] ?? ''),
            'token' => $result['token'],
            'expires_at' => time() + self::LIFETIME,
            'is_admin' => self::lookupAdmin($result['employee_id']),
            'admin_checked_at' => time(),
        ];
        return $_SESSION['user'];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            $next = $_SERVER['REQUEST_URI'] ?? '';
            redirect('login.php', $next !== '' ? ['next' => $next] : []);
        }
        return $user;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['is_admin'] ?? false) === true;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if (!self::isAdmin()) {
            abort(403, 'หน้านี้สำหรับผู้ดูแลระบบเท่านั้น');
        }
        return $user;
    }

    /** ดูคำตอบของฟอร์มนี้ได้ไหม (admin หรือได้รับสิทธิ์ใน form_permissions) */
    public static function canViewForm(int $formId): bool
    {
        if (self::isAdmin()) {
            return true;
        }
        $id = self::id();
        return $id !== null && FormRepository::hasPermission($formId, $id);
    }

    public static function requireFormViewer(int $formId): array
    {
        $user = self::requireLogin();
        if (!self::canViewForm($formId)) {
            abort(403, 'คุณไม่มีสิทธิ์ดูคำตอบของฟอร์มนี้');
        }
        return $user;
    }

    /** admin = อยู่ใน config app.admins หรือในตาราง app_admins */
    private static function lookupAdmin(int $employeeId): bool
    {
        if (in_array($employeeId, array_map('intval', (array) config('app.admins', [])), true)) {
            return true;
        }
        return FormRepository::isAdmin($employeeId);
    }

    // ------------------------------------------------- จำกัดการลอง login

    private static function attemptFile(string $key): string
    {
        return APP_ROOT . '/storage/cache/login_' . hash('sha256', $key) . '.json';
    }

    private static function isLocked(string $key, int $max): bool
    {
        $file = self::attemptFile($key);
        if (!is_file($file)) {
            return false;
        }
        $data = json_decode((string) file_get_contents($file), true) ?: [];
        $recent = array_filter($data, fn ($t) => $t > time() - self::LOCK_SECONDS);
        return count($recent) >= $max;
    }

    private static function recordFailure(string $key): void
    {
        $file = self::attemptFile($key);
        $data = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $data = array_values(array_filter($data, fn ($t) => $t > time() - self::LOCK_SECONDS));
        $data[] = time();
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }

    private static function clearFailures(string $key): void
    {
        @unlink(self::attemptFile($key));
    }
}

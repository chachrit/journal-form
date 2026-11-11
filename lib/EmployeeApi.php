<?php
declare(strict_types=1);

final class EmployeeApiException extends RuntimeException
{
}

/**
 * เรียก Employee API (ดู EMPLOYEE-API-GUIDE.md)
 * - login(): POST api/auth.php
 * - employees(): GET api/employee.php (cache ลงไฟล์)
 * API key และ token ใช้ฝั่ง server เท่านั้น ห้ามส่งไปถึงเบราว์เซอร์
 */
final class EmployeeApi
{
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $cacheDir,
        private readonly int $timeout = 10,
        private readonly bool $fakeLogin = false,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('employee_api.base_url'),
            (string) config('employee_api.api_key'),
            APP_ROOT . '/storage/cache',
            (int) config('employee_api.timeout', 10),
            config('app.env') === 'development' && config('employee_api.fake_login') === true && self::isLocalRequest(),
        );
    }

    /**
     * fake login ใช้ได้เฉพาะ request จากเครื่องตัวเอง — กันกรณีตั้ง .env ผิดบนเครื่องที่คนอื่นเข้าถึงได้
     * ถ้ามี reverse proxy อยู่หน้า Apache ทุก request จะมาจาก 127.0.0.1 จึงต้องเช็กว่าไม่ได้ผ่าน proxy
     * และ Host ที่เรียกต้องเป็น localhost ด้วย
     */
    public static function isLocalRequest(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }
        if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            return false;
        }
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_X_FORWARDED_HOST'] as $h) {
            if (!empty($_SERVER[$h])) {
                return false;
            }
        }
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * @return array{employee_id: int, name: string, email: string, token: string}
     * @throws EmployeeApiException ข้อความภาษาไทยที่แสดงให้ผู้ใช้ได้
     */
    public function login(int $employeeId, string $password): array
    {
        if ($this->fakeLogin) {
            if ($password !== 'dev') {
                throw new EmployeeApiException('[โหมดทดสอบ] ใช้รหัสผ่าน "dev"');
            }
            return ['employee_id' => $employeeId, 'name' => 'ผู้ทดสอบ ' . $employeeId, 'email' => "dev$employeeId@example.com", 'token' => 'fake'];
        }

        [$status, $body] = $this->request('POST', 'api/auth.php', ['employee_id' => $employeeId, 'pws_user' => $password]);

        if ($status === 200 && ($body['success'] ?? false) === true && isset($body['token'])) {
            return [
                'employee_id' => (int) $body['employee_id'],
                'name' => (string) ($body['name'] ?? ''),
                'email' => (string) ($body['email'] ?? ''),
                'token' => (string) $body['token'],
            ];
        }

        $error = (string) ($body['error'] ?? '');
        throw new EmployeeApiException(match (true) {
            $error === 'Invalid employee_id or password' => 'รหัสพนักงานหรือรหัสผ่านไม่ถูกต้อง',
            $error === 'Employee account is inactive' => 'บัญชีนี้ถูกปิดใช้งาน กรุณาติดต่อฝ่ายบุคคล',
            $error === 'employee_id must be numeric' => 'รหัสพนักงานต้องเป็นตัวเลข',
            default => $this->logAndGeneric("login failed: HTTP $status " . json_encode($body, JSON_UNESCAPED_UNICODE)),
        });
    }

    /**
     * รายชื่อพนักงาน active ทั้งหมด (cache 1 ชม.)
     * @return array<int, array> employee_id => ข้อมูลพนักงาน
     */
    public function employees(string $token): array
    {
        $file = $this->cacheDir . '/employees.json';
        if (is_file($file) && filemtime($file) > time() - self::CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        if ($this->fakeLogin) {
            return [];
        }

        [$status, $body] = $this->request('GET', 'api/employee.php', null, $token);
        if ($status !== 200 || !isset($body['data']) || !is_array($body['data'])) {
            $this->logAndGeneric("employees failed: HTTP $status " . json_encode($body, JSON_UNESCAPED_UNICODE));
            // ถ้าเรียกไม่ได้ ใช้ cache เก่าไปก่อน (ถ้ามี)
            $stale = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            return is_array($stale) ? $stale : [];
        }

        $byId = [];
        foreach ($body['data'] as $emp) {
            if (isset($emp['employee_id'])) {
                $byId[(int) $emp['employee_id']] = $emp;
            }
        }
        @file_put_contents($file, json_encode($byId, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $byId;
    }

    public function find(string $token, int $employeeId): ?array
    {
        return $this->employees($token)[$employeeId] ?? null;
    }

    /** @return array{0: int, 1: array} [HTTP status, JSON body] */
    private function request(string $method, string $path, ?array $json, ?string $token = null): array
    {
        $headers = ['X-API-KEY: ' . $this->apiKey, 'Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init(rtrim($this->baseUrl, '/') . '/' . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new EmployeeApiException($this->logAndGeneric("curl error: $curlError"));
        }
        $body = json_decode((string) $raw, true);
        return [$status, is_array($body) ? $body : []];
    }

    private function logAndGeneric(string $detail): string
    {
        error_log('[EmployeeApi] ' . $detail);
        return 'ระบบยืนยันตัวตนขัดข้อง กรุณาลองใหม่อีกครั้ง หรือติดต่อทีม IT';
    }
}

<?php
declare(strict_types=1);

/**
 * ตรวจข้อมูลที่ผู้ใช้ส่งมาตาม schema ฝั่ง server
 * - ช่องที่ถูกซ่อนด้วย show_if: ไม่ตรวจและไม่เก็บค่า
 * - ช่อง readonly ที่มี default: ใช้ค่า default เสมอ (ไม่เชื่อค่าที่ส่งมา)
 */
final class SubmissionValidator
{
    public const SIGNATURE_MAX_BYTES = 512 * 1024;

    /** นามสกุลไฟล์ => MIME ที่ยอมรับ (ตรวจด้วย finfo) */
    public const MIME_MAP = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    public function __construct(
        private readonly array $schema,
        private readonly array $context = [],
        private readonly float $defaultMaxMb = 10,
    ) {
    }

    /**
     * @param array $post  ปกติคือ $_POST
     * @param array $files ปกติคือ $_FILES
     * @return array{
     *   values: array<string, mixed>,          ค่าที่ผ่านการตรวจ (ไม่รวม file/signature)
     *   errors: array<string, string>,         name => ข้อความ error
     *   uploads: array<string, list<array>>,   name => ไฟล์ที่อัปโหลด (tmp_name, name, size, mime, ext)
     *   signatures: array<string, string>,     name => PNG binary
     *   old: array<string, mixed>              ค่าที่ส่งมา (ใช้เติมกลับเมื่อมี error)
     * }
     */
    public function validate(array $post, array $files): array
    {
        $result = ['values' => [], 'errors' => [], 'uploads' => [], 'signatures' => [], 'old' => []];
        $effective = [];   // ค่าที่ใช้ประเมิน show_if (ช่องที่ซ่อน = null)

        foreach ($this->schema['fields'] ?? [] as $field) {
            if (!FormSchema::isInput($field)) {
                continue;
            }
            $name = $field['name'];
            $type = $field['type'];

            $raw = $this->rawValue($field, $post);
            $result['old'][$name] = $raw;

            if (!ConditionEvaluator::evaluate($field['show_if'] ?? null, $effective)) {
                $effective[$name] = null;
                continue;
            }

            $required = ($field['required'] ?? false) === true;

            if ($type === 'file') {
                $this->checkFiles($field, $files[$name] ?? null, $required, $result);
                $effective[$name] = isset($result['uploads'][$name]) ? 'file' : null;
                continue;
            }
            if ($type === 'signature') {
                $this->checkSignature($field, $raw, $required, $result);
                $effective[$name] = isset($result['signatures'][$name]) ? 'signed' : null;
                continue;
            }

            [$value, $error] = $this->checkValue($field, $raw, $required);
            if ($error !== null) {
                $result['errors'][$name] = $error;
            }
            $result['values'][$name] = $value;
            $effective[$name] = $value;
        }
        return $result;
    }

    private function rawValue(array $field, array $post): mixed
    {
        $name = $field['name'];
        if (($field['readonly'] ?? false) === true && array_key_exists('default', $field)) {
            return FormSchema::resolveDefault($field['default'], $this->context);
        }
        $v = $post[$name] ?? null;
        if ($field['type'] === 'checkbox') {
            return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
        }
        return is_string($v) ? $v : '';
    }

    /** @return array{0: mixed, 1: ?string} [ค่าที่ทำความสะอาดแล้ว, error] */
    private function checkValue(array $field, mixed $raw, bool $required): array
    {
        $type = $field['type'];

        if ($type === 'checkbox') {
            $allowed = array_column(FormSchema::options($field), 'value');
            $value = array_values(array_unique(array_intersect($raw, $allowed)));
            if (count($value) !== count(array_unique($raw))) {
                return [$value, 'มีตัวเลือกที่ไม่ถูกต้อง'];
            }
            if ($required && !$value) {
                return [$value, 'กรุณาเลือกอย่างน้อย 1 ข้อ'];
            }
            return [$value, null];
        }

        // ตัดช่องว่างหัวท้าย และ normalize ขึ้นบรรทัดใหม่
        $value = trim(str_replace("\r\n", "\n", (string) $raw));
        if ($type !== 'textarea') {
            $value = str_replace("\n", ' ', $value);
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            return ['', 'ข้อความมีอักขระไม่ถูกต้อง'];
        }

        if ($value === '') {
            return [$value, $required ? 'กรุณากรอกข้อมูลช่องนี้' : null];
        }

        $maxlength = $field['maxlength'] ?? ($type === 'textarea' ? 10000 : 1000);
        if (mb_strlen($value) > $maxlength) {
            return [$value, "ยาวเกิน $maxlength ตัวอักษร"];
        }

        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return [$value, 'รูปแบบอีเมลไม่ถูกต้อง'];
                }
                break;

            case 'number':
                if (!is_numeric($value)) {
                    return [$value, 'กรุณากรอกเป็นตัวเลข'];
                }
                if (isset($field['min']) && (float) $value < $field['min']) {
                    return [$value, 'ต้องไม่น้อยกว่า ' . $field['min']];
                }
                if (isset($field['max']) && (float) $value > $field['max']) {
                    return [$value, 'ต้องไม่มากกว่า ' . $field['max']];
                }
                break;

            case 'date':
                if (!FormSchema::isDate($value)) {
                    return [$value, 'รูปแบบวันที่ไม่ถูกต้อง'];
                }
                if (isset($field['min']) && $value < $field['min']) {
                    return [$value, 'ต้องไม่ก่อนวันที่ ' . $field['min']];
                }
                if (isset($field['max']) && $value > $field['max']) {
                    return [$value, 'ต้องไม่หลังวันที่ ' . $field['max']];
                }
                break;

            case 'datetime':
                if (!FormSchema::isDateTime($value)) {
                    return [$value, 'รูปแบบวันเวลาไม่ถูกต้อง'];
                }
                break;

            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                    return [$value, 'รูปแบบเวลาไม่ถูกต้อง'];
                }
                break;

            case 'select':
            case 'radio':
                if (!in_array($value, array_column(FormSchema::options($field), 'value'), true)) {
                    return [$value, 'ตัวเลือกไม่ถูกต้อง'];
                }
                break;
        }

        if (isset($field['pattern']) && in_array($type, ['text', 'tel', 'email'], true)
            && !preg_match(FormSchema::patternRegex($field['pattern']), $value)) {
            return [$value, $field['pattern_message'] ?? 'รูปแบบข้อมูลไม่ถูกต้อง'];
        }
        return [$value, null];
    }

    private function checkFiles(array $field, mixed $input, bool $required, array &$result): void
    {
        $name = $field['name'];
        $list = self::normalizeFiles($input);
        $list = array_values(array_filter($list, fn ($f) => $f['error'] !== UPLOAD_ERR_NO_FILE));

        if (!$list) {
            if ($required) {
                $result['errors'][$name] = 'กรุณาแนบไฟล์';
            }
            return;
        }
        if (($field['multiple'] ?? false) !== true && count($list) > 1) {
            $result['errors'][$name] = 'แนบได้ 1 ไฟล์เท่านั้น';
            return;
        }

        $maxBytes = (int) round(((float) ($field['max_size_mb'] ?? $this->defaultMaxMb)) * 1048576);
        $allowedExt = self::allowedExtensions($field['accept'] ?? null);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $ok = [];

        foreach ($list as $f) {
            $label = '"' . $f['name'] . '"';
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
                $result['errors'][$name] = "ไฟล์ $label ใหญ่เกินที่เซิร์ฟเวอร์รองรับ";
                return;
            }
            $isUploaded = defined('JF_TESTING') ? is_file($f['tmp_name']) : is_uploaded_file($f['tmp_name']);
            if ($f['error'] !== UPLOAD_ERR_OK || !$isUploaded) {
                $result['errors'][$name] = "อัปโหลดไฟล์ $label ไม่สำเร็จ กรุณาลองใหม่";
                return;
            }
            if ($f['size'] > $maxBytes) {
                $result['errors'][$name] = "ไฟล์ $label ใหญ่เกิน " . ($maxBytes / 1048576) . ' MB';
                return;
            }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                $result['errors'][$name] = "ไม่รองรับไฟล์ชนิด .$ext (รองรับ: " . implode(', ', $allowedExt) . ')';
                return;
            }
            $mime = (string) $finfo->file($f['tmp_name']);
            if (!in_array($mime, self::MIME_MAP[$ext], true)) {
                $result['errors'][$name] = "ไฟล์ $label ไม่ตรงกับนามสกุล .$ext";
                return;
            }
            $ok[] = ['tmp_name' => $f['tmp_name'], 'name' => self::cleanFileName($f['name']), 'size' => (int) $f['size'], 'mime' => $mime, 'ext' => $ext];
        }
        $result['uploads'][$name] = $ok;
    }

    private function checkSignature(array $field, mixed $raw, bool $required, array &$result): void
    {
        $name = $field['name'];
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            if ($required) {
                $result['errors'][$name] = 'กรุณาเซ็นชื่อ';
            }
            return;
        }
        $prefix = 'data:image/png;base64,';
        if (!str_starts_with($raw, $prefix) || strlen($raw) > self::SIGNATURE_MAX_BYTES * 1.4) {
            $result['errors'][$name] = 'ข้อมูลลายเซ็นไม่ถูกต้อง กรุณาเซ็นใหม่';
            return;
        }
        $png = base64_decode(substr($raw, strlen($prefix)), true);
        $info = $png === false ? false : @getimagesizefromstring($png);
        if ($png === false || strlen($png) > self::SIGNATURE_MAX_BYTES || $info === false || $info[2] !== IMAGETYPE_PNG) {
            $result['errors'][$name] = 'ข้อมูลลายเซ็นไม่ถูกต้อง กรุณาเซ็นใหม่';
            return;
        }
        $result['signatures'][$name] = $png;
    }

    /** แปลง $_FILES[name] ทั้งแบบไฟล์เดียวและหลายไฟล์ ให้เป็น list */
    public static function normalizeFiles(mixed $input): array
    {
        if (!is_array($input) || !isset($input['error'])) {
            return [];
        }
        if (!is_array($input['error'])) {
            return [[
                'name' => (string) $input['name'], 'tmp_name' => (string) $input['tmp_name'],
                'size' => (int) $input['size'], 'error' => (int) $input['error'],
            ]];
        }
        $out = [];
        foreach (array_keys($input['error']) as $i) {
            if (!is_int($input['error'][$i]) && !is_string($input['error'][$i])) {
                continue;
            }
            $out[] = [
                'name' => (string) ($input['name'][$i] ?? ''), 'tmp_name' => (string) ($input['tmp_name'][$i] ?? ''),
                'size' => (int) ($input['size'][$i] ?? 0), 'error' => (int) $input['error'][$i],
            ];
        }
        return $out;
    }

    /** นามสกุลที่อนุญาต จาก accept เช่น ".pdf,.jpg" (ไม่ระบุ = ทุกชนิดที่ระบบรองรับ) */
    public static function allowedExtensions(?string $accept): array
    {
        if ($accept === null || trim($accept) === '') {
            return array_keys(self::MIME_MAP);
        }
        $out = [];
        foreach (explode(',', $accept) as $part) {
            $part = strtolower(trim($part));
            if ($part === 'image/*') {
                array_push($out, 'jpg', 'jpeg', 'png', 'gif', 'webp');
                continue;
            }
            $ext = ltrim($part, '.');
            if (isset(self::MIME_MAP[$ext])) {
                $out[] = $ext;
            }
        }
        return array_values(array_unique($out));
    }

    private static function cleanFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'file';
        return mb_substr($name, 0, 200) ?: 'file';
    }
}

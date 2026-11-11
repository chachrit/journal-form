<?php
declare(strict_types=1);

/**
 * ตรวจความถูกต้องของ JSON schema ของฟอร์ม และมีฟังก์ชันช่วยอ่าน schema
 * ดูรูปแบบ schema ใน docs/design.md ข้อ 6
 */
final class FormSchema
{
    public const INPUT_TYPES = [
        'text', 'textarea', 'number', 'email', 'tel', 'date', 'time', 'datetime',
        'select', 'radio', 'checkbox', 'file', 'signature',
    ];
    public const DISPLAY_TYPES = ['heading', 'paragraph'];
    public const OPTION_TYPES = ['select', 'radio', 'checkbox'];

    /** operator ที่ต้องมี value เดี่ยว / ต้องเป็น array / ไม่ต้องมี value */
    public const OPS_SCALAR = ['eq', 'neq', 'gt', 'lt', 'contains'];
    public const OPS_LIST = ['in', 'not_in'];
    public const OPS_NOVALUE = ['empty', 'not_empty'];

    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,49}$/';

    /** ตัวแทนค่าที่ใช้ใน default ได้ */
    public const PLACEHOLDERS = ['user.name', 'user.email', 'user.employee_id', 'user.department', 'user.division', 'today', 'now'];

    /**
     * ตรวจ JSON string
     * @return array{schema: ?array, errors: string[]}
     */
    public static function validateJson(string $json): array
    {
        try {
            $schema = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $ex) {
            return ['schema' => null, 'errors' => ['JSON ไม่ถูกรูปแบบ: ' . $ex->getMessage()]];
        }
        if (!is_array($schema) || array_is_list($schema)) {
            return ['schema' => null, 'errors' => ['JSON ต้องเป็น object { ... } ที่ระดับบนสุด']];
        }
        $errors = self::validate($schema);
        return ['schema' => $errors ? null : $schema, 'errors' => $errors];
    }

    /** @return string[] รายการ error (ว่าง = ผ่าน) */
    public static function validate(array $schema): array
    {
        $errors = [];

        if (!isset($schema['title']) || !is_string($schema['title']) || trim($schema['title']) === '') {
            $errors[] = '"title" ต้องเป็นข้อความและห้ามว่าง';
        }
        foreach (['description', 'success_message'] as $key) {
            if (isset($schema[$key]) && !is_string($schema[$key])) {
                $errors[] = "\"$key\" ต้องเป็นข้อความ";
            }
        }
        if (isset($schema['notify'])) {
            $errors = array_merge($errors, self::validateNotify($schema['notify']));
        }

        if (!isset($schema['fields']) || !is_array($schema['fields']) || !array_is_list($schema['fields']) || !$schema['fields']) {
            $errors[] = '"fields" ต้องเป็น array และต้องมีอย่างน้อย 1 ช่อง';
            return $errors;
        }

        $seen = [];   // name => type ของช่องที่ผ่านมาแล้ว (ใช้ตรวจ show_if)
        foreach ($schema['fields'] as $i => $field) {
            $where = 'ช่องที่ ' . ($i + 1);
            if (!is_array($field) || array_is_list($field)) {
                $errors[] = "$where: ต้องเป็น object";
                continue;
            }
            if (isset($field['name']) && is_string($field['name'])) {
                $where .= ' ("' . $field['name'] . '")';
            }
            $errors = array_merge($errors, self::validateField($field, $where, $seen));
            if (isset($field['name'], $field['type']) && is_string($field['name']) && in_array($field['type'], self::INPUT_TYPES, true)) {
                $seen[$field['name']] = $field['type'];
            }
        }
        return $errors;
    }

    private static function validateNotify(mixed $notify): array
    {
        if ($notify === []) {
            return [];
        }
        if (!is_array($notify) || array_is_list($notify)) {
            return ['"notify" ต้องเป็น object'];
        }
        $errors = [];
        foreach (['to', 'cc'] as $key) {
            if (!isset($notify[$key])) {
                continue;
            }
            if (!is_array($notify[$key]) || !array_is_list($notify[$key])) {
                $errors[] = "\"notify.$key\" ต้องเป็น array ของอีเมล";
                continue;
            }
            foreach ($notify[$key] as $addr) {
                if (!is_string($addr) || !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "\"notify.$key\" มีอีเมลไม่ถูกต้อง: " . json_encode($addr, JSON_UNESCAPED_UNICODE);
                }
            }
        }
        if (isset($notify['subject']) && !is_string($notify['subject'])) {
            $errors[] = '"notify.subject" ต้องเป็นข้อความ';
        }
        if (isset($notify['send_copy_to_submitter']) && !is_bool($notify['send_copy_to_submitter'])) {
            $errors[] = '"notify.send_copy_to_submitter" ต้องเป็น true หรือ false';
        }
        return $errors;
    }

    private static function validateField(array $f, string $where, array $seen): array
    {
        $errors = [];
        $type = $f['type'] ?? null;

        if (!is_string($type) || !in_array($type, [...self::INPUT_TYPES, ...self::DISPLAY_TYPES], true)) {
            $errors[] = "$where: \"type\" ไม่รู้จัก — ใช้ได้: " . implode(', ', [...self::INPUT_TYPES, ...self::DISPLAY_TYPES]);
            return $errors;
        }
        if (!isset($f['label']) || !is_string($f['label']) || trim($f['label']) === '') {
            $errors[] = "$where: ต้องมี \"label\"";
        }

        $isInput = in_array($type, self::INPUT_TYPES, true);
        if ($isInput) {
            $name = $f['name'] ?? null;
            if (!is_string($name) || !preg_match(self::NAME_PATTERN, $name)) {
                $errors[] = "$where: \"name\" ต้องขึ้นต้นด้วย a-z และมีได้แค่ a-z, 0-9, _ (ยาวไม่เกิน 50)";
            } elseif (isset($seen[$name])) {
                $errors[] = "$where: \"name\" ซ้ำกับช่องก่อนหน้า";
            }
        } elseif (isset($f['name']) && (!is_string($f['name']) || !preg_match(self::NAME_PATTERN, $f['name']))) {
            $errors[] = "$where: \"name\" ต้องขึ้นต้นด้วย a-z และมีได้แค่ a-z, 0-9, _";
        }

        foreach (['required', 'readonly', 'multiple', 'capture'] as $boolKey) {
            if (isset($f[$boolKey]) && !is_bool($f[$boolKey])) {
                $errors[] = "$where: \"$boolKey\" ต้องเป็น true หรือ false";
            }
        }
        foreach (['help', 'placeholder', 'accept', 'pattern', 'pattern_message'] as $strKey) {
            if (isset($f[$strKey]) && !is_string($f[$strKey])) {
                $errors[] = "$where: \"$strKey\" ต้องเป็นข้อความ";
            }
        }
        if (isset($f['default']) && !is_string($f['default']) && !is_int($f['default']) && !is_float($f['default'])
            && !($type === 'checkbox' && is_array($f['default']))) {
            $errors[] = "$where: \"default\" ต้องเป็นข้อความหรือตัวเลข";
        }

        if (in_array($type, self::OPTION_TYPES, true)) {
            $errors = array_merge($errors, self::validateOptions($f['options'] ?? null, $where));
        } elseif (isset($f['options'])) {
            $errors[] = "$where: \"options\" ใช้ได้กับ select, radio, checkbox เท่านั้น";
        }

        if ($type === 'number') {
            foreach (['min', 'max'] as $k) {
                if (isset($f[$k]) && !is_int($f[$k]) && !is_float($f[$k])) {
                    $errors[] = "$where: \"$k\" ต้องเป็นตัวเลข";
                }
            }
        }
        if ($type === 'date') {
            foreach (['min', 'max'] as $k) {
                if (isset($f[$k]) && (!is_string($f[$k]) || !self::isDate($f[$k]))) {
                    $errors[] = "$where: \"$k\" ต้องเป็นวันที่รูปแบบ YYYY-MM-DD";
                }
            }
        }
        if (isset($f['columns']) && (!is_int($f['columns']) || $f['columns'] < 1 || $f['columns'] > 4)) {
            $errors[] = "$where: \"columns\" ต้องเป็นจำนวนเต็ม 1–4";
        }
        if (isset($f['maxlength']) && (!is_int($f['maxlength']) || $f['maxlength'] < 1)) {
            $errors[] = "$where: \"maxlength\" ต้องเป็นจำนวนเต็มบวก";
        }
        if (isset($f['max_size_mb']) && ((!is_int($f['max_size_mb']) && !is_float($f['max_size_mb'])) || $f['max_size_mb'] <= 0)) {
            $errors[] = "$where: \"max_size_mb\" ต้องเป็นตัวเลขมากกว่า 0";
        }
        if (isset($f['pattern']) && is_string($f['pattern']) && @preg_match(self::patternRegex($f['pattern']), '') === false) {
            $errors[] = "$where: \"pattern\" เป็น regex ที่ไม่ถูกต้อง";
        }

        if (isset($f['show_if'])) {
            $errors = array_merge($errors, self::validateCondition($f['show_if'], $where, $seen));
        }
        return $errors;
    }

    private static function validateOptions(mixed $options, string $where): array
    {
        if (!is_array($options) || !array_is_list($options) || !$options) {
            return ["$where: ต้องมี \"options\" เป็น array อย่างน้อย 1 ตัวเลือก"];
        }
        $errors = [];
        $values = [];
        foreach ($options as $j => $opt) {
            if (is_string($opt) || is_int($opt)) {
                $values[] = (string) $opt;
            } elseif (is_array($opt) && isset($opt['value'], $opt['label']) && is_string($opt['label'])
                && (is_string($opt['value']) || is_int($opt['value']))) {
                $values[] = (string) $opt['value'];
            } else {
                $errors[] = "$where: ตัวเลือกที่ " . ($j + 1) . ' ต้องเป็นข้อความ หรือ {"value": ..., "label": ...}';
            }
        }
        if (count($values) !== count(array_unique($values))) {
            $errors[] = "$where: \"options\" มีค่าซ้ำกัน";
        }
        return $errors;
    }

    private static function validateCondition(mixed $cond, string $where, array $seen, int $depth = 0): array
    {
        if ($depth > 5) {
            return ["$where: \"show_if\" ซ้อนลึกเกินไป"];
        }
        if (!is_array($cond) || array_is_list($cond)) {
            return ["$where: \"show_if\" ต้องเป็น object"];
        }
        foreach (['all', 'any'] as $group) {
            if (isset($cond[$group])) {
                if (!is_array($cond[$group]) || !array_is_list($cond[$group]) || !$cond[$group]) {
                    return ["$where: \"show_if.$group\" ต้องเป็น array ของเงื่อนไข"];
                }
                $errors = [];
                foreach ($cond[$group] as $sub) {
                    $errors = array_merge($errors, self::validateCondition($sub, $where, $seen, $depth + 1));
                }
                return $errors;
            }
        }

        $errors = [];
        $field = $cond['field'] ?? null;
        $op = $cond['op'] ?? 'eq';
        if (!is_string($field) || !isset($seen[$field])) {
            $errors[] = "$where: \"show_if.field\" ต้องอ้างถึง name ของช่องที่อยู่ก่อนหน้า";
        }
        if (in_array($op, self::OPS_SCALAR, true)) {
            if (!array_key_exists('value', $cond) || (!is_string($cond['value']) && !is_int($cond['value']) && !is_float($cond['value']))) {
                $errors[] = "$where: \"show_if\" operator \"$op\" ต้องมี \"value\"";
            }
        } elseif (in_array($op, self::OPS_LIST, true)) {
            if (!isset($cond['value']) || !is_array($cond['value']) || !array_is_list($cond['value'])) {
                $errors[] = "$where: \"show_if\" operator \"$op\" ต้องมี \"value\" เป็น array";
            }
        } elseif (!in_array($op, self::OPS_NOVALUE, true)) {
            $errors[] = "$where: \"show_if.op\" ไม่รู้จัก — ใช้ได้: " . implode(', ', [...self::OPS_SCALAR, ...self::OPS_LIST, ...self::OPS_NOVALUE]);
        }
        return $errors;
    }

    // ---------------------------------------------------------------- helpers

    public static function isInput(array $field): bool
    {
        return in_array($field['type'] ?? '', self::INPUT_TYPES, true);
    }

    /** เฉพาะช่องที่กรอกได้ */
    public static function inputFields(array $schema): array
    {
        return array_values(array_filter($schema['fields'] ?? [], [self::class, 'isInput']));
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(array $field): array
    {
        $out = [];
        foreach ($field['options'] ?? [] as $opt) {
            if (is_array($opt)) {
                $out[] = ['value' => (string) $opt['value'], 'label' => (string) $opt['label']];
            } else {
                $out[] = ['value' => (string) $opt, 'label' => (string) $opt];
            }
        }
        return $out;
    }

    public static function optionLabel(array $field, string $value): string
    {
        foreach (self::options($field) as $opt) {
            if ($opt['value'] === $value) {
                return $opt['label'];
            }
        }
        return $value;
    }

    /** แทนค่า {user.name}, {today} ฯลฯ ใน default */
    public static function resolveDefault(mixed $default, array $context): mixed
    {
        if (!is_string($default)) {
            return $default;
        }
        return preg_replace_callback('/\{([a-z_.]+)\}/', static function (array $m) use ($context) {
            return array_key_exists($m[1], $context) ? (string) $context[$m[1]] : $m[0];
        }, $default);
    }

    /** regex ที่ใช้ตรวจ pattern ฝั่ง PHP (ต้องตรงทั้งค่า) */
    public static function patternRegex(string $pattern): string
    {
        return '~^(?:' . str_replace('~', '\~', $pattern) . ')$~u';
    }

    /** วันเวลาแบบ input datetime-local: YYYY-MM-DDTHH:MM */
    public static function isDateTime(string $value): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
        return $dt !== false && $dt->format('Y-m-d\TH:i') === $value;
    }

    public static function isDate(string $value): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }
}

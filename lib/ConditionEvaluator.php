<?php
declare(strict_types=1);

/**
 * ประเมินเงื่อนไข show_if ฝั่ง PHP
 * ต้องให้ผลตรงกับ assets/js/form.js (evaluate) ทุกกรณี
 */
final class ConditionEvaluator
{
    /**
     * @param array|null $cond   เงื่อนไข show_if (null = แสดงเสมอ)
     * @param array      $values ค่าปัจจุบันของแต่ละช่อง name => string|array|null
     */
    public static function evaluate(?array $cond, array $values): bool
    {
        if ($cond === null) {
            return true;
        }
        if (isset($cond['all'])) {
            foreach ($cond['all'] as $sub) {
                if (!self::evaluate($sub, $values)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($cond['any'])) {
            foreach ($cond['any'] as $sub) {
                if (self::evaluate($sub, $values)) {
                    return true;
                }
            }
            return false;
        }

        $actual = $values[$cond['field'] ?? ''] ?? null;
        $expected = $cond['value'] ?? null;
        $op = $cond['op'] ?? 'eq';

        return match ($op) {
            'eq' => self::equals($actual, $expected),
            'neq' => !self::equals($actual, $expected),
            'in' => self::inList($actual, (array) $expected),
            'not_in' => !self::inList($actual, (array) $expected),
            'empty' => self::isEmpty($actual),
            'not_empty' => !self::isEmpty($actual),
            'gt' => self::compare($actual, $expected) === 1,
            'lt' => self::compare($actual, $expected) === -1,
            'contains' => self::contains($actual, $expected),
            default => false,
        };
    }

    /**
     * คำนวณว่าช่องไหนแสดงอยู่ ไล่ตามลำดับช่อง
     * ช่องที่ถูกซ่อนถือว่าค่าว่าง (ช่องที่อ้างถึงช่องที่ซ่อนจึงได้ผลตามค่าว่าง)
     *
     * @return array<string, bool> name => แสดงหรือไม่ (เฉพาะช่องที่มี name)
     */
    public static function visibility(array $schema, array $values): array
    {
        $effective = [];
        $visible = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $show = self::evaluate($field['show_if'] ?? null, $effective);
            $name = $field['name'] ?? null;
            if (is_string($name)) {
                $visible[$name] = $show;
                $effective[$name] = $show ? ($values[$name] ?? null) : null;
            }
        }
        return $visible;
    }

    /** ช่องนี้แสดงหรือไม่ (ใช้ตอนวาดฟอร์ม/แสดงผล) */
    public static function isVisible(array $field, array $visibility, array $effectiveValues): bool
    {
        if (isset($field['name']) && array_key_exists($field['name'], $visibility)) {
            return $visibility[$field['name']];
        }
        return self::evaluate($field['show_if'] ?? null, $effectiveValues);
    }

    /** ค่าที่ใช้ประเมินเงื่อนไข: ช่องที่ซ่อนถือเป็น null */
    public static function effectiveValues(array $visibility, array $values): array
    {
        $out = [];
        foreach ($visibility as $name => $show) {
            $out[$name] = $show ? ($values[$name] ?? null) : null;
        }
        return $out;
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function equals(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array(self::str($expected), array_map([self::class, 'str'], $actual), true);
        }
        return self::str($actual) === self::str($expected);
    }

    private static function inList(mixed $actual, array $list): bool
    {
        $list = array_map([self::class, 'str'], $list);
        if (is_array($actual)) {
            return (bool) array_intersect(array_map([self::class, 'str'], $actual), $list);
        }
        return in_array(self::str($actual), $list, true);
    }

    private static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    /** -1, 0, 1 หรือ null ถ้าเทียบไม่ได้ */
    private static function compare(mixed $actual, mixed $expected): ?int
    {
        $a = self::str($actual);
        $b = self::str($expected);
        if ($a === '' || $b === '') {
            return null;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }
        return strcmp($a, $b) <=> 0;   // เช่น วันที่ YYYY-MM-DD เทียบแบบข้อความได้
    }

    private static function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return self::equals($actual, $expected);
        }
        $needle = self::str($expected);
        return $needle !== '' && str_contains(self::str($actual), $needle);
    }
}

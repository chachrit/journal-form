<?php
declare(strict_types=1);

/**
 * แปลง schema เป็น HTML
 * - renderFields(): ช่องกรอกในหน้าฟอร์ม (ไม่รวมแท็ก <form>)
 * - renderAnswers(): แสดงคำตอบที่บันทึกแล้ว (หน้าแอดมิน, คำตอบของฉัน, อีเมล)
 */
final class FormRenderer
{
    public function __construct(
        private readonly array $schema,
        private readonly array $context = [],
    ) {
    }

    /** ค่าเริ่มต้นของแต่ละช่อง (แทนค่า {user.name} ฯลฯ แล้ว) */
    public function defaults(): array
    {
        $values = [];
        foreach (FormSchema::inputFields($this->schema) as $field) {
            $default = $field['default'] ?? ($field['type'] === 'checkbox' ? [] : '');
            $values[$field['name']] = FormSchema::resolveDefault($default, $this->context);
        }
        return $values;
    }

    /**
     * @param array $values ค่าที่จะเติมในช่อง (ส่งมาจาก defaults() หรือค่าเดิมเมื่อมี error)
     * @param array $errors name => ข้อความ error
     */
    public function renderFields(array $values, array $errors = []): string
    {
        $visibility = ConditionEvaluator::visibility($this->schema, $values);
        $effective = ConditionEvaluator::effectiveValues($visibility, $values);

        $html = '';
        foreach ($this->schema['fields'] ?? [] as $i => $field) {
            $visible = ConditionEvaluator::isVisible($field, $visibility, $effective);
            $html .= $this->renderField($field, $i, $values, $errors, $visible);
        }
        return $html;
    }

    private function renderField(array $f, int $index, array $values, array $errors, bool $visible): string
    {
        $type = $f['type'];
        $name = $f['name'] ?? null;
        $id = 'f-' . ($name ?? 'item' . $index);

        $attrs = 'class="field field-' . e($type) . (isset($errors[$name ?? '']) ? ' has-error' : '') . '" id="wrap-' . e($id) . '"';
        if ($name !== null) {
            $attrs .= ' data-name="' . e($name) . '"';
        }
        if (isset($f['show_if'])) {
            $attrs .= " data-show-if='" . e(json_encode($f['show_if'], JSON_UNESCAPED_UNICODE)) . "'";
        }
        if (!$visible) {
            $attrs .= ' hidden';
        }

        if ($type === 'heading') {
            return "<div $attrs><h2 class=\"field-heading\">" . e($f['label']) . '</h2>'
                . (isset($f['help']) ? '<p class="help">' . e($f['help']) . '</p>' : '') . "</div>\n";
        }
        if ($type === 'paragraph') {
            return "<div $attrs><p class=\"field-paragraph\">" . nl2br(e($f['label'])) . "</p></div>\n";
        }

        $required = ($f['required'] ?? false) === true;
        $value = $values[$name] ?? ($type === 'checkbox' ? [] : '');
        $disabled = $visible ? '' : ' disabled';

        $label = e($f['label']) . ($required ? ' <span class="req" aria-hidden="true">*</span>' : '');
        $isGroup = in_array($type, ['radio', 'checkbox'], true);
        $html = "<div $attrs>";
        $html .= $isGroup
            ? "<fieldset><legend class=\"label\">$label</legend>"
            : '<label class="label" for="' . e($id) . "\">$label</label>";

        $html .= $this->renderInput($f, $id, $value, $required, $disabled);

        if (isset($f['help'])) {
            $html .= '<p class="help">' . e($f['help']) . '</p>';
        }
        if (isset($errors[$name])) {
            $html .= '<p class="error" role="alert">' . e($errors[$name]) . '</p>';
        }
        $html .= $isGroup ? '</fieldset>' : '';
        return $html . "</div>\n";
    }

    private function renderInput(array $f, string $id, mixed $value, bool $required, string $disabled): string
    {
        $type = $f['type'];
        $name = e($f['name']);
        $req = $required ? ' required' : '';
        $ro = ($f['readonly'] ?? false) === true ? ' readonly' : '';
        $ph = isset($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '';
        $common = 'id="' . e($id) . "\" name=\"$name\"$req$ro$disabled";

        switch ($type) {
            case 'textarea':
                $max = isset($f['maxlength']) ? ' maxlength="' . (int) $f['maxlength'] . '"' : '';
                return "<textarea $common rows=\"4\"$ph$max>" . e($value) . '</textarea>';

            case 'select':
                $html = "<select $common><option value=\"\">— เลือก —</option>";
                foreach (FormSchema::options($f) as $opt) {
                    $sel = (string) $value === $opt['value'] ? ' selected' : '';
                    $html .= '<option value="' . e($opt['value']) . "\"$sel>" . e($opt['label']) . '</option>';
                }
                return $html . '</select>';

            case 'radio':
            case 'checkbox':
                $cols = (int) ($f['columns'] ?? 1);
                $html = '<div class="choices' . ($cols > 1 ? ' cols-' . $cols : '') . '">';
                $checkedValues = $type === 'checkbox' ? array_map('strval', (array) $value) : [(string) $value];
                foreach (FormSchema::options($f) as $j => $opt) {
                    $optId = e($id . '-' . $j);
                    $checked = in_array($opt['value'], $checkedValues, true) ? ' checked' : '';
                    // checkbox: required ตรวจฝั่ง server (เบราว์เซอร์บังคับทุกกล่องถ้าใส่ required)
                    $r = $type === 'radio' ? $req : '';
                    $inputName = $type === 'checkbox' ? $name . '[]' : $name;
                    $html .= "<label class=\"choice\" for=\"$optId\"><input type=\"$type\" id=\"$optId\" name=\"$inputName\" value=\""
                        . e($opt['value']) . "\"$checked$r$ro$disabled> <span>" . e($opt['label']) . '</span></label>';
                }
                return $html . '</div>';

            case 'file':
                $multiple = ($f['multiple'] ?? false) === true;
                $accept = isset($f['accept']) ? ' accept="' . e($f['accept']) . '"' : '';
                $inputName = $multiple ? $name . '[]' : $name;
                $maxMb = $f['max_size_mb'] ?? config('upload.max_size_mb', 10);
                // capture: มือถือ/แท็บเล็ตเปิดกล้องถ่ายรูปทันที (คอมพิวเตอร์จะเปิดหน้าต่างเลือกไฟล์ตามปกติ)
                $capture = ($f['capture'] ?? false) === true ? ' capture="environment"' : '';
                return '<input type="file" id="' . e($id) . "\" name=\"$inputName\"$accept$req$disabled$capture"
                    . ($multiple ? ' multiple' : '') . ' data-max-mb="' . e($maxMb) . '">'
                    . '<p class="help">ขนาดไม่เกิน ' . e($maxMb) . ' MB' . ($multiple ? ' เลือกได้หลายไฟล์' : '') . '</p>';

            case 'signature':
                return '<div class="signature" data-signature>'
                    . '<canvas aria-label="ช่องเซ็นชื่อ"></canvas>'
                    . '<input type="hidden" id="' . e($id) . "\" name=\"$name\" value=\"" . e($value) . "\"$disabled>"
                    . '<button type="button" class="btn btn-small" data-signature-clear>ล้างลายเซ็น</button>'
                    . '</div>';

            default:
                $htmlType = $type === 'datetime' ? 'datetime-local'
                    : (in_array($type, ['text', 'number', 'email', 'tel', 'date', 'time'], true) ? $type : 'text');
                $extra = '';
                foreach (['min', 'max'] as $k) {
                    if (isset($f[$k]) && in_array($type, ['number', 'date'], true)) {
                        $extra .= " $k=\"" . e($f[$k]) . '"';
                    }
                }
                if ($type === 'number') {
                    $extra .= ' step="any"';
                }
                if (isset($f['maxlength'])) {
                    $extra .= ' maxlength="' . (int) $f['maxlength'] . '"';
                }
                if (isset($f['pattern'])) {
                    $extra .= ' pattern="' . e($f['pattern']) . '"';
                    if (isset($f['pattern_message'])) {
                        $extra .= ' title="' . e($f['pattern_message']) . '"';
                    }
                }
                return "<input type=\"$htmlType\" $common value=\"" . e($value) . "\"$ph$extra>";
        }
    }

    /**
     * แสดงคำตอบที่บันทึกแล้วเป็นตาราง
     * @param array $data  data_json ของ submission
     * @param array $files แถวจาก submission_files จัดกลุ่มตาม field_name
     * @param bool  $forEmail true = ไม่ใส่ลิงก์ดาวน์โหลด/รูป (ใช้ในเมล)
     */
    public function renderAnswers(array $data, array $files = [], bool $forEmail = false): string
    {
        $rows = '';
        foreach ($this->answerRows($data, $files) as $row) {
            if ($row['kind'] === 'heading') {
                $rows .= '<tr class="answer-heading"><th colspan="2">' . e($row['label']) . '</th></tr>';
                continue;
            }
            $rows .= '<tr><th>' . e($row['label']) . '</th><td>' . $this->formatAnswer($row, $forEmail) . '</td></tr>';
        }
        $style = $forEmail ? ' border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse"' : '';
        return "<table class=\"answers\"$style>$rows</table>";
    }

    /**
     * แถวคำตอบตามลำดับช่องใน schema (ข้ามช่องที่ไม่มีคำตอบเพราะถูกซ่อน)
     * @return list<array{kind: string, field?: array, label: string, value?: mixed, files?: array}>
     */
    public function answerRows(array $data, array $files = []): array
    {
        $rows = [];
        foreach ($this->schema['fields'] ?? [] as $field) {
            $type = $field['type'];
            if ($type === 'heading') {
                $rows[] = ['kind' => 'heading', 'label' => $field['label']];
                continue;
            }
            if (!FormSchema::isInput($field)) {
                continue;
            }
            $name = $field['name'];
            $hasValue = array_key_exists($name, $data) || isset($files[$name]);
            if (!$hasValue) {
                continue;   // ช่องที่ถูกซ่อนตอนกรอก
            }
            $rows[] = ['kind' => 'answer', 'field' => $field, 'label' => $field['label'],
                'value' => $data[$name] ?? null, 'files' => $files[$name] ?? []];
        }
        $rows = $this->dropEmptyHeadings($rows);
        return $rows;
    }

    /** ค่าคำตอบเป็นข้อความล้วน (ใช้ใน CSV และหัวข้อเมล) */
    public static function plainValue(array $field, mixed $value, array $files = []): string
    {
        $type = $field['type'] ?? 'text';
        if ($type === 'file') {
            return implode(', ', array_map(fn ($f) => (string) $f['original_name'], $files));
        }
        if ($type === 'signature') {
            return $files ? 'มีลายเซ็น' : '';
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => FormSchema::optionLabel($field, (string) $v), $value));
        }
        if (in_array($type, FormSchema::OPTION_TYPES, true)) {
            return FormSchema::optionLabel($field, (string) $value);
        }
        if ($type === 'datetime' && is_string($value) && FormSchema::isDateTime($value)) {
            return DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value)->format('d/m/Y H:i');
        }
        if ($type === 'date' && is_string($value) && FormSchema::isDate($value)) {
            return DateTimeImmutable::createFromFormat('!Y-m-d', $value)->format('d/m/Y');
        }
        return (string) ($value ?? '');
    }

    private function formatAnswer(array $row, bool $forEmail): string
    {
        $field = $row['field'];
        $type = $field['type'];
        $files = $row['files'];

        if ($type === 'file' || $type === 'signature') {
            if (!$files) {
                return '<span class="muted">-</span>';
            }
            if ($forEmail) {
                return e(self::plainValue($field, null, $files));
            }
            $out = [];
            foreach ($files as $file) {
                $href = e(url('file.php', ['id' => $file['id']]));
                if ($type === 'signature') {
                    $out[] = "<img class=\"signature-img\" src=\"$href\" alt=\"ลายเซ็น\">";
                } else {
                    $out[] = "<a href=\"$href\">" . e($file['original_name']) . '</a> <span class="muted">('
                        . e(format_bytes((int) $file['size_bytes'])) . ')</span>';
                }
            }
            return implode('<br>', $out);
        }

        $text = self::plainValue($field, $row['value']);
        if ($text === '') {
            return '<span class="muted">-</span>';
        }
        return nl2br(e($text));
    }

    private function dropEmptyHeadings(array $rows): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            if ($row['kind'] === 'heading') {
                $next = $rows[$i + 1] ?? null;
                if ($next === null || $next['kind'] === 'heading') {
                    continue;
                }
            }
            $out[] = $row;
        }
        return $out;
    }
}

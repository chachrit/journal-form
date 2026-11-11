<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

/** Export คำตอบเป็น CSV (UTF-8 BOM เพื่อให้ Excel แสดงภาษาไทยถูกต้อง) */

$formId = query_int('form');
$form = $formId ? FormRepository::find($formId) : null;
if ($form === null) {
    abort(404, 'ไม่พบฟอร์ม');
}
Auth::requireFormViewer($formId);

$filter = ['from' => query_str('from'), 'to' => query_str('to'), 'q' => mb_substr(query_str('q'), 0, 100)];

// รวมช่องจากทุกเวอร์ชัน: ใช้ลำดับและ label จากเวอร์ชันล่าสุด ช่องที่ถูกลบไปแล้วต่อท้าย
$columns = [];
$schemas = FormRepository::allSchemas($formId);
foreach (array_reverse($schemas, true) as $schema) {
    foreach (FormSchema::inputFields($schema) as $field) {
        $columns[$field['name']] ??= $field;
    }
}
$latest = $schemas ? end($schemas) : ['fields' => []];
$order = array_column(FormSchema::inputFields($latest), 'name');
uksort($columns, function ($a, $b) use ($order) {
    $ia = array_search($a, $order, true);
    $ib = array_search($b, $order, true);
    return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
});

/** กัน CSV injection: ค่าที่ขึ้นต้นด้วย = + - @ จะถูกตีความเป็นสูตรใน Excel */
function csv_safe(string $v): string
{
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
}

$filename = $form['slug'] . '-' . date('Ymd-His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, [
    'เลขที่', 'วันเวลาที่ส่ง', 'รหัสพนักงาน', 'ชื่อผู้ส่ง', 'อีเมล', 'แผนก', 'เวอร์ชันฟอร์ม',
    ...array_map(fn ($f) => csv_safe($f['label']), array_values($columns)),
], ',', '"', '');

FormRepository::eachSubmission($formId, $filter, function (array $row, array $files) use ($out, $columns) {
    $line = [
        $row['id'], format_datetime($row['created_at']), $row['submitted_by'],
        csv_safe((string) $row['submitter_name']), csv_safe((string) $row['submitter_email']),
        csv_safe((string) $row['submitter_dept']), 'v' . $row['form_version'],
    ];
    foreach ($columns as $name => $field) {
        $line[] = csv_safe(FormRenderer::plainValue($field, $row['data'][$name] ?? null, $files[$name] ?? []));
    }
    fputcsv($out, $line, ',', '"', '');
});
fclose($out);

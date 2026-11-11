<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

/** AJAX: ตรวจ JSON และคืน HTML ตัวอย่างฟอร์ม ให้หน้า form_edit.php */

Auth::requireAdmin();
if (!is_post()) {
    json_response(['error' => 'POST only'], 405);
}
Csrf::check();

$check = FormSchema::validateJson((string) ($_POST['schema'] ?? ''));
if ($check['errors']) {
    json_response(['ok' => false, 'errors' => $check['errors']]);
}

$schema = $check['schema'];
$renderer = new FormRenderer($schema, Auth::context());
json_response([
    'ok' => true,
    'title' => $schema['title'],
    'description' => $schema['description'] ?? '',
    'html' => $renderer->renderFields($renderer->defaults()),
]);

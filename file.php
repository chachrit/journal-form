<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

/** ดาวน์โหลดไฟล์แนบ/ลายเซ็น — ต้องเป็นเจ้าของคำตอบ หรือมีสิทธิ์ดูคำตอบของฟอร์ม */

$user = Auth::requireLogin();
$id = query_int('id');
$file = $id ? FormRepository::file($id) : null;
if ($file === null) {
    abort(404, 'ไม่พบไฟล์');
}
$isOwner = (int) $file['submitted_by'] === (int) $user['employee_id'];
if (!$isOwner && !Auth::canViewForm((int) $file['form_id'])) {
    abort(403, 'คุณไม่มีสิทธิ์เปิดไฟล์นี้');
}

$path = FileStorage::default()->absolute($file['stored_path']);
if (!is_file($path)) {
    abort(404, 'ไม่พบไฟล์บนเซิร์ฟเวอร์');
}

// แสดงในเบราว์เซอร์ได้เฉพาะรูปและ PDF ที่เหลือบังคับดาวน์โหลด
$mime = (string) $file['mime_type'];
$inline = in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'], true);
$name = (string) $file['original_name'];
$asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';

header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Cache-Control: private, max-age=3600');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($path);

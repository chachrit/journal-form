<?php
declare(strict_types=1);

/**
 * จัดการผู้ดูแลระบบจาก command line
 *   php database/admin.php list
 *   php database/admin.php add 110001
 *   php database/admin.php remove 110001
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';

[$cmd, $id] = [$argv[1] ?? 'list', $argv[2] ?? null];

if (in_array($cmd, ['add', 'remove'], true) && ($id === null || !ctype_digit($id))) {
    fwrite(STDERR, "ต้องระบุ employee_id เป็นตัวเลข\n");
    exit(1);
}

switch ($cmd) {
    case 'add':
        if (!FormRepository::isAdmin((int) $id)) {
            Db::exec('INSERT INTO app_admins (employee_id) VALUES (?)', [(int) $id]);
        }
        echo "เพิ่ม $id เป็น admin แล้ว (มีผลเมื่อ login ครั้งถัดไป)\n";
        break;
    case 'remove':
        Db::exec('DELETE FROM app_admins WHERE employee_id = ?', [(int) $id]);
        echo "ลบ $id ออกจาก admin แล้ว\n";
        break;
    default:
        echo "Admin ในฐานข้อมูล:\n";
        foreach (Db::all('SELECT employee_id, created_at FROM app_admins ORDER BY employee_id') as $row) {
            echo "  {$row['employee_id']}  (" . format_datetime($row['created_at']) . ")\n";
        }
        echo 'Admin ใน config.php: ' . (implode(', ', (array) config('app.admins', [])) ?: '-') . "\n";
}

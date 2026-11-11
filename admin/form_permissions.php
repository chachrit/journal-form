<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

$user = Auth::requireAdmin();
$id = query_int('id');
$form = $id ? FormRepository::find($id) : null;
if ($form === null) {
    abort(404, 'ไม่พบฟอร์ม');
}

$employees = [];
try {
    $employees = EmployeeApi::fromConfig()->employees((string) $user['token']);
} catch (EmployeeApiException) {
    // ไม่มีรายชื่อก็ยังให้สิทธิ์ด้วยรหัสพนักงานได้
}

if (is_post()) {
    Csrf::check();
    $empId = post_str('employee_id');
    // รับได้ทั้ง "110001" และ "110001 - ชื่อ" (จาก datalist)
    if (preg_match('/^(\d{1,10})/', $empId, $m)) {
        $empId = (int) $m[1];
        if (post_str('action') === 'revoke') {
            FormRepository::revoke($id, $empId);
            flash('success', "ยกเลิกสิทธิ์ของ $empId แล้ว");
        } elseif ($employees && !isset($employees[$empId])) {
            flash('error', "ไม่พบพนักงานรหัส $empId ในระบบพนักงาน");
        } else {
            FormRepository::grant($id, $empId, (int) $user['employee_id']);
            flash('success', "ให้สิทธิ์ $empId ดูคำตอบแล้ว");
        }
    } else {
        flash('error', 'กรุณาระบุรหัสพนักงาน');
    }
    redirect('admin/form_permissions.php', ['id' => $id]);
}

$permissions = FormRepository::permissions($id);
$nameOf = static function (int $empId) use ($employees): string {
    $emp = $employees[$empId] ?? null;
    if (!$emp) {
        return '';
    }
    return trim(($emp['prefix_th'] ?? '') . ($emp['first_name_th'] ?? '') . ' ' . ($emp['last_name_th'] ?? ''));
};

$pageTitle = 'สิทธิ์ดูคำตอบ: ' . $form['title'];
require __DIR__ . '/../templates/header.php';
?>
<p><a href="<?= e(url('admin/forms.php')) ?>">← รายการฟอร์ม</a></p>
<h1><?= e($pageTitle) ?></h1>
<p class="muted">คนที่อยู่ในรายการนี้ดูคำตอบ ไฟล์แนบ และ export CSV ของฟอร์มนี้ได้ (แก้ไขฟอร์มไม่ได้) — ผู้ดูแลระบบเห็นทุกฟอร์มอยู่แล้ว</p>

<div class="card">
    <form method="post" class="inline-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="grant">
        <input type="text" name="employee_id" list="employee-list" placeholder="พิมพ์รหัสพนักงานหรือชื่อ" required autocomplete="off">
        <datalist id="employee-list">
            <?php foreach ($employees as $empId => $emp): ?>
                <option value="<?= e($empId . ' - ' . $nameOf((int) $empId) . ($emp['department_name'] ? ' (' . $emp['department_name'] . ')' : '')) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <button type="submit" class="btn btn-primary">ให้สิทธิ์</button>
    </form>
</div>

<?php if (!$permissions): ?>
    <p class="muted">ยังไม่มีผู้ได้รับสิทธิ์</p>
<?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>รหัสพนักงาน</th><th>ชื่อ</th><th>แผนก</th><th>ให้สิทธิ์เมื่อ</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($permissions as $p): $empId = (int) $p['employee_id']; ?>
            <tr>
                <td><?= e($empId) ?></td>
                <td><?= e($nameOf($empId)) ?: '<span class="muted">-</span>' ?></td>
                <td><?= e($employees[$empId]['department_name'] ?? '') ?></td>
                <td><?= e(format_datetime($p['created_at'])) ?></td>
                <td>
                    <form method="post" class="inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="revoke">
                        <input type="hidden" name="employee_id" value="<?= e($empId) ?>">
                        <button type="submit" class="link danger">ยกเลิกสิทธิ์</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>

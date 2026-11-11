<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

$user = Auth::requireLogin();
$isAdmin = Auth::isAdmin();

// เปิด/ปิดรับคำตอบ
if (is_post()) {
    Auth::requireAdmin();
    Csrf::check();
    $formId = (int) post_str('form_id');
    $status = post_str('status');
    if (in_array($status, ['published', 'closed'], true) && FormRepository::find($formId)) {
        FormRepository::setStatus($formId, $status, (int) $user['employee_id']);
        flash('success', $status === 'published' ? 'เปิดรับคำตอบแล้ว' : 'ปิดรับคำตอบแล้ว');
    }
    redirect('admin/forms.php');
}

$forms = $isAdmin ? FormRepository::allWithCounts() : FormRepository::viewableBy((int) $user['employee_id']);
if (!$isAdmin && !$forms) {
    abort(403, 'คุณยังไม่ได้รับสิทธิ์ดูคำตอบของฟอร์มใด');
}

$statusLabels = ['draft' => 'ฉบับร่าง', 'published' => 'เปิดรับ', 'closed' => 'ปิดรับ'];
$pageTitle = $isAdmin ? 'จัดการฟอร์ม' : 'ดูคำตอบ';
$wide = true;
require __DIR__ . '/../templates/header.php';
?>
<div class="page-head">
    <h1><?= e($pageTitle) ?></h1>
    <?php if ($isAdmin): ?>
        <a class="btn btn-primary" href="<?= e(url('admin/form_edit.php')) ?>">+ สร้างฟอร์มใหม่</a>
    <?php endif; ?>
</div>

<?php if (!$forms): ?>
    <p class="muted">ยังไม่มีฟอร์ม</p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
    <thead>
    <tr>
        <th>ฟอร์ม</th>
        <th>สถานะ</th>
        <?php if ($isAdmin): ?><th>เวอร์ชัน</th><?php endif; ?>
        <th class="num">คำตอบ</th>
        <?php if ($isAdmin): ?><th>คำตอบล่าสุด</th><?php endif; ?>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($forms as $f): ?>
        <tr>
            <td>
                <strong><?= e($f['title']) ?></strong><br>
                <?php if ($f['status'] === 'published'): ?>
                    <a class="muted small" href="<?= e(url('f/' . $f['slug'])) ?>" target="_blank">/f/<?= e($f['slug']) ?></a>
                <?php else: ?>
                    <span class="muted small">/f/<?= e($f['slug']) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge badge-<?= e($f['status']) ?>"><?= e($statusLabels[$f['status']] ?? $f['status']) ?></span>
                <?php if ($isAdmin && (int) $f['has_draft_changes'] === 1 && (int) $f['current_version'] > 0): ?>
                    <span class="badge badge-warn" title="มีการแก้ไขที่ยังไม่เผยแพร่">มีแก้ไขค้าง</span>
                <?php endif; ?>
            </td>
            <?php if ($isAdmin): ?><td><?= (int) $f['current_version'] > 0 ? 'v' . e($f['current_version']) : '-' ?></td><?php endif; ?>
            <td class="num"><?= e(number_format((int) $f['submission_count'])) ?></td>
            <?php if ($isAdmin): ?><td><?= e(format_datetime($f['last_submission_at'])) ?: '-' ?></td><?php endif; ?>
            <td class="row-actions">
                <a href="<?= e(url('admin/submissions.php', ['form' => $f['id']])) ?>">คำตอบ</a>
                <?php if ($isAdmin): ?>
                    <a href="<?= e(url('admin/form_edit.php', ['id' => $f['id']])) ?>">แก้ไข</a>
                    <a href="<?= e(url('admin/form_permissions.php', ['id' => $f['id']])) ?>">สิทธิ์</a>
                    <?php if ((int) $f['current_version'] > 0): ?>
                        <form method="post" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="form_id" value="<?= e($f['id']) ?>">
                            <?php if ($f['status'] === 'published'): ?>
                                <input type="hidden" name="status" value="closed">
                                <button type="submit" class="link">ปิดรับ</button>
                            <?php else: ?>
                                <input type="hidden" name="status" value="published">
                                <button type="submit" class="link">เปิดรับ</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>

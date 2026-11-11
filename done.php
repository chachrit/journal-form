<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$user = Auth::requireLogin();
$id = query_int('id');
$submission = $id ? FormRepository::submission($id) : null;
if ($submission === null || (int) $submission['submitted_by'] !== (int) $user['employee_id']) {
    abort(404);
}
$form = FormRepository::find((int) $submission['form_id']);
$schema = FormRepository::schema((int) $form['id'], (int) $submission['form_version']);

$pageTitle = 'ส่งเรียบร้อย';
require __DIR__ . '/templates/header.php';
?>
<div class="card done">
    <div class="done-icon" aria-hidden="true">✓</div>
    <h1><?= e($schema['success_message'] ?? 'ส่งฟอร์มเรียบร้อยแล้ว') ?></h1>
    <p class="muted"><?= e($schema['title']) ?> · เลขที่ #<?= e($submission['id']) ?> · <?= e(format_datetime($submission['created_at'])) ?></p>
    <p class="actions-center">
        <a class="btn" href="<?= e(url('my.php', ['id' => $submission['id']])) ?>">ดูคำตอบที่ส่ง</a>
        <?php if ($form['status'] === 'published'): ?>
            <a class="btn" href="<?= e(url('f/' . $form['slug'])) ?>">กรอกอีกครั้ง</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="<?= e(url('index.php')) ?>">กลับหน้าแรก</a>
    </p>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>

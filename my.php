<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$user = Auth::requireLogin();
$id = query_int('id');
$submission = $id ? FormRepository::submission($id) : null;
if ($submission === null || (int) $submission['submitted_by'] !== (int) $user['employee_id']) {
    abort(404, 'ไม่พบคำตอบนี้');
}
$form = FormRepository::find((int) $submission['form_id']);
$schema = FormRepository::schema((int) $form['id'], (int) $submission['form_version']);
$files = FormRepository::submissionFiles((int) $submission['id']);

$pageTitle = $schema['title'];
require __DIR__ . '/templates/header.php';
?>
<p><a href="<?= e(url('index.php')) ?>">← กลับ</a></p>
<div class="card">
    <h1><?= e($schema['title']) ?></h1>
    <?php require __DIR__ . '/templates/submission_detail.php'; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>

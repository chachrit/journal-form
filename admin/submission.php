<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

$id = query_int('id');
$submission = $id ? FormRepository::submission($id) : null;
if ($submission === null) {
    abort(404, 'ไม่พบคำตอบ');
}
Auth::requireFormViewer((int) $submission['form_id']);

$form = FormRepository::find((int) $submission['form_id']);
$schema = FormRepository::schema((int) $form['id'], (int) $submission['form_version']);
$files = FormRepository::submissionFiles((int) $submission['id']);

$pageTitle = $form['title'] . ' #' . $submission['id'];
require __DIR__ . '/../templates/header.php';
?>
<p><a href="<?= e(url('admin/submissions.php', ['form' => $form['id']])) ?>">← คำตอบทั้งหมด</a></p>
<div class="card">
    <h1><?= e($schema['title']) ?></h1>
    <?php require __DIR__ . '/../templates/submission_detail.php'; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>

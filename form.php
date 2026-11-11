<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$user = Auth::requireLogin();

$slug = query_str('slug');
$form = $slug !== '' ? FormRepository::findBySlug($slug) : null;
if ($form === null || (int) $form['current_version'] === 0) {
    abort(404, 'ไม่พบฟอร์มนี้');
}
if ($form['status'] !== 'published') {
    abort(403, 'ฟอร์มนี้ปิดรับคำตอบแล้ว');
}

$schema = FormRepository::schema((int) $form['id'], (int) $form['current_version']);
$context = Auth::context();
$renderer = new FormRenderer($schema, $context);
$values = $renderer->defaults();
$errors = [];
$formError = '';

if (is_post()) {
    // POST ใหญ่เกิน post_max_size → PHP ทิ้งข้อมูลทั้งหมด (รวม CSRF token)
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $formError = 'ไฟล์ที่แนบรวมกันมีขนาดใหญ่เกินที่ระบบรองรับ (' . ini_get('post_max_size') . ') กรุณาลดขนาดไฟล์แล้วส่งใหม่';
    } else {
        Csrf::check();
        $validator = new SubmissionValidator($schema, $context, (float) config('upload.max_size_mb', 10));
        $result = $validator->validate($_POST, $_FILES);
        $values = array_merge($values, $result['old']);
        $errors = $result['errors'];

        if (!$errors) {
            $storage = FileStorage::default();
            $saved = [];
            try {
                foreach ($result['uploads'] as $field => $uploads) {
                    foreach ($uploads as $upload) {
                        $saved[] = ['field_name' => $field, 'kind' => 'file'] + $storage->saveUpload($upload);
                    }
                }
                foreach ($result['signatures'] as $field => $png) {
                    $saved[] = ['field_name' => $field, 'kind' => 'signature'] + $storage->saveSignature($png);
                }
                $submissionId = FormRepository::createSubmission($form, $user, $result['values'], $saved, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            } catch (Throwable $ex) {
                foreach ($saved as $file) {
                    $storage->delete($file['stored_path']);
                }
                throw $ex;
            }

            // ส่งเมล — ถ้าส่งไม่ได้ คำตอบยังถูกบันทึกแล้ว (ดู error ใน mail_log)
            try {
                Notifier::submissionReceived(
                    $form,
                    $schema,
                    FormRepository::submission($submissionId),
                    FormRepository::submissionFiles($submissionId)
                );
            } catch (Throwable $ex) {
                error_log('[notify] ' . $ex);
            }

            redirect('done.php', ['slug' => $form['slug'], 'id' => $submissionId]);
        }
        $formError = 'กรุณาตรวจสอบช่องที่มีข้อความสีแดง' . ($_FILES ? ' (ไฟล์แนบต้องเลือกใหม่อีกครั้ง)' : '');
    }
}

$pageTitle = $schema['title'];
$scripts = ['assets/js/form.js'];
require __DIR__ . '/templates/header.php';
?>
<div class="card">
    <h1><?= e($schema['title']) ?></h1>
    <?php if (!empty($schema['description'])): ?>
        <p class="description"><?= nl2br(e($schema['description'])) ?></p>
    <?php endif; ?>
    <?php if ($formError): ?>
        <div class="alert alert-error"><?= e($formError) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="jf-form" data-jf-form>
        <?= Csrf::field() ?>
        <?= $renderer->renderFields($values, $errors) ?>
        <div class="actions">
            <button type="submit" class="btn btn-primary">ส่งฟอร์ม</button>
        </div>
    </form>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>

<?php
/**
 * แสดงรายละเอียดคำตอบ (ใช้ร่วมกันใน my.php และ admin/submission.php)
 * @var array $form @var array $submission @var array $schema @var array $files
 */
$renderer = new FormRenderer($schema);
?>
<dl class="meta">
    <dt>เลขที่</dt><dd>#<?= e($submission['id']) ?></dd>
    <dt>ผู้ส่ง</dt><dd><?= e($submission['submitter_name']) ?> (<?= e($submission['submitted_by']) ?>)</dd>
    <?php if ($submission['submitter_dept']): ?>
        <dt>แผนก</dt><dd><?= e($submission['submitter_dept']) ?></dd>
    <?php endif; ?>
    <dt>วันเวลาที่ส่ง</dt><dd><?= e(format_datetime($submission['created_at'])) ?></dd>
    <?php if ((int) $submission['form_version'] !== (int) $form['current_version']): ?>
        <dt>เวอร์ชันฟอร์ม</dt><dd>v<?= e($submission['form_version']) ?> <span class="muted">(ปัจจุบัน v<?= e($form['current_version']) ?>)</span></dd>
    <?php endif; ?>
</dl>
<?= $renderer->renderAnswers($submission['data'], $files) ?>

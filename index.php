<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$user = Auth::requireLogin();
$forms = FormRepository::published();
$mine = FormRepository::mySubmissions((int) $user['employee_id']);

$pageTitle = 'ฟอร์ม';
require __DIR__ . '/templates/header.php';
?>
<h1>ฟอร์มที่เปิดให้กรอก</h1>
<?php if (!$forms): ?>
    <p class="muted">ยังไม่มีฟอร์มที่เปิดให้กรอก</p>
<?php else: ?>
    <div class="form-grid">
        <?php foreach ($forms as $form): ?>
            <a class="card form-card" href="<?= e(url('f/' . $form['slug'])) ?>">
                <span class="form-card-title"><?= e($form['title']) ?></span>
                <span class="muted">กรอกฟอร์ม →</span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2>คำตอบของฉัน</h2>
<?php if (!$mine): ?>
    <p class="muted">ยังไม่เคยส่งฟอร์ม</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>เลขที่</th><th>ฟอร์ม</th><th>วันเวลาที่ส่ง</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($mine as $s): ?>
                <tr>
                    <td>#<?= e($s['id']) ?></td>
                    <td><?= e($s['title']) ?></td>
                    <td><?= e(format_datetime($s['created_at'])) ?></td>
                    <td><a href="<?= e(url('my.php', ['id' => $s['id']])) ?>">ดู</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/templates/footer.php'; ?>

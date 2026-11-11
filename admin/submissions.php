<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

$formId = query_int('form');
$form = $formId ? FormRepository::find($formId) : null;
if ($form === null) {
    abort(404, 'ไม่พบฟอร์ม');
}
Auth::requireFormViewer($formId);

$filter = ['from' => query_str('from'), 'to' => query_str('to'), 'q' => mb_substr(query_str('q'), 0, 100)];
$perPage = 50;
$page = max(1, query_int('page') ?? 1);
$total = FormRepository::countSubmissions($formId, $filter);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$rows = FormRepository::submissions($formId, $filter, $perPage, ($page - 1) * $perPage);

// คอลัมน์: ช่องข้อความ/ตัวเลือก 4 ช่องแรกของเวอร์ชันล่าสุด
$schema = (int) $form['current_version'] > 0
    ? FormRepository::schema($formId, (int) $form['current_version'])
    : json_decode($form['draft_json'], true);
$columns = array_slice(array_values(array_filter(
    FormSchema::inputFields($schema),
    fn ($f) => !in_array($f['type'], ['file', 'signature', 'textarea'], true)
)), 0, 4);

$queryBase = array_filter(['form' => $formId] + $filter, fn ($v) => $v !== '' && $v !== null);

$pageTitle = 'คำตอบ: ' . $form['title'];
$wide = true;
require __DIR__ . '/../templates/header.php';
?>
<p><a href="<?= e(url('admin/forms.php')) ?>">← รายการฟอร์ม</a></p>
<div class="page-head">
    <h1><?= e($pageTitle) ?></h1>
    <a class="btn" href="<?= e(url('admin/export.php', $queryBase)) ?>">Export CSV</a>
</div>

<form method="get" class="filters">
    <input type="hidden" name="form" value="<?= e($formId) ?>">
    <label>ตั้งแต่ <input type="date" name="from" value="<?= e($filter['from']) ?>"></label>
    <label>ถึง <input type="date" name="to" value="<?= e($filter['to']) ?>"></label>
    <input type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="ค้นหาชื่อ รหัส หรือข้อความในคำตอบ">
    <button type="submit" class="btn">กรอง</button>
    <?php if (array_filter($filter)): ?>
        <a href="<?= e(url('admin/submissions.php', ['form' => $formId])) ?>">ล้าง</a>
    <?php endif; ?>
</form>

<p class="muted">ทั้งหมด <?= e(number_format($total)) ?> คำตอบ</p>

<?php if ($rows): ?>
<div class="table-wrap">
<table class="table">
    <thead>
    <tr>
        <th>เลขที่</th>
        <th>วันเวลา</th>
        <th>ผู้ส่ง</th>
        <th>แผนก</th>
        <?php foreach ($columns as $col): ?><th><?= e($col['label']) ?></th><?php endforeach; ?>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td>#<?= e($row['id']) ?></td>
            <td class="nowrap"><?= e(format_datetime($row['created_at'])) ?></td>
            <td><?= e($row['submitter_name']) ?><br><span class="muted small"><?= e($row['submitted_by']) ?></span></td>
            <td><?= e($row['submitter_dept']) ?></td>
            <?php foreach ($columns as $col): ?>
                <td><?= e(mb_strimwidth(FormRenderer::plainValue($col, $row['data'][$col['name']] ?? null), 0, 60, '…')) ?></td>
            <?php endforeach; ?>
            <td><a href="<?= e(url('admin/submission.php', ['id' => $row['id']])) ?>">ดู</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
    <nav class="pager">
        <?php if ($page > 1): ?><a href="<?= e(url('admin/submissions.php', $queryBase + ['page' => $page - 1])) ?>">← ก่อนหน้า</a><?php endif; ?>
        <span>หน้า <?= e($page) ?> / <?= e($pages) ?></span>
        <?php if ($page < $pages): ?><a href="<?= e(url('admin/submissions.php', $queryBase + ['page' => $page + 1])) ?>">ถัดไป →</a><?php endif; ?>
    </nav>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>

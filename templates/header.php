<?php
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? (string) config('app.name');
$currentUser = Auth::user();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=2">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= e(url('index.php')) ?>"><?= e(config('app.name')) ?></a>
    <?php if ($currentUser): ?>
        <nav class="nav">
            <a href="<?= e(url('index.php')) ?>">ฟอร์ม</a>
            <?php if (Auth::isAdmin()): ?>
                <a href="<?= e(url('admin/forms.php')) ?>">จัดการฟอร์ม</a>
            <?php elseif (FormRepository::viewableBy((int) $currentUser['employee_id'])): ?>
                <a href="<?= e(url('admin/forms.php')) ?>">ดูคำตอบ</a>
            <?php endif; ?>
        </nav>
        <div class="user">
            <span><?= e($currentUser['name']) ?></span>
            <form method="post" action="<?= e(url('logout.php')) ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="link">ออกจากระบบ</button>
            </form>
        </div>
    <?php endif; ?>
</header>
<?php if (config('app.env') === 'development'): ?>
    <div class="dev-banner">โหมดพัฒนา (dev)<?= config('employee_api.fake_login') ? ' — fake login เปิดอยู่' : '' ?><?= config('mail.transport') !== 'smtp' ? ' — เมลเขียนลงไฟล์' : '' ?></div>
<?php endif; ?>
<main class="container<?= !empty($wide) ? ' wide' : '' ?>">
<?php foreach (take_flashes() as $flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endforeach; ?>

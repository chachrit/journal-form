<?php
/** @var string $title @var string $message @var int $status */
$pageTitle = $title;
try {
    require __DIR__ . '/header.php';
} catch (Throwable) {
    echo '<!doctype html><meta charset="utf-8"><main class="container">';
}
?>
<div class="card error-page">
    <h1><?= e($title) ?></h1>
    <p><?= e($message) ?></p>
    <p><a class="btn" href="<?= e(url('index.php')) ?>">กลับหน้าแรก</a></p>
</div>
<?php require __DIR__ . '/footer.php'; ?>

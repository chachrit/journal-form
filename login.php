<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

/** ไปหน้าเดิมหลัง login ได้เฉพาะ path ภายในระบบ (กัน open redirect) */
function safe_next(string $next): string
{
    $base = rtrim((string) config('app.base_url', ''), '/') . '/';
    if ($next !== '' && str_starts_with($next, $base) && !str_starts_with($next, '//') && !preg_match('#[\r\n\\\\]#', $next)) {
        return $next;
    }
    return url('index.php');
}

$next = safe_next(is_post() ? post_str('next') : query_str('next'));

if (Auth::user() !== null) {
    header('Location: ' . $next);
    exit;
}

$error = '';
$employeeId = '';

if (is_post()) {
    Csrf::check();
    $employeeId = post_str('employee_id');
    $password = (string) ($_POST['password'] ?? '');

    if (!ctype_digit($employeeId) || strlen($employeeId) > 10) {
        $error = 'รหัสพนักงานต้องเป็นตัวเลข';
    } elseif ($password === '') {
        $error = 'กรุณากรอกรหัสผ่าน';
    } else {
        try {
            Auth::attempt((int) $employeeId, $password);
            header('Location: ' . $next);
            exit;
        } catch (EmployeeApiException $ex) {
            $error = $ex->getMessage();
        }
    }
}

$pageTitle = 'เข้าสู่ระบบ';
require __DIR__ . '/templates/header.php';
?>
<div class="card login-card">
    <h1>เข้าสู่ระบบ</h1>
    <p class="muted">ใช้รหัสพนักงานและรหัสผ่านเดียวกับระบบพนักงาน</p>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="on">
        <?= Csrf::field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field">
            <label class="label" for="employee_id">รหัสพนักงาน</label>
            <input type="text" id="employee_id" name="employee_id" inputmode="numeric" pattern="[0-9]+"
                   value="<?= e($employeeId) ?>" required autofocus autocomplete="username">
        </div>
        <div class="field">
            <label class="label" for="password">รหัสผ่าน</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block">เข้าสู่ระบบ</button>
    </form>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>

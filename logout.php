<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (is_post()) {
    Csrf::check();
    Auth::logout();
}
redirect('login.php');

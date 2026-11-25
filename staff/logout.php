<?php
require_once __DIR__ . '/../config.php';

if (is_post()) {
    csrf_verify();
    $_SESSION = [];
    session_regenerate_id(true);
    flash('success', 'You have been signed out.');
}
redirect('staff/login.php');

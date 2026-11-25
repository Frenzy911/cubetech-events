<?php
require_once __DIR__ . '/../config.php';

/** Only allow redirects back into this app (never to another site). */
function safe_next(string $next, array $staff): string
{
    $base = base_path() . '/';
    $ok = $next !== '' && str_starts_with($next, $base) && !str_contains($next, '//') && !preg_match('/[\r\n\\\\]/', $next)
        && !str_contains($next, 'login.php') && !str_contains($next, 'logout.php');
    if (!$ok) {
        return url(staff_home($staff));
    }
    // Door staff can only use the check-in screen.
    if ($staff['role'] !== 'organizer' && !str_contains($next, '/staff/checkin')) {
        return url('staff/checkin.php');
    }
    return $next;
}

$already = current_staff();
if ($already) {
    redirect(safe_next(get_str('next', 500), $already));
}

$username = '';
$error = null;
$next = get_str('next', 500);

if (is_post()) {
    csrf_verify();
    $username = post_str('username', 50);
    $password = (string) ($_POST['password'] ?? '');
    $next = post_str('next', 500);
    $ip = client_ip();

    if (rate_limited('login', $ip, 10, 900)) {
        $error = 'Too many failed sign-in attempts. Please wait 15 minutes and try again.';
    } else {
        $user = db_one('SELECT id, name, role, password_hash, is_active FROM staff_users WHERE username = ?', [$username]);
        // Verify against a dummy hash when the user doesn't exist, so timing doesn't reveal valid usernames.
        $hash = $user['password_hash'] ?? '$2y$10$uJuKBtlrH/os9U4rq7uihuLm/iHpZpq99v92nRyoGEHIiR1Sq3Sxy';
        $valid = password_verify($password, $hash) && $user && (int) $user['is_active'] === 1;

        if ($valid) {
            session_regenerate_id(true);
            $_SESSION['staff_id'] = (int) $user['id'];
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db_exec('UPDATE staff_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
            }
            db_exec('UPDATE staff_users SET last_login_at = NOW() WHERE id = ?', [(int) $user['id']]);
            redirect(safe_next($next, $user));
        }
        rate_hit('login', $ip);
        $error = ($user && (int) $user['is_active'] !== 1 && password_verify($password, $user['password_hash']))
            ? 'This account has been deactivated. Ask an organizer.'
            : 'Incorrect username or password.';
    }
}

render_header('Staff sign in', ['area' => 'staff', 'narrow' => true]);
?>
<section class="card auth-card">
  <div class="auth-icon"><?= icon('users') ?></div>
  <h1 class="h2 center">Staff sign in</h1>
  <p class="muted center">For organizers and door staff only.</p>

  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('x') ?><div><?= h($error) ?></div></div>
  <?php endif; ?>

  <form method="post" class="form" novalidate data-validate>
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <?= field('username', 'Username', $username, null, [
        'autocomplete' => 'username', 'required' => true, 'data-rule' => 'required', 'autocapitalize' => 'off',
        'spellcheck' => 'false', 'maxlength' => 50, 'autofocus' => true,
    ]) ?>
    <?= field('password', 'Password', '', null, [
        'type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'data-rule' => 'required',
    ]) ?>
    <button class="btn btn-primary btn-lg btn-block" type="submit">Sign in</button>
  </form>
  <p class="muted small center"><a href="<?= h(url('')) ?>">← Back to events</a></p>
</section>
<?php render_footer(['area' => 'staff']); ?>

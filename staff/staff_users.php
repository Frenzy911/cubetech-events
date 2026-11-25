<?php
/** Organizer manages staff accounts: add, change role, activate/deactivate, reset password. */
require_once __DIR__ . '/../config.php';
$staff = require_organizer();

$roles = ['door_staff' => 'Door staff (check-in only)', 'organizer' => 'Organizer (manages events and staff)'];
$new = ['name' => '', 'username' => '', 'role' => 'door_staff'];
$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);

    if ($action === 'create') {
        $new = ['name' => post_str('name', 100), 'username' => strtolower(post_str('username', 50)), 'role' => post_str('role', 20)];
        $password = (string) ($_POST['password'] ?? '');
        if ($new['name'] === '') {
            $errors['name'] = 'Enter a name.';
        }
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $new['username'])) {
            $errors['username'] = 'Use 3–50 characters: lowercase letters, numbers, dot, dash or underscore.';
        } elseif (db_value('SELECT COUNT(*) FROM staff_users WHERE username = ?', [$new['username']])) {
            $errors['username'] = 'That username is already taken.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        if (!isset($roles[$new['role']])) {
            $errors['role'] = 'Choose a role.';
        }
        if (!$errors) {
            $newId = db_insert(
                'INSERT INTO staff_users (name, username, password_hash, role) VALUES (?, ?, ?, ?)',
                [$new['name'], $new['username'], password_hash($password, PASSWORD_DEFAULT), $new['role']]
            );
            audit('staff_created', 'staff_user', $newId, sprintf('Created %s account "%s" (%s)', role_label($new['role']), $new['username'], $new['name']), $staff);
            flash('success', 'Account "' . $new['username'] . '" created.');
            redirect('staff/staff_users.php');
        }
    } elseif ($action === 'update') {
        $targetId = (int) ($_POST['id'] ?? 0);
        $target = db_one('SELECT * FROM staff_users WHERE id = ?', [$targetId]);
        $role = post_str('role', 20);
        $active = isset($_POST['is_active']) ? 1 : 0;
        $password = (string) ($_POST['password'] ?? '');

        if (!$target || !isset($roles[$role])) {
            flash('error', 'Account not found.');
        } elseif ($targetId === (int) $staff['id'] && ($role !== 'organizer' || !$active)) {
            flash('error', "You can't remove your own organizer access or deactivate your own account.");
        } elseif ($password !== '' && strlen($password) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } else {
            $changes = [];
            if ($target['role'] !== $role) {
                $changes[] = 'role: ' . role_label($target['role']) . ' → ' . role_label($role);
            }
            if ((int) $target['is_active'] !== $active) {
                $changes[] = $active ? 'reactivated' : 'deactivated';
            }
            db_exec('UPDATE staff_users SET role = ?, is_active = ? WHERE id = ?', [$role, $active, $targetId]);
            if ($password !== '') {
                db_exec('UPDATE staff_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $targetId]);
                $changes[] = 'password reset';
            }
            if ($changes) {
                audit('staff_updated', 'staff_user', $targetId, sprintf('Updated "%s": %s', $target['username'], implode('; ', $changes)), $staff);
                flash('success', 'Account "' . $target['username'] . '" updated.');
            } else {
                flash('info', 'No changes to save.');
            }
        }
        redirect('staff/staff_users.php');
    }
}

$users = db_all('SELECT * FROM staff_users ORDER BY role DESC, name');

render_header('Staff accounts', ['area' => 'staff', 'active' => 'staff']);
?>
<div class="page-head">
  <div>
    <h1>Staff accounts</h1>
    <p class="muted"><strong>Organizers</strong> manage events, attendees and staff. <strong>Door staff</strong> can only use the check-in screen.</p>
  </div>
</div>

<section class="card card-flush">
  <div class="table-wrap">
    <table class="table table-stack">
      <thead>
        <tr><th scope="col">Name</th><th scope="col">Username</th><th scope="col">Last sign-in</th><th scope="col">Role, status and password</th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): $isMe = (int) $u['id'] === (int) $staff['id']; ?>
        <tr>
          <td class="strong span-all"><?= h($u['name']) ?><?= $isMe ? ' <span class="small muted">(you)</span>' : '' ?>
            <div><?= (int) $u['is_active'] ? badge('Active', 'green') : badge('Deactivated', 'gray') ?></div>
          </td>
          <td class="mono" data-label="Username"><?= h($u['username']) ?></td>
          <td class="small nowrap" data-label="Last sign-in"><?= h(fmt_datetime($u['last_login_at'])) ?></td>
          <td class="span-all" data-label="Role, status and password">
            <form method="post" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <label class="sr-only" for="role-<?= (int) $u['id'] ?>">Role for <?= h($u['username']) ?></label>
              <select id="role-<?= (int) $u['id'] ?>" name="role"<?= $isMe ? ' disabled' : '' ?>>
                <?php foreach ($roles as $key => $label): ?>
                  <option value="<?= h($key) ?>"<?= $u['role'] === $key ? ' selected' : '' ?>><?= h(role_label($key)) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($isMe): ?><input type="hidden" name="role" value="organizer"><input type="hidden" name="is_active" value="1"><?php endif; ?>
              <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $u['is_active'] ? ' checked' : '' ?><?= $isMe ? ' disabled' : '' ?>> Active</label>
              <label class="sr-only" for="pw-<?= (int) $u['id'] ?>">New password for <?= h($u['username']) ?></label>
              <input id="pw-<?= (int) $u['id'] ?>" type="password" name="password" placeholder="New password (optional)" autocomplete="new-password" minlength="8">
              <button type="submit" class="btn btn-sm btn-secondary">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <h2 class="h3"><?= icon('plus') ?> Add a staff account</h2>
  <form method="post" class="form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="grid-2">
      <?= field('name', 'Full name', $new['name'], $errors['name'] ?? null, ['required' => true, 'maxlength' => 100]) ?>
      <?= field('username', 'Username', $new['username'], $errors['username'] ?? null, ['required' => true, 'maxlength' => 50, 'autocapitalize' => 'off', 'autocomplete' => 'off']) ?>
      <?= field('password', 'Password', '', $errors['password'] ?? null, ['type' => 'password', 'required' => true, 'minlength' => 8, 'autocomplete' => 'new-password'], 'At least 8 characters. Stored as a bcrypt hash.') ?>
      <?= field('role', 'Role', $new['role'], $errors['role'] ?? null, ['type' => 'select', 'options' => $roles]) ?>
    </div>
    <button class="btn btn-primary" type="submit">Create account</button>
  </form>
</section>
<?php render_footer(['area' => 'staff']); ?>

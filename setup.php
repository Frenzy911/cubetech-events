<?php
/**
 * One-click installer: creates the database, tables and demo data from database.sql.
 *  - First run: anyone on this computer can install.
 *  - Already installed: only a signed-in Organizer can reset the demo data.
 */
define('SKIP_DB', true);
require_once __DIR__ . '/config.php';

function run_sql_file(mysqli $db, string $file, string $dbName): void
{
    $sql = file_get_contents($file);
    $sql = str_replace('`cubetech_events`', '`' . str_replace('`', '``', $dbName) . '`', $sql);
    $db->multi_query($sql);
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
}

$error = null;
$done = false;
$installed = false;

try {
    $server = new mysqli($DB_HOST, $DB_USER, $DB_PASS, '', $DB_PORT);
    $server->set_charset('utf8mb4');
    $installed = (int) db_value_on($server,
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$DB_NAME, 'events']) > 0;
} catch (mysqli_sql_exception $e) {
    $server = null;
    $error = 'Cannot connect to MySQL. Start MySQL in the XAMPP Control Panel and check the DB_ settings in .env. (' . $e->getMessage() . ')';
}

/** Tiny helper for this page only (the normal helpers expect $conn). */
function db_value_on(mysqli $db, string $sql, array $params)
{
    $stmt = $db->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return $row[0] ?? null;
}

// When installed, connect $conn so we can check who is signed in.
$staff = null;
if ($server && $installed) {
    $server->select_db($DB_NAME);
    $server->query("SET time_zone = '+08:00'");
    $conn = $server;
    $staff = current_staff();
}
$canReset = $staff && $staff['role'] === 'organizer';

if ($server && is_post()) {
    csrf_verify();
    if ($installed && !$canReset) {
        $error = 'The database is already installed. Sign in as an organizer to reset the demo data.';
    } else {
        try {
            run_sql_file($server, __DIR__ . '/database.sql', $DB_NAME);
            $server->select_db($DB_NAME);
            $conn = $server;
            if ($installed && $staff) {
                audit('demo_reset', 'system', null, 'Reset all data to the demo seed', ['id' => 1, 'name' => $staff['name']]);
            }
            $done = true;
            $installed = true;
        } catch (mysqli_sql_exception $e) {
            $error = 'Install failed: ' . $e->getMessage();
        }
    }
}

render_header('Setup', ['narrow' => true]);
?>
<section class="card">
  <h1>Setup</h1>
  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('x') ?><div><?= h($error) ?></div></div>
  <?php endif; ?>

  <?php if ($done): ?>
    <div class="alert alert-success" role="status"><?= icon('check') ?><div>Database <strong><?= h($DB_NAME) ?></strong> is ready with demo data.</div></div>
    <h2>Demo staff logins</h2>
    <ul>
      <li>Organizer: <code>admin</code> / <code>admin123</code></li>
      <li>Door staff: <code>door</code> / <code>door123</code></li>
    </ul>
    <p class="actions">
      <a class="btn btn-primary" href="<?= h(url('')) ?>">Open the event list</a>
      <a class="btn btn-secondary" href="<?= h(url('staff/login.php')) ?>">Staff sign in</a>
    </p>
  <?php elseif ($server && !$installed): ?>
    <p>This creates the database <strong><?= h($DB_NAME) ?></strong>, its tables and the demo data (events, tickets and two staff accounts).</p>
    <form method="post">
      <?= csrf_field() ?>
      <button class="btn btn-primary btn-lg" type="submit">Install database</button>
    </form>
  <?php elseif ($installed): ?>
    <p>The database <strong><?= h($DB_NAME) ?></strong> is installed.</p>
    <?php if ($canReset): ?>
      <div class="alert alert-warning" role="note"><?= icon('alert') ?><div>Resetting deletes <strong>all</strong> events, registrations and logs, and reloads the demo data with dates relative to now.</div></div>
      <form method="post" data-confirm="Delete everything and reload the demo data?">
        <?= csrf_field() ?>
        <button class="btn btn-danger" type="submit">Reset demo data</button>
      </form>
    <?php else: ?>
      <p class="muted">To reset the demo data, <a href="<?= h(url('staff/login.php?next=' . rawurlencode(url('setup.php')))) ?>">sign in as an organizer</a> and come back to this page.</p>
    <?php endif; ?>
    <p class="actions"><a class="btn btn-secondary" href="<?= h(url('')) ?>">Open the event list</a></p>
  <?php endif; ?>
</section>
<?php render_footer(); ?>

<?php
require_once __DIR__ . '/../config.php';
$staff = require_organizer();

$action = get_str('action', 40);
if (!isset(AUDIT_ACTIONS[$action])) {
    $action = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = $action !== '' ? 'WHERE action = ?' : '';
$params = $action !== '' ? [$action] : [];
$total = (int) db_value("SELECT COUNT(*) FROM audit_logs $where", $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$logs = db_all(
    "SELECT * FROM audit_logs $where ORDER BY id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, ($page - 1) * $perPage])
);

$pageUrl = fn (int $p) => url('staff/audit.php?' . http_build_query(array_filter(['action' => $action, 'page' => $p > 1 ? $p : null])));

render_header('Audit log', ['area' => 'staff', 'active' => 'audit']);
?>
<div class="page-head">
  <div>
    <h1>Audit log</h1>
    <p class="muted">Who did what, and when: event changes, cancellations, waitlist promotions, check-ins and undos.</p>
  </div>
  <form method="get" class="inline-form">
    <label for="action" class="sr-only">Filter by action</label>
    <select id="action" name="action" onchange="this.form.submit()">
      <option value="">All actions</option>
      <?php foreach (AUDIT_ACTIONS as $key => $label): ?>
        <option value="<?= h($key) ?>"<?= $action === $key ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-sm btn-secondary" type="submit">Filter</button></noscript>
  </form>
</div>

<section class="card card-flush">
  <?php if (!$logs): ?>
    <p class="muted pad">No entries.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table table-stack">
      <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Details</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td class="nowrap small" data-label="When"><?= h(fmt_datetime($log['created_at'])) ?></td>
            <td class="nowrap" data-label="Who"><?= h($log['actor']) ?></td>
            <td class="nowrap span-all" data-label="Action"><?= h(AUDIT_ACTIONS[$log['action']] ?? $log['action']) ?></td>
            <td class="small span-all" data-label="Details"><?= h($log['details']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($pages > 1): ?>
  <nav class="pager" aria-label="Pages">
    <?php if ($page > 1): ?><a class="btn btn-sm btn-secondary" href="<?= h($pageUrl($page - 1)) ?>">← Newer</a><?php endif; ?>
    <span class="small muted">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-sm btn-secondary" href="<?= h($pageUrl($page + 1)) ?>">Older →</a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php render_footer(['area' => 'staff']); ?>

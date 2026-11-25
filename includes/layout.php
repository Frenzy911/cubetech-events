<?php
/**
 * HTML building blocks: page header/footer, icons, badges, form fields, event cards.
 */

function icon(string $name, string $class = 'icon'): string
{
    static $paths = [
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'pin'      => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'check'    => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'x'        => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        'alert'    => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'info'     => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'camera'   => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'ticket'   => '<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><line x1="14" y1="6" x2="14" y2="8"/><line x1="14" y1="11" x2="14" y2="13"/><line x1="14" y1="16" x2="14" y2="18"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'undo'     => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
        'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'edit'     => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'mail'     => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'up'       => '<circle cx="12" cy="12" r="10"/><polyline points="16 12 12 8 8 12"/><line x1="12" y1="16" x2="12" y2="8"/>',
        'scan'     => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/>',
        'list'     => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'printer'  => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'hourglass'=> '<path d="M6 2h12"/><path d="M6 22h12"/><path d="M6 2v4a6 6 0 0 0 12 0V2"/><path d="M6 22v-4a6 6 0 0 1 12 0v4"/>',
        'menu'     => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'close'    => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
    ];
    return '<svg class="' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}

function asset(string $path): string
{
    $file = BASE_PATH . '/assets/' . $path;
    return url('assets/' . $path) . '?v=' . (is_file($file) ? filemtime($file) : '1');
}

/**
 * Page header. Options:
 *   area   => 'public' | 'staff'
 *   active => nav key to highlight
 *   narrow => true for a narrow centered column
 *   body_class => extra class on <body>
 */
function render_header(string $title, array $opt = []): void
{
    $area = $opt['area'] ?? 'public';
    $active = $opt['active'] ?? '';
    $staff = $area === 'staff' ? current_staff() : null;
    $containerClass = 'container' . (!empty($opt['narrow']) ? ' container-narrow' : '');

    if ($area === 'staff') {
        header('Cache-Control: no-store');
        $links = $staff && $staff['role'] === 'organizer'
            ? [
                'dashboard' => ['staff/index.php', 'Dashboard'],
                'events'    => ['staff/events.php', 'Events'],
                'checkin'   => ['staff/checkin.php', 'Check-in'],
                'staff'     => ['staff/staff_users.php', 'Staff'],
                'audit'     => ['staff/audit.php', 'Audit log'],
                'outbox'    => ['staff/outbox.php', 'Email outbox'],
            ]
            : ($staff ? ['checkin' => ['staff/checkin.php', 'Check-in']] : []);
    } else {
        $links = [
            'events' => ['index.php', 'Events'],
            'lookup' => ['lookup.php', 'Find my ticket'],
        ];
    }
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1d4ed8">
<title><?= h($title) ?> · <?= h(APP_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='6' fill='%231d4ed8'/%3E%3Cpath d='M6 9.5a1.5 1.5 0 0 1 1.5-1.5h9A1.5 1.5 0 0 1 18 9.5v1a1.5 1.5 0 0 0 0 3v1a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 14.5v-1a1.5 1.5 0 0 0 0-3z' fill='white'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= h(asset('css/style.css')) ?>">
</head>
<body class="area-<?= h($area) ?> <?= h($opt['body_class'] ?? '') ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="<?= h($area === 'staff' && $staff ? url(staff_home($staff)) : url('')) ?>">
      <span class="brand-mark"><?= icon('ticket') ?></span>
      <span class="brand-name"><?= h(APP_NAME) ?></span>
      <?php if ($area === 'staff'): ?><span class="brand-tag">Staff</span><?php endif; ?>
    </a>
    <?php if ($links || $staff): ?>
    <button type="button" class="nav-toggle" aria-expanded="false" aria-controls="main-nav" hidden>
      <span class="nav-toggle-open"><?= icon('menu') ?> Menu</span>
      <span class="nav-toggle-close"><?= icon('close') ?> Close</span>
    </button>
    <nav class="main-nav" id="main-nav" aria-label="Main">
      <?php foreach ($links as $key => [$href, $label]): ?>
        <a href="<?= h(url($href)) ?>"<?= $key === $active ? ' class="is-active" aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
      <?php if ($staff): ?>
        <form method="post" action="<?= h(url('staff/logout.php')) ?>" class="nav-logout">
          <?= csrf_field() ?>
          <span class="nav-user" title="Signed in as <?= h($staff['username']) ?>"><?= h($staff['name']) ?> · <?= h(role_label($staff['role'])) ?></span>
          <button type="submit" class="nav-button"><?= icon('logout') ?> Sign out</button>
        </form>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
  </div>
</header>
<main id="main" class="<?= h($containerClass) ?>">
<?php foreach (take_flashes() as $f): ?>
  <div class="alert alert-<?= h($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>">
    <?= icon($f['type'] === 'success' ? 'check' : ($f['type'] === 'error' ? 'x' : ($f['type'] === 'warning' ? 'alert' : 'info'))) ?>
    <div><?= h($f['message']) ?></div>
  </div>
<?php endforeach; ?>
<?php
}

function render_footer(array $opt = []): void
{
    $area = $opt['area'] ?? 'public';
    ?>
</main>
<footer class="site-footer">
  <div class="container footer-inner">
    <p>All times are Cebu time (Asia/Manila, UTC+8).</p>
    <?php if ($area === 'public'): ?>
      <p><a href="<?= h(url('staff/login.php')) ?>">Staff sign in</a></p>
    <?php endif; ?>
  </div>
</footer>
<script src="<?= h(asset('js/app.js')) ?>" defer></script>
<?php foreach ($opt['scripts'] ?? [] as $script): ?>
<script src="<?= h(asset($script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

function render_error_page(int $code, string $title, string $message, string $area = 'public'): never
{
    http_response_code($code);
    render_header($title, ['area' => $area, 'narrow' => true]);
    ?>
    <section class="card empty-state">
      <div class="empty-icon"><?= icon($code === 403 ? 'alert' : 'info') ?></div>
      <h1><?= h($title) ?></h1>
      <p class="muted"><?= h($message) ?></p>
      <p><a class="btn btn-primary" href="<?= h($area === 'staff' ? url('staff/') : url('')) ?>"><?= $area === 'staff' ? 'Back to staff home' : 'See upcoming events' ?></a></p>
    </section>
    <?php
    render_footer(['area' => $area]);
    exit;
}

/* ---------- Badges ---------- */

function badge(string $text, string $tone, string $iconName = ''): string
{
    return '<span class="badge badge-' . h($tone) . '">' . ($iconName ? icon($iconName) : '') . h($text) . '</span>';
}

function status_badge(string $status, ?int $waitPosition = null): string
{
    switch ($status) {
        case 'confirmed':
            return badge('Confirmed', 'blue', 'ticket');
        case 'checked_in':
            return badge('Checked in', 'green', 'check');
        case 'waitlisted':
            return badge('Waitlisted' . ($waitPosition ? ' #' . $waitPosition : ''), 'yellow', 'hourglass');
        case 'cancelled':
            return badge('Cancelled', 'gray', 'x');
    }
    return badge(ucfirst($status), 'gray');
}

function event_status_badge(string $status): string
{
    return [
        'draft'     => badge('Draft', 'gray', 'edit'),
        'published' => badge('Published', 'green', 'check'),
        'closed'    => badge('Closed', 'red', 'x'),
    ][$status] ?? badge($status, 'gray');
}

/** Badges an attendee sees on an event: Ended / Happening now / Full. */
function event_public_badges(array $e): string
{
    $out = '';
    if (event_is_ended($e)) {
        return badge('Ended', 'gray', 'clock');
    }
    if (event_is_started($e)) {
        $out .= badge('Happening now', 'green', 'clock');
    }
    if (event_is_full($e)) {
        $out .= badge('Full', 'red', 'users');
    }
    return $out;
}

/* ---------- Forms ---------- */

/**
 * Form field with label, optional hint and an error message slot next to the input.
 * $attrs: any HTML attributes; 'type' => 'textarea' | 'select' (with 'options' => [value => label]).
 */
function field(string $name, string $label, string $value, ?string $error = null, array $attrs = [], string $hint = ''): string
{
    $id = 'f-' . $name;
    $type = $attrs['type'] ?? 'text';
    $options = $attrs['options'] ?? [];
    unset($attrs['options']);
    if ($type === 'textarea' || $type === 'select') {
        unset($attrs['type']);
    } else {
        $attrs['type'] = $type;
    }

    $describedBy = trim(($hint ? $id . '-hint ' : '') . $id . '-error');
    $attrHtml = '';
    foreach ($attrs as $k => $v) {
        if ($v === true) {
            $attrHtml .= ' ' . h($k);
        } elseif ($v !== false && $v !== null) {
            $attrHtml .= ' ' . h($k) . '="' . h($v) . '"';
        }
    }
    $common = 'id="' . $id . '" name="' . h($name) . '"' . $attrHtml . ' aria-describedby="' . $describedBy . '"'
            . ($error ? ' aria-invalid="true"' : '');

    if ($type === 'textarea') {
        $input = '<textarea ' . $common . '>' . h($value) . '</textarea>';
    } elseif ($type === 'select') {
        $input = '<select ' . $common . '>';
        foreach ($options as $optValue => $optLabel) {
            $input .= '<option value="' . h($optValue) . '"' . ((string) $optValue === $value ? ' selected' : '') . '>' . h($optLabel) . '</option>';
        }
        $input .= '</select>';
    } else {
        $input = '<input ' . $common . ' value="' . h($value) . '">';
    }

    return '<div class="field' . ($error ? ' has-error' : '') . '">'
         . '<label for="' . $id . '">' . h($label) . '</label>'
         . ($hint ? '<p class="hint" id="' . $id . '-hint">' . h($hint) . '</p>' : '')
         . $input
         . '<p class="field-error" id="' . $id . '-error"' . ($error ? '' : ' hidden') . '>' . icon('alert') . '<span>' . h($error ?? '') . '</span></p>'
         . '</div>';
}

/* ---------- Event card (attendee list) ---------- */

function event_card(array $e): string
{
    $ended = event_is_ended($e);
    $left = event_spots_left($e);
    $start = strtotime($e['starts_at']);
    $href = url('event.php?id=' . (int) $e['id']);
    ob_start();
    ?>
    <article class="event-card<?= $ended ? ' is-ended' : '' ?>">
      <div class="event-card-top">
        <div class="date-chip" aria-hidden="true">
          <span class="date-chip-month"><?= date('M', $start) ?></span>
          <span class="date-chip-day"><?= date('j', $start) ?></span>
          <span class="date-chip-dow"><?= date('D', $start) ?></span>
        </div>
        <div class="event-card-heading">
          <div class="badges"><?= event_public_badges($e) ?></div>
          <h3 class="event-card-title"><a href="<?= h($href) ?>" class="stretched-link"><?= h($e['title']) ?></a></h3>
        </div>
      </div>
      <p class="sr-only"><?= h(fmt_date($e['starts_at'])) ?></p>
      <p class="meta"><?= icon('clock') ?><span><?= h(event_time_range($e)) ?></span></p>
      <p class="meta"><?= icon('pin') ?><span><?= h($e['venue']) ?></span></p>
      <div class="event-card-foot">
        <?php if (!$ended): ?>
          <?php $tone = $left <= 0 ? 'is-full' : ($left <= 5 ? 'is-low' : ''); ?>
          <div class="event-card-spots">
            <p class="spots <?= $tone ?>">
              <?= icon('users') ?>
              <span><?= $left <= 0 ? '<strong>Full</strong> · waitlist open' : h($left . ' of ' . $e['capacity'] . ' spots left') ?></span>
            </p>
            <div class="progress progress-sm <?= $tone ?>" aria-hidden="true"><span style="width:<?= (int) min(100, round(((int) $e['taken']) / max(1, (int) $e['capacity']) * 100)) ?>%"></span></div>
          </div>
        <?php else: ?>
          <p class="spots is-ended"><?= icon('clock') ?><span>This event has ended</span></p>
        <?php endif; ?>
        <span class="event-card-more" aria-hidden="true"><?= $ended ? 'Details' : ($left <= 0 ? 'Waitlist' : 'Register') ?> <?= icon('chevron-right') ?></span>
      </div>
    </article>
    <?php
    return ob_get_clean();
}

/** Small "x / y" progress bar. */
function progress_bar(int $value, int $max, string $label): string
{
    $pct = $max > 0 ? min(100, round($value / $max * 100)) : 0;
    return '<div class="progress" role="progressbar" aria-label="' . h($label) . '" aria-valuemin="0" aria-valuemax="' . $max
         . '" aria-valuenow="' . $value . '"><span style="width:' . $pct . '%"></span></div>';
}

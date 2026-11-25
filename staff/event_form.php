<?php
require_once __DIR__ . '/../config.php';
$staff = require_organizer();

$id = (int) ($_GET['id'] ?? 0);
$event = $id ? get_event($id) : null;
if ($id && !$event) {
    render_error_page(404, 'Event not found', 'This event does not exist.', 'staff');
}
$isEdit = (bool) $event;
$taken = $isEdit ? (int) $event['taken'] : 0;

$statuses = ['draft' => 'Draft (hidden from attendees)', 'published' => 'Published (visible, open for registration)', 'closed' => 'Closed (no new registrations)'];

if ($isEdit) {
    $v = [
        'title'       => $event['title'],
        'description' => $event['description'],
        'venue'       => $event['venue'],
        'start_date'  => date('Y-m-d', strtotime($event['starts_at'])),
        'start_time'  => date('H:i', strtotime($event['starts_at'])),
        'end_date'    => date('Y-m-d', strtotime($event['ends_at'])),
        'end_time'    => date('H:i', strtotime($event['ends_at'])),
        'capacity'    => (string) $event['capacity'],
        'status'      => $event['status'],
    ];
} else {
    $v = [
        'title' => '', 'description' => '', 'venue' => '',
        'start_date' => date('Y-m-d', strtotime('+7 days')), 'start_time' => '18:00',
        'end_date' => date('Y-m-d', strtotime('+7 days')), 'end_time' => '21:00',
        'capacity' => '30', 'status' => 'draft',
    ];
}
$errors = [];

if (is_post()) {
    csrf_verify();
    foreach (array_keys($v) as $key) {
        $v[$key] = post_str($key, $key === 'description' ? 5000 : 200);
    }
    $v['title'] = preg_replace('/\s+/', ' ', $v['title']);
    $v['venue'] = preg_replace('/\s+/', ' ', $v['venue']);
    if ($v['end_date'] === '') {
        $v['end_date'] = $v['start_date'];
    }

    // ---- Validation ----
    if ($v['title'] === '') {
        $errors['title'] = 'Enter a title.';
    } elseif (mb_strlen($v['title']) > 150) {
        $errors['title'] = 'Title must be 150 characters or less.';
    }
    if ($v['description'] === '') {
        $errors['description'] = 'Enter a short description.';
    }
    if ($v['venue'] === '') {
        $errors['venue'] = 'Enter the venue.';
    } elseif (mb_strlen($v['venue']) > 200) {
        $errors['venue'] = 'Venue must be 200 characters or less.';
    }

    $start = DateTime::createFromFormat('!Y-m-d H:i', $v['start_date'] . ' ' . $v['start_time']);
    $end = DateTime::createFromFormat('!Y-m-d H:i', $v['end_date'] . ' ' . $v['end_time']);
    if (!$start || $start->format('Y-m-d H:i') !== $v['start_date'] . ' ' . $v['start_time']) {
        $errors['start_date'] = 'Enter a valid start date and time.';
    }
    if (!$end || $end->format('Y-m-d H:i') !== $v['end_date'] . ' ' . $v['end_time']) {
        $errors['end_time'] = 'Enter a valid end date and time.';
    }
    if ($start && $end && !isset($errors['start_date']) && !isset($errors['end_time']) && $end <= $start) {
        $errors['end_time'] = 'End time must be after the start time.';
    }

    if (!ctype_digit($v['capacity']) || (int) $v['capacity'] < 1) {
        $errors['capacity'] = 'Capacity must be a whole number of at least 1.';
    } elseif ((int) $v['capacity'] > 100000) {
        $errors['capacity'] = 'Capacity looks too large.';
    }
    if (!isset($statuses[$v['status']])) {
        $errors['status'] = 'Choose a status.';
    }

    if (!$errors) {
        $startsAt = $start->format('Y-m-d H:i:s');
        $endsAt = $end->format('Y-m-d H:i:s');
        $capacity = (int) $v['capacity'];

        if (!$isEdit) {
            $newId = db_insert(
                'INSERT INTO events (title, description, venue, starts_at, ends_at, capacity, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$v['title'], $v['description'], $v['venue'], $startsAt, $endsAt, $capacity, $v['status'], (int) $staff['id']]
            );
            audit('event_created', 'event', $newId, sprintf('Created "%s" (%s, capacity %d, %s)', $v['title'], $v['status'], $capacity, fmt_datetime($startsAt)), $staff);
            flash('success', 'Event "' . $v['title'] . '" created.');
            redirect('staff/events.php');
        }

        // Edit: lock the event row so capacity can't drop below registrations made at this same moment.
        db()->begin_transaction();
        try {
            $locked = db_one('SELECT * FROM events WHERE id = ? FOR UPDATE', [$id]);
            $takenNow = (int) db_value("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status IN ('confirmed','checked_in')", [$id]);
            if ($capacity < $takenNow) {
                db()->rollback();
                $taken = $takenNow;
                $errors['capacity'] = "Capacity can't be lower than {$takenNow}: that many people are already registered (confirmed or checked in). Cancel registrations first if you need fewer spots.";
            } else {
                db_exec(
                    'UPDATE events SET title = ?, description = ?, venue = ?, starts_at = ?, ends_at = ?, capacity = ?, status = ?, updated_at = NOW() WHERE id = ?',
                    [$v['title'], $v['description'], $v['venue'], $startsAt, $endsAt, $capacity, $v['status'], $id]
                );
                db()->commit();

                $changes = [];
                $compare = [
                    'title'     => [$locked['title'], $v['title']],
                    'venue'     => [$locked['venue'], $v['venue']],
                    'starts'    => [fmt_datetime($locked['starts_at']), fmt_datetime($startsAt)],
                    'ends'      => [fmt_datetime($locked['ends_at']), fmt_datetime($endsAt)],
                    'capacity'  => [(string) $locked['capacity'], (string) $capacity],
                    'status'    => [$locked['status'], $v['status']],
                ];
                foreach ($compare as $label => [$old, $new]) {
                    if ($old !== $new) {
                        $changes[] = "$label: $old → $new";
                    }
                }
                if ($locked['description'] !== $v['description']) {
                    $changes[] = 'description updated';
                }
                audit('event_updated', 'event', $id, sprintf('Edited "%s": %s', $v['title'], $changes ? implode('; ', $changes) : 'no changes'), $staff);
                flash('success', 'Event "' . $v['title'] . '" saved.');
                redirect('staff/events.php');
            }
        } catch (Throwable $e) {
            try {
                db()->rollback();
            } catch (Throwable $ignored) {
            }
            throw $e;
        }
    }
}

render_header($isEdit ? 'Edit event' : 'New event', ['area' => 'staff', 'active' => 'events', 'narrow' => true]);
?>
<a class="back-link" href="<?= h(url('staff/events.php')) ?>"><?= icon('arrow-left') ?> Events</a>

<section class="card">
  <h1 class="h2"><?= $isEdit ? 'Edit event' : 'New event' ?></h1>
  <?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><?= icon('x') ?><div>Please fix the highlighted <?= count($errors) === 1 ? 'field' : 'fields' ?>.</div></div>
  <?php endif; ?>

  <form method="post" class="form" novalidate>
    <?= csrf_field() ?>
    <?= field('title', 'Title', $v['title'], $errors['title'] ?? null, ['required' => true, 'maxlength' => 150]) ?>
    <?= field('description', 'Description', $v['description'], $errors['description'] ?? null, ['type' => 'textarea', 'rows' => 4, 'required' => true]) ?>
    <?= field('venue', 'Venue', $v['venue'], $errors['venue'] ?? null, ['required' => true, 'maxlength' => 200]) ?>

    <fieldset class="fieldset">
      <legend>Date and time <span class="muted">(Cebu time)</span></legend>
      <div class="grid-2">
        <?= field('start_date', 'Start date', $v['start_date'], $errors['start_date'] ?? null, ['type' => 'date', 'required' => true]) ?>
        <?= field('start_time', 'Start time', $v['start_time'], null, ['type' => 'time', 'required' => true]) ?>
        <?= field('end_date', 'End date', $v['end_date'], null, ['type' => 'date', 'required' => true], 'Usually the same day.') ?>
        <?= field('end_time', 'End time', $v['end_time'], $errors['end_time'] ?? null, ['type' => 'time', 'required' => true]) ?>
      </div>
    </fieldset>

    <div class="grid-2">
      <?= field('capacity', 'Capacity', $v['capacity'], $errors['capacity'] ?? null, [
          'type' => 'number', 'min' => max(1, $taken), 'step' => 1, 'required' => true, 'inputmode' => 'numeric',
      ], $isEdit ? "Currently registered: {$taken} (can't go below this)" : 'At least 1.') ?>
      <?= field('status', 'Status', $v['status'], $errors['status'] ?? null, ['type' => 'select', 'options' => $statuses]) ?>
    </div>

    <div class="actions">
      <button class="btn btn-primary btn-lg" type="submit"><?= $isEdit ? 'Save changes' : 'Create event' ?></button>
      <a class="btn btn-ghost" href="<?= h(url('staff/events.php')) ?>">Cancel</a>
    </div>
  </form>
</section>
<?php render_footer(['area' => 'staff']); ?>

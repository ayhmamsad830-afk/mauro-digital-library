<?php
/** Analytics from real reading_sessions rows. SVG bars use attributes only (strict CSP: no inline styles). @var array $report */
$days = $report['days'];
$max = max(1, ...array_map(static fn ($d) => $d['total'], $days));
$n = count($days);
$W = 720; $H = 200; $gap = 2; $bw = ($W - ($n - 1) * $gap) / $n;
$t = $report['totals'];
$devTotal = max(1, array_sum(array_column($report['devices'], 'n')));
?>
<div class="admin-toolbar">
    <nav class="tabs" aria-label="<?= e(__('admin.period')) ?>">
        <?php foreach ([7, 30, 90] as $d): ?>
            <a href="<?= e(route('admin.analytics', [], ['days' => $d])) ?>"<?= $report['days_count'] === $d ? ' aria-current="page"' : '' ?>><?= e(__('admin.last_days', ['n' => $d])) ?></a>
        <?php endforeach; ?>
    </nav>
</div>

<ul class="stat-grid stat-grid--compact" role="list">
    <?php foreach ([['chart', 'an_sessions', $t['sessions']], ['users', 'an_members', $t['members']], ['user', 'an_guests', $t['guests']], ['book', 'an_pages', $t['pages']], ['users', 'an_new_users', $t['new_users']], ['star', 'an_reviews', $t['reviews']]] as [$ic, $k, $v]): ?>
    <li class="card stat"><span class="stat__label"><?= icon($ic, 'icon--sm') ?><?= e(__('admin.' . $k)) ?></span><span class="stat__value"><?= e(format_number((int) $v)) ?></span></li>
    <?php endforeach; ?>
</ul>

<section class="admin-section card" aria-labelledby="an-daily">
    <div class="admin-section__head"><h2 id="an-daily" class="h4"><?= e(__('admin.an_daily')) ?></h2>
        <span class="chart-legend xsmall"><span class="chart-key chart-key--total"></span><?= e(__('admin.an_all')) ?> <span class="chart-key chart-key--members"></span><?= e(__('admin.an_members')) ?></span></div>
    <?php if ($t['sessions'] > 0): ?>
    <figure class="chart" dir="ltr">
        <svg class="chart__svg" viewBox="0 0 <?= $W ?> <?= $H + 1 ?>" role="img" aria-labelledby="an-daily" preserveAspectRatio="none">
            <?php foreach ($days as $i => $d): $x = round($i * ($bw + $gap), 2); $h = round($d['total'] / $max * $H, 2); $hm = round($d['members'] / $max * $H, 2); ?>
            <g><title><?= e($d['date'] . ': ' . $d['total'] . ' (' . __('admin.an_members') . ' ' . $d['members'] . ')') ?></title>
                <rect class="chart__bar" x="<?= $x ?>" y="<?= $H - $h ?>" width="<?= round($bw, 2) ?>" height="<?= max(0, $h) ?>"></rect>
                <rect class="chart__bar chart__bar--members" x="<?= $x ?>" y="<?= $H - $hm ?>" width="<?= round($bw, 2) ?>" height="<?= max(0, $hm) ?>"></rect>
            </g>
            <?php endforeach; ?>
            <line class="chart__axis" x1="0" y1="<?= $H ?>" x2="<?= $W ?>" y2="<?= $H ?>"></line>
        </svg>
        <div class="chart__range xsmall muted"><span><?= e($days[0]['date']) ?></span><span><?= e($days[$n - 1]['date']) ?></span></div>
        <figcaption class="muted xsmall"><?= e(__('admin.an_peak', ['n' => format_number($max)])) ?></figcaption>
    </figure>
    <details class="chart-table"><summary class="small"><?= e(__('admin.an_table')) ?></summary>
        <div class="table-wrap table-wrap--flush"><table class="table"><thead><tr><th scope="col"><?= e(__('admin.an_date')) ?></th><th scope="col"><?= e(__('admin.an_all')) ?></th><th scope="col"><?= e(__('admin.an_members')) ?></th></tr></thead><tbody>
        <?php foreach (array_reverse($days) as $d): if ($d['total'] === 0) { continue; } ?><tr><td class="ltr"><?= e($d['date']) ?></td><td><?= e($d['total']) ?></td><td><?= e($d['members']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </details>
    <?php else: ?>
        <p class="muted small"><?= e(__('admin.an_empty')) ?></p>
    <?php endif; ?>
</section>

<div class="admin-grid admin-grid--even">
    <section class="admin-section card" aria-labelledby="an-top">
        <div class="admin-section__head"><h2 id="an-top" class="h4"><?= e(__('admin.an_top_books')) ?></h2></div>
        <?php if ($report['topBooks']): ?>
        <ol class="rank-list">
            <?php foreach ($report['topBooks'] as $b): ?>
            <li><a href="<?= e(route('admin.books.edit', ['id' => $b['id']])) ?>" dir="auto"><?= e($b['title']) ?></a><span class="muted xsmall"><?= e(__('admin.an_book_line', ['sessions' => format_number($b['sessions']), 'pages' => format_number($b['pages'])])) ?></span></li>
            <?php endforeach; ?>
        </ol>
        <?php else: ?><p class="muted small"><?= e(__('admin.an_empty')) ?></p><?php endif; ?>
    </section>
    <section class="admin-section card" aria-labelledby="an-dev">
        <div class="admin-section__head"><h2 id="an-dev" class="h4"><?= e(__('admin.an_devices')) ?></h2></div>
        <?php if ($report['devices']): ?>
        <ul class="meter-list" role="list">
            <?php foreach ($report['devices'] as $d): $pct = round($d['n'] / $devTotal * 100); ?>
            <li><span class="meter-list__label"><?= e(__('admin.device_' . (in_array($d['device'], ['mobile', 'tablet', 'desktop'], true) ? $d['device'] : 'unknown'))) ?></span>
                <progress class="meter" max="100" value="<?= e($pct) ?>"><?= e($pct) ?>%</progress><span class="xsmall ltr"><?= e($pct) ?>% · <?= e(format_number($d['n'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?><p class="muted small"><?= e(__('admin.an_empty')) ?></p><?php endif; ?>
    </section>
</div>

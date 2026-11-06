<?php
/** DESIGN PREVIEW (demo). @var array $user @var list<array> $history @var list<array> $favorites @var list<array> $bookmarks */
?>
<div class="page-head page-head--account">
    <div class="container account-head">
        <span class="avatar avatar--lg" aria-hidden="true"><?= e(mb_substr($user['name'], 0, 1)) ?></span>
        <div>
            <p class="eyebrow"><?= e(__('account.welcome')) ?></p>
            <h1 class="page-head__title"><?= e($user['name']) ?></h1>
            <p class="muted small ltr"><?= e($user['email']) ?></p>
        </div>
    </div>
</div>

<div class="container">
    <?= component('alert', ['type' => 'warning', 'title' => __('demo.label'), 'text' => __('account.demo_notice')]) ?>

    <nav class="tabs account-tabs" aria-label="<?= e(__('account.sections')) ?>">
        <a href="#history" aria-current="true"><?= icon('clock', 'icon--sm') ?><?= e(__('account.history')) ?></a>
        <a href="#favorites"><?= icon('heart', 'icon--sm') ?><?= e(__('account.favorites')) ?></a>
        <a href="#bookmarks"><?= icon('bookmark', 'icon--sm') ?><?= e(__('account.bookmarks')) ?></a>
        <a href="#settings"><?= icon('settings', 'icon--sm') ?><?= e(__('account.settings')) ?></a>
    </nav>

    <div class="account-grid">
        <section id="history" class="card card--pad account-section" aria-labelledby="history-title">
            <h2 id="history-title" class="h3"><?= e(__('account.continue')) ?></h2>
            <?php foreach ($history as $h): ?>
                <?= component('book-row', ['book' => $h['book'], 'page' => $h['page'], 'percent' => $h['percent'],
                    'actions' => '<a class="btn btn--primary btn--sm" href="' . e(route('reader.show', ['slug' => $h['book']['slug']])) . '">' . e(__('account.resume')) . '</a>']) ?>
            <?php endforeach; ?>
        </section>

        <section id="favorites" class="card card--pad account-section" aria-labelledby="fav-title">
            <h2 id="fav-title" class="h3"><?= e(__('account.favorites')) ?></h2>
            <?= component('book-grid', ['books' => $favorites]) ?>
        </section>

        <section id="bookmarks" class="card card--pad account-section" aria-labelledby="bm-title">
            <h2 id="bm-title" class="h3"><?= e(__('account.bookmarks')) ?></h2>
            <?php foreach ($bookmarks as $bm): ?>
                <?= component('book-row', ['book' => $bm['book'],
                    'note' => __('account.bookmark_page', ['page' => $bm['page']]) . ($bm['title'] ? ' — ' . $bm['title'] : '') . ($bm['note'] ? ' · ' . $bm['note'] : ''),
                    'actions' => '<a class="btn btn--secondary btn--sm" href="' . e(route('reader.show', ['slug' => $bm['book']['slug']])) . '#page=' . (int) $bm['page'] . '">' . e(__('account.open_page')) . '</a>']) ?>
            <?php endforeach; ?>
        </section>

        <section id="settings" class="card card--pad account-section" aria-labelledby="settings-title">
            <h2 id="settings-title" class="h3"><?= e(__('account.settings')) ?></h2>
            <dl class="meta-list">
                <dt><?= e(__('pref.language')) ?></dt><dd><?= component('lang-switch') ?></dd>
                <dt><?= e(__('pref.theme')) ?></dt><dd><?= component('theme-toggle') ?></dd>
                <dt><?= e(__('account.member_since')) ?></dt><dd><?= e(format_date($user['created_at'])) ?></dd>
            </dl>
            <hr>
            <button type="button" class="btn btn--ghost" disabled aria-describedby="logout-hint"><?= icon('logout', 'icon--sm') ?><?= e(__('account.sign_out')) ?></button>
            <p id="logout-hint" class="help"><?= e(__('account.sign_out_phase5')) ?></p>
        </section>
    </div>
</div>

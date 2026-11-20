<?php
/**
 * Real member area. @var array $user @var string $section @var list<array> $history @var list<array> $favorites
 * @var int $favoritesCount @var list<array> $bookmarks @var int $bookmarksCount
 * (header + tabs: account/_head.php, shared with the settings page)
 */
$show = static fn (string $s) => $section === 'overview' || $section === $s;
?>
<?= \App\Core\View::partial('account/_head', get_defined_vars()) ?>

<div class="container">
    <div class="sync-prompt" data-sync-prompt data-done="<?= e(__('member.sync_done')) ?>" data-endpoint="<?= e(route('api.progress.import')) ?>" hidden>
        <?= component('alert', ['type' => 'info', 'title' => __('member.sync_title'), 'text' => __('member.sync_text')]) ?>
        <div class="btn-group">
            <button type="button" class="btn btn--primary btn--sm" data-sync-accept><?= e(__('member.sync_accept')) ?></button>
            <button type="button" class="btn btn--ghost btn--sm" data-sync-decline><?= e(__('member.sync_decline')) ?></button>
        </div>
        <p class="small muted" data-sync-result role="status"></p>
    </div>


    <div class="account-grid">
        <?php if ($show('history')): ?>
        <section class="card card--pad account-section" aria-labelledby="history-title">
            <div class="section-head"><h2 id="history-title" class="h3"><?= e($section === 'history' ? __('account.history') : __('account.continue')) ?></h2>
                <?php if ($section === 'overview' && $history): ?><a class="section-head__link" href="<?= e(route('account.history')) ?>"><?= e(__('common.view_all')) ?></a><?php endif; ?></div>
            <?php if ($history): foreach ($history as $h): ?>
                <?= component('book-row', ['book' => $h['book'], 'page' => $h['page'], 'percent' => (int) round($h['percent']),
                    'note' => __('member.last_read', ['date' => format_date($h['last_read_at'])]),
                    'actions' => '<a class="btn btn--primary btn--sm" href="' . e(route('reader.show', ['slug' => $h['book']['slug']])) . '">' . e(__('account.resume')) . '</a>']) ?>
            <?php endforeach; else: ?>
                <?= component('empty-state', ['icon' => 'clock', 'title' => __('member.no_history'), 'text' => __('member.no_history_text'), 'actionUrl' => route('books.index'), 'actionLabel' => __('home.explore')]) ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($show('favorites')): ?>
        <section class="card card--pad account-section" aria-labelledby="fav-title">
            <div class="section-head"><h2 id="fav-title" class="h3"><?= e(__('account.favorites')) ?></h2>
                <?php if ($section === 'overview' && $favoritesCount > count($favorites)): ?><a class="section-head__link" href="<?= e(route('account.favorites')) ?>"><?= e(__('common.view_all')) ?></a><?php endif; ?></div>
            <?php if ($favorites): ?>
                <?= component('book-grid', ['books' => $favorites]) ?>
            <?php else: ?>
                <?= component('empty-state', ['icon' => 'heart', 'title' => __('member.no_favorites'), 'text' => __('member.no_favorites_text')]) ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($show('bookmarks')): ?>
        <section class="card card--pad account-section" aria-labelledby="bm-title">
            <div class="section-head"><h2 id="bm-title" class="h3"><?= e(__('account.bookmarks')) ?></h2>
                <?php if ($section === 'overview' && $bookmarksCount > count($bookmarks)): ?><a class="section-head__link" href="<?= e(route('account.bookmarks')) ?>"><?= e(__('common.view_all')) ?></a><?php endif; ?></div>
            <?php if ($bookmarks): foreach ($bookmarks as $bm): ?>
                <?= component('book-row', ['book' => $bm['book'],
                    'note' => __('account.bookmark_page', ['page' => $bm['page']]) . ($bm['title'] ? ' — ' . $bm['title'] : '') . ($bm['note'] ? ' · ' . $bm['note'] : ''),
                    'actions' => '<a class="btn btn--secondary btn--sm" href="' . e(route('reader.show', ['slug' => $bm['book']['slug']])) . '#page=' . (int) $bm['page'] . '">' . e(__('account.open_page')) . '</a>'
                        . '<form method="post" action="' . e(route('member.bookmark.delete', ['id' => $bm['id']])) . '">' . csrf_field()
                        . '<input type="hidden" name="redirect" value="' . e($currentUri ?? '/account') . '">'
                        . '<button class="btn btn--ghost btn--sm" type="submit" aria-label="' . e(__('member.delete_bookmark')) . '">' . icon('close', 'icon--sm') . '</button></form>']) ?>
            <?php endforeach; else: ?>
                <?= component('empty-state', ['icon' => 'bookmark', 'title' => __('member.no_bookmarks'), 'text' => __('member.no_bookmarks_text')]) ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($section === 'overview'): ?>
        <section id="settings" class="card card--pad account-section" aria-labelledby="settings-title">
            <h2 id="settings-title" class="h3"><?= e(__('account.settings')) ?></h2>
            <dl class="meta-list">
                <dt><?= e(__('pref.language')) ?></dt><dd><?= component('lang-switch') ?></dd>
                <dt><?= e(__('pref.theme')) ?></dt><dd><?= component('theme-toggle') ?></dd>
                <dt><?= e(__('account.member_since')) ?></dt><dd><?= e(format_date((string) $user['created_at'])) ?></dd>
            </dl>
            <hr>
            <form method="post" action="<?= e(route('logout')) ?>"><?= csrf_field() ?>
                <button type="submit" class="btn btn--ghost"><?= icon('logout', 'icon--sm') ?><?= e(__('account.sign_out')) ?></button>
            </form>
        </section>
        <?php endif; ?>
    </div>
</div>

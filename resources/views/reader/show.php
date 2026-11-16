<?php
/**
 * Protected reader. Receives only page-image URL templates in $manifest;
 * no PDF URL, no storage path, no text layer ever reaches this page.
 * @var array $book @var array $manifest
 */
$bookDir = $book['language'] === 'ar' ? 'rtl' : 'ltr';
$manifest['dir'] = $bookDir;
$manifest['i18n'] = [
    'pageAlt' => __('reader.page_alt', ['title' => $book['title']]),
    'loadError' => __('reader.load_error'),
    'versionChanged' => __('reader.version_changed'),
    'resumed' => __('reader.resumed'),
    'copyBlocked' => __('reader.copy_blocked'),
    'thumbLabel' => __('reader.thumb_label'),
    'bookmarkSaved' => __('member.bookmark_added'),
    'captureBlocked' => __('reader.capture_blocked'),
    'lockedThumb' => __('reader.locked_thumb'),
];
$previewPages = $manifest['previewPages'] ?? null;
?>
<div class="reader" data-reader data-manifest="<?= e(json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <header class="reader-bar reader-bar--top" data-reader-toolbar>
        <div class="reader-bar__group">
            <a class="btn btn--plain btn--icon btn--sm" href="<?= e(route('books.show', ['slug' => $book['slug']])) ?>" aria-label="<?= e(__('reader.back')) ?>" title="<?= e(__('reader.back')) ?>"><?= icon('arrow-prev', 'icon--dir') ?></a>
            <button type="button" class="btn btn--plain btn--icon btn--sm" data-action="thumbs" aria-controls="reader-thumbs" aria-expanded="false" aria-label="<?= e(__('reader.thumbnails')) ?>" title="<?= e(__('reader.thumbnails')) ?>"><?= icon('grid') ?></button>
            <?php if (!empty($manifest['demo'])): ?><span class="badge badge--warning"><?= e(__('demo.short')) ?></span><?php endif; ?>
            <?php if ($previewPages !== null): ?>
                <a class="badge badge--solid reader-preview-badge hide-sm" href="<?= e($manifest['registerUrl']) ?>"><?= icon('lock', 'icon--sm') ?><?= e(__('reader.preview_badge', ['n' => $previewPages])) ?></a>
            <?php endif; ?>
        </div>
        <div class="reader-bar__center">
            <h1 class="reader-bar__title" lang="<?= e($book['language']) ?>" dir="auto"><?= e($book['title']) ?></h1>
            <progress class="progress reader-progress" max="<?= e($manifest['pageCount']) ?>" value="1" data-progress aria-label="<?= e(__('reader.progress')) ?>"></progress>
        </div>
        <div class="reader-bar__group reader-bar__group--tools">
            <button type="button" class="btn btn--plain btn--icon btn--sm" data-action="zoom-out" aria-label="<?= e(__('reader.zoom_out')) ?>" title="<?= e(__('reader.zoom_out')) ?>"><?= icon('zoom-out') ?></button>
            <output class="reader-zoom" data-zoom-label aria-live="polite">100%</output>
            <button type="button" class="btn btn--plain btn--icon btn--sm" data-action="zoom-in" aria-label="<?= e(__('reader.zoom_in')) ?>" title="<?= e(__('reader.zoom_in')) ?>"><?= icon('zoom-in') ?></button>
            <span class="reader-bar__sep" aria-hidden="true"></span>
            <button type="button" class="btn btn--plain btn--icon btn--sm hide-sm" data-action="fit-width" aria-pressed="true" aria-label="<?= e(__('reader.fit_width')) ?>" title="<?= e(__('reader.fit_width')) ?>"><?= icon('fit-width') ?></button>
            <button type="button" class="btn btn--plain btn--icon btn--sm hide-sm" data-action="fit-page" aria-pressed="false" aria-label="<?= e(__('reader.fit_page')) ?>" title="<?= e(__('reader.fit_page')) ?>"><?= icon('fit-page') ?></button>
            <button type="button" class="btn btn--plain btn--icon btn--sm" data-action="fullscreen" aria-label="<?= e(__('reader.fullscreen')) ?>" title="<?= e(__('reader.fullscreen')) ?>"><?= icon('fullscreen') ?></button>
            <?php if (!empty($manifest['bookmarkUrl'])): ?>
                <button type="button" class="btn btn--plain btn--icon btn--sm" data-action="bookmark" aria-label="<?= e(__('reader.bookmark_add')) ?>" title="<?= e(__('reader.bookmark_add')) ?>"><?= icon('bookmark') ?></button>
            <?php else: ?>
                <a class="btn btn--plain btn--icon btn--sm hide-sm" href="<?= e(route('login', [], ['redirect' => route('reader.show', ['slug' => $book['slug']])])) ?>" aria-label="<?= e(__('reader.bookmark_login')) ?>" title="<?= e(__('reader.bookmark_login')) ?>"><?= icon('bookmark') ?></a>
            <?php endif; ?>
            <?= component('theme-toggle') ?>
            <button type="button" class="btn btn--plain btn--icon btn--sm" data-action="info" aria-controls="reader-info" aria-expanded="false" aria-label="<?= e(__('reader.info')) ?>" title="<?= e(__('reader.info')) ?>"><?= icon('info') ?></button>
        </div>
    </header>

    <div class="reader-main">
        <nav class="reader-thumbs" id="reader-thumbs" aria-label="<?= e(__('reader.thumbnails')) ?>" data-thumbs hidden>
            <ol class="reader-thumbs__list" data-thumbs-list></ol>
        </nav>

        <!-- Copy deterrence applies to this region only (spec §7.2, FR-028). -->
        <div class="reader-stage" id="reader-stage" tabindex="0" data-stage aria-label="<?= e(__('reader.stage_label')) ?>" dir="<?= e($bookDir) ?>">
            <div class="reader-page" data-page-wrap>
                <img class="reader-page__img" data-page-img alt="" draggable="false" decoding="async">
                <div class="reader-page__state" data-page-loading role="status">
                    <span class="spinner"></span><span class="visually-hidden"><?= e(__('common.loading')) ?></span>
                </div>
                <div class="reader-page__state reader-page__state--error" data-page-error hidden role="alert">
                    <p><?= e(__('reader.load_error')) ?></p>
                    <button type="button" class="btn btn--secondary btn--sm" data-action="retry"><?= e(__('common.retry')) ?></button>
                </div>
            </div>
        </div>

        <?php if ($previewPages !== null): ?>
        <!-- Free-preview wall for guests (the server refuses later pages as well). -->
        <div class="reader-gate" data-gate hidden>
            <section class="reader-gate__card card" role="dialog" aria-modal="false" aria-labelledby="reader-gate-title">
                <span class="reader-gate__mark"><?= icon('book', 'icon--lg') ?></span>
                <h2 class="reader-gate__title" id="reader-gate-title"><?= e(__('reader.gate_title')) ?></h2>
                <p class="reader-gate__text"><?= e(__('reader.gate_text', ['n' => $previewPages])) ?></p>
                <ul class="reader-gate__perks" role="list">
                    <li><?= icon('check', 'icon--sm') ?><?= e(__('reader.gate_free')) ?></li>
                    <li><?= icon('check', 'icon--sm') ?><?= e(__('reader.gate_fast')) ?></li>
                    <li><?= icon('check', 'icon--sm') ?><?= e(__('reader.gate_sync')) ?></li>
                </ul>
                <a class="btn btn--primary btn--lg btn--block" href="<?= e($manifest['registerUrl']) ?>"><?= icon('user', 'icon--sm') ?><?= e(__('reader.gate_register')) ?></a>
                <a class="btn btn--ghost btn--block" href="<?= e($manifest['loginUrl']) ?>"><?= e(__('reader.gate_google')) ?></a>
                <p class="small reader-gate__login"><?= e(__('reader.gate_have_account')) ?> <a href="<?= e($manifest['loginUrl']) ?>"><?= e(__('reader.gate_login')) ?></a></p>
                <button type="button" class="btn btn--plain btn--sm" data-action="gate-back"><?= e(__('reader.gate_back', ['n' => $previewPages])) ?></button>
            </section>
        </div>
        <?php endif; ?>

        <!-- Privacy screen: hides the page while the window is not in focus or a capture key is pressed. -->
        <div class="reader-shield" data-shield hidden aria-hidden="true">
            <span class="reader-shield__mark"><?= icon('shield', 'icon--lg') ?></span>
            <p class="reader-shield__title"><?= e(__('reader.shield_title')) ?></p>
            <p class="small"><?= e(__('reader.shield_text')) ?></p>
        </div>

        <aside class="reader-info card" id="reader-info" data-info hidden aria-labelledby="reader-info-title">
            <div class="reader-info__head">
                <h2 id="reader-info-title" class="h4"><?= e(__('reader.info')) ?></h2>
                <button type="button" class="btn btn--plain btn--icon btn--sm" data-action="info" aria-label="<?= e(__('common.close')) ?>"><?= icon('close') ?></button>
            </div>
            <h3 class="xsmall muted"><?= e(__('reader.shortcuts')) ?></h3>
            <ul class="plain-list small">
                <li><kbd>←</kbd> <kbd>→</kbd> <?= e(__('reader.sc_pages')) ?></li>
                <li><kbd>+</kbd> <kbd>−</kbd> <?= e(__('reader.sc_zoom')) ?></li>
                <li><kbd>F</kbd> <?= e(__('reader.fullscreen')) ?></li>
                <li><kbd>T</kbd> <?= e(__('reader.thumbnails')) ?></li>
            </ul>
            <?= component('alert', ['type' => 'info', 'title' => __('reader.no_search_title'), 'text' => __('reader.no_search_text')]) ?>
            <p class="small muted"><?= icon('shield', 'icon--sm') ?> <?= e(__('reader.protection_text')) ?></p>
        </aside>
    </div>

    <footer class="reader-bar reader-bar--bottom" data-reader-toolbar dir="<?= e($bookDir) ?>">
        <button type="button" class="btn btn--secondary btn--sm reader-nav" data-action="prev" aria-label="<?= e(__('reader.prev')) ?>">
            <?= icon($bookDir === 'rtl' ? 'chevron-next' : 'chevron-prev', $bookDir === 'rtl' ? '' : '') ?><span class="hide-sm"><?= e(__('reader.prev')) ?></span>
        </button>
        <form class="reader-goto" data-goto>
            <label class="visually-hidden" for="reader-page-input"><?= e(__('reader.page_number')) ?></label>
            <input class="input reader-goto__input" id="reader-page-input" type="number" inputmode="numeric" min="1" max="<?= e($manifest['pageCount']) ?>" value="1" data-page-input>
            <span class="reader-goto__total">/ <span data-page-total><?= e($manifest['pageCount']) ?></span></span>
        </form>
        <button type="button" class="btn btn--secondary btn--sm reader-nav" data-action="next" aria-label="<?= e(__('reader.next')) ?>">
            <span class="hide-sm"><?= e(__('reader.next')) ?></span><?= icon($bookDir === 'rtl' ? 'chevron-prev' : 'chevron-next') ?>
        </button>
    </footer>
</div>

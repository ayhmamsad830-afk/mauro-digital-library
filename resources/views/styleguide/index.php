<?php /** @var list<array> $books @var list<array> $categories @var \App\Support\Paginator $paginator */ ?>
<div class="page-head">
    <div class="container">
        <p class="eyebrow"><?= e(__('demo.label')) ?></p>
        <h1 class="page-head__title"><?= e(__('styleguide.title')) ?></h1>
        <p class="page-head__sub"><?= e(__('styleguide.subtitle')) ?></p>
    </div>
</div>

<div class="container styleguide">
    <section class="sg-section" aria-labelledby="sg-colors">
        <h2 id="sg-colors"><?= e(__('styleguide.colors')) ?></h2>
        <ul class="swatches" role="list">
            <?php foreach (['bg', 'surface', 'surface-2', 'text', 'muted', 'border', 'primary', 'primary-soft', 'accent', 'success', 'warning', 'danger'] as $c): ?>
                <li><span class="swatch swatch--<?= e($c) ?>"></span><code>--c-<?= e($c) ?></code></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="sg-section" aria-labelledby="sg-type">
        <h2 id="sg-type"><?= e(__('styleguide.typography')) ?></h2>
        <p class="sg-type sg-type--3xl"><?= e(__('home.hero_title')) ?></p>
        <p class="sg-type sg-type--xl"><?= e(__('home.principles_title')) ?></p>
        <p><?= e(__('home.hero_text')) ?></p>
        <p class="small muted"><?= e(__('footer.protection_note')) ?></p>
    </section>

    <section class="sg-section" aria-labelledby="sg-buttons">
        <h2 id="sg-buttons"><?= e(__('styleguide.buttons')) ?></h2>
        <div class="cluster">
            <button class="btn btn--primary" type="button"><?= e(__('book.read_now')) ?></button>
            <button class="btn btn--secondary" type="button"><?= e(__('styleguide.secondary')) ?></button>
            <button class="btn btn--accent" type="button"><?= e(__('styleguide.accent')) ?></button>
            <button class="btn btn--ghost" type="button"><?= e(__('styleguide.ghost')) ?></button>
            <button class="btn btn--danger" type="button"><?= e(__('styleguide.danger')) ?></button>
            <button class="btn btn--primary" type="button" disabled><?= e(__('styleguide.disabled')) ?></button>
            <button class="btn btn--primary is-loading" type="button" aria-busy="true"><?= e(__('common.loading')) ?></button>
            <button class="btn btn--ghost btn--icon" type="button" aria-label="<?= e(__('search.submit')) ?>"><?= icon('search') ?></button>
        </div>
        <div class="cluster sg-gap"><button class="btn btn--primary btn--sm" type="button">Small</button><button class="btn btn--primary btn--lg" type="button">Large</button></div>
    </section>

    <section class="sg-section" aria-labelledby="sg-forms">
        <h2 id="sg-forms"><?= e(__('styleguide.forms')) ?></h2>
        <form class="card card--pad" action="#" data-preview-form>
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="sg-name"><?= e(__('styleguide.field_text')) ?><span class="req" aria-hidden="true">*</span></label>
                    <input class="input" id="sg-name" placeholder="<?= e(__('search.placeholder')) ?>">
                    <p class="help"><?= e(__('styleguide.help_text')) ?></p>
                </div>
                <div class="field">
                    <label class="label" for="sg-select"><?= e(__('filter.category')) ?></label>
                    <select class="select" id="sg-select"><?php foreach ($categories as $c): ?><option><?= e(localized($c, 'name')) ?></option><?php endforeach; ?></select>
                </div>
                <div class="field">
                    <label class="label" for="sg-err"><?= e(__('styleguide.field_error')) ?></label>
                    <input class="input" id="sg-err" aria-invalid="true" aria-describedby="sg-err-msg" value="x">
                    <p class="error-text" id="sg-err-msg"><?= icon('alert', 'icon--sm') ?><?= e(__('styleguide.error_text')) ?></p>
                </div>
                <div class="field">
                    <label class="label" for="sg-dis"><?= e(__('styleguide.disabled')) ?></label>
                    <input class="input" id="sg-dis" disabled value="—">
                </div>
            </div>
            <div class="field"><label class="label" for="sg-ta"><?= e(__('styleguide.field_textarea')) ?></label><textarea class="textarea" id="sg-ta"></textarea></div>
            <label class="check"><input type="checkbox" checked> <?= e(__('styleguide.checkbox')) ?></label>
            <div class="form-actions"><button class="btn btn--primary" type="submit"><?= e(__('styleguide.save')) ?></button><button class="btn btn--ghost" type="reset"><?= e(__('styleguide.cancel')) ?></button></div>
        </form>
    </section>

    <section class="sg-section" aria-labelledby="sg-search">
        <h2 id="sg-search"><?= e(__('styleguide.search')) ?></h2>
        <div class="stack">
            <?= component('search-bar', ['size' => 'lg', 'id' => 'sg-search-lg']) ?>
            <?= component('search-bar', ['size' => 'md', 'id' => 'sg-search-md']) ?>
        </div>
    </section>

    <section class="sg-section" aria-labelledby="sg-cards">
        <h2 id="sg-cards"><?= e(__('styleguide.book_cards')) ?></h2>
        <?= component('book-grid', ['books' => $books]) ?>
        <h3 class="sg-sub"><?= e(__('styleguide.category_cards')) ?></h3>
        <ul class="category-grid" role="list"><?php foreach ($categories as $c): ?><li><?= component('category-card', ['category' => $c]) ?></li><?php endforeach; ?></ul>
    </section>

    <section class="sg-section" aria-labelledby="sg-feedback">
        <h2 id="sg-feedback"><?= e(__('styleguide.feedback')) ?></h2>
        <div class="stack">
            <?= component('alert', ['type' => 'info', 'title' => __('styleguide.info'), 'text' => __('reader.no_search_text')]) ?>
            <?= component('alert', ['type' => 'success', 'text' => __('styleguide.success_text')]) ?>
            <?= component('alert', ['type' => 'warning', 'text' => __('pages.draft_notice')]) ?>
            <?= component('alert', ['type' => 'danger', 'text' => __('reader.load_error')]) ?>
            <div class="cluster"><span class="badge">Default</span><span class="badge badge--primary">Primary</span><span class="badge badge--success"><?= e(__('admin.status_published')) ?></span><span class="badge badge--warning"><?= e(__('admin.status_draft')) ?></span><span class="badge badge--danger"><?= e(__('admin.job_failed')) ?></span><?= component('rating', ['avg' => 4.6, 'count' => 128]) ?></div>
            <progress class="progress" max="100" value="62" aria-label="62%"></progress>
        </div>
    </section>

    <section class="sg-section" aria-labelledby="sg-loading">
        <h2 id="sg-loading"><?= e(__('styleguide.loading')) ?></h2>
        <?= component('skeleton-grid', ['count' => 4]) ?>
    </section>

    <section class="sg-section" aria-labelledby="sg-empty">
        <h2 id="sg-empty"><?= e(__('styleguide.empty')) ?></h2>
        <?= component('empty-state', ['icon' => 'search', 'title' => __('catalog.no_results'), 'text' => __('catalog.no_results_text'), 'actionUrl' => route('books.index'), 'actionLabel' => __('filter.clear')]) ?>
    </section>

    <section class="sg-section" aria-labelledby="sg-nav">
        <h2 id="sg-nav"><?= e(__('styleguide.navigation')) ?></h2>
        <?= component('breadcrumbs', ['items' => [[__('nav.home'), route('home')], [__('nav.library'), route('books.index')], [__('styleguide.title'), null]]]) ?>
        <nav class="tabs sg-gap" aria-label="Tabs"><a href="#sg-nav" aria-current="true"><?= e(__('account.history')) ?></a><a href="#sg-nav"><?= e(__('account.favorites')) ?></a><a href="#sg-nav"><?= e(__('account.bookmarks')) ?></a></nav>
        <?= component('pagination', ['paginator' => $paginator, 'pageUrl' => static fn (int $p) => '#page-' . $p]) ?>
    </section>
</div>

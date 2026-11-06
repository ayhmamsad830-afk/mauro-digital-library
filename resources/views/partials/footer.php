<?php $appName = brand_name(); ?>
<footer class="site-footer">
    <div class="container site-footer__grid">
        <div class="site-footer__brand">
            <?= component('brand') ?>
            <p class="site-footer__tagline"><?= e(__('app.tagline')) ?></p>
            <p class="muted small site-footer__note"><?= icon('shield', 'icon--sm') ?><span><?= e(__('footer.protection_note')) ?></span></p>
        </div>
        <div>
            <h2><?= e(__('footer.explore')) ?></h2>
            <ul>
                <li><a href="<?= e(route('books.index')) ?>"><?= e(__('nav.library')) ?></a></li>
                <li><a href="<?= e(route('categories.index')) ?>"><?= e(__('nav.categories')) ?></a></li>
                <li><a href="<?= e(route('books.latest')) ?>"><?= e(__('nav.latest')) ?></a></li>
                <li><a href="<?= e(route('books.popular')) ?>"><?= e(__('nav.popular')) ?></a></li>
            </ul>
        </div>
        <div>
            <h2><?= e(__('footer.account')) ?></h2>
            <ul>
                <li><a href="<?= e(route('login')) ?>"><?= e(__('nav.sign_in')) ?></a></li>
                <li><a href="<?= e(route('account')) ?>"><?= e(__('nav.my_library')) ?></a></li>
            </ul>
        </div>
        <div>
            <h2><?= e(__('footer.about')) ?></h2>
            <ul>
                <li><a href="<?= e(route('pages.privacy')) ?>"><?= e(__('pages.privacy.title')) ?></a></li>
                <li><a href="<?= e(route('pages.terms')) ?>"><?= e(__('pages.terms.title')) ?></a></li>
                <li><a href="<?= e(route('pages.copyright')) ?>"><?= e(__('pages.copyright.title')) ?></a></li>
            </ul>
        </div>
    </div>
    <div class="container site-footer__bottom">
        <p class="muted small">© <?= e(date('Y')) ?> <?= e($appName) ?>. <?= e(__('footer.rights')) ?></p>
        <p class="muted small"><?= e(__('footer.website_only')) ?></p>
    </div>
</footer>

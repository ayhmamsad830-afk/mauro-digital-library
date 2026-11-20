<?php $brand = brand_name(); ?>
<div class="page-head page-head--about">
    <div class="container container--narrow">
        <?= component('breadcrumbs', ['items' => [[__('nav.home'), route('home')], [__('about.title', ['brand' => $brand]), null]]]) ?>
        <p class="eyebrow"><?= e(__('nav.about')) ?></p>
        <h1 class="page-head__title display"><?= e(__('about.title', ['brand' => $brand])) ?></h1>
        <p class="page-head__lead"><?= e(__('about.lead')) ?></p>
    </div>
</div>

<div class="container container--narrow section about">
    <div class="about__pair">
        <section class="about-block card">
            <span class="about-block__mark" aria-hidden="true"><?= icon('book') ?></span>
            <h2 class="h4"><?= e(__('about.what_title', ['brand' => $brand])) ?></h2>
            <p><?= e(__('about.what_text', ['brand' => $brand])) ?></p>
        </section>
        <section class="about-block card">
            <span class="about-block__mark" aria-hidden="true"><?= icon('eye') ?></span>
            <h2 class="h4"><?= e(__('about.reading_title')) ?></h2>
            <p><?= e(__('about.reading_text')) ?></p>
        </section>
    </div>

    <section class="about-block about-block--features">
        <h2 class="section-title"><?= e(__('about.features_title')) ?></h2>
        <ul class="about__features" role="list">
            <li><span class="about__feature-icon" aria-hidden="true"><?= icon('search') ?></span><?= e(__('about.feature_browse')) ?></li>
            <li><span class="about__feature-icon" aria-hidden="true"><?= icon('book') ?></span><?= e(__('about.feature_preview')) ?></li>
            <li><span class="about__feature-icon" aria-hidden="true"><?= icon('heart') ?></span><?= e(__('about.feature_library')) ?></li>
            <li><span class="about__feature-icon" aria-hidden="true"><?= icon('star') ?></span><?= e(__('about.feature_reviews')) ?></li>
        </ul>
    </section>

    <section class="about-block about-block--values card">
        <h2 class="section-title"><?= e(__('about.values_title')) ?></h2>
        <dl class="about__values">
            <div><dt><?= icon('shield', 'icon--sm') ?></dt><dd><?= e(__('about.value_rights')) ?></dd></div>
            <div><dt><?= icon('lock', 'icon--sm') ?></dt><dd><?= e(__('about.value_privacy')) ?></dd></div>
            <div><dt><?= icon('globe', 'icon--sm') ?></dt><dd><?= e(__('about.value_access')) ?></dd></div>
        </dl>
    </section>

    <div class="about__cta">
        <a class="btn btn--primary btn--lg" href="<?= e(route('books.index')) ?>"><?= icon('book', 'icon--sm') ?><?= e(__('about.cta_browse')) ?></a>
        <a class="btn btn--secondary btn--lg" href="<?= e(route('contact')) ?>"><?= icon('mail', 'icon--sm') ?><?= e(__('about.cta_contact')) ?></a>
    </div>
</div>

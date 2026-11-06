<?php /** @var string $key @var int $sections @var string|null $contactEmail */ ?>
<div class="page-head">
    <div class="container container--narrow">
        <?= component('breadcrumbs', ['items' => [[__('nav.home'), route('home')], [__("pages.$key.title"), null]]]) ?>
        <h1 class="page-head__title"><?= e(__("pages.$key.title")) ?></h1>
    </div>
</div>
<div class="container container--narrow section prose">
    <?= component('alert', ['type' => 'warning', 'text' => __('pages.draft_notice')]) ?>
    <p class="lead"><?= e(__("pages.$key.intro")) ?></p>
    <?php for ($i = 1; $i <= $sections; $i++): ?>
        <h2><?= e(__("pages.$key.s{$i}_title")) ?></h2>
        <p><?= e(__("pages.$key.s{$i}_text")) ?></p>
    <?php endfor; ?>
    <?php if (!empty($contactEmail)): ?>
        <h2><?= e(__('pages.contact_title')) ?></h2>
        <p><?= e(__('pages.contact_text')) ?> <a class="ltr" href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a></p>
    <?php endif; ?>
</div>

<div class="demo-banner" role="note">
    <div class="container demo-banner__inner">
        <span><strong><?= e(__('demo.label')) ?></strong> — <?= e(__('demo.text')) ?></span>
        <nav aria-label="<?= e(__('demo.previews')) ?>">
            <a href="<?= e(route('styleguide')) ?>"><?= e(__('demo.styleguide')) ?></a>
            <a href="<?= e(route('account.preview')) ?>"><?= e(__('demo.account')) ?></a>
            <a href="<?= e(route('admin.preview')) ?>"><?= e(__('demo.admin')) ?></a>
        </nav>
    </div>
</div>

<button type="button" class="btn btn--ghost btn--icon btn--sm" data-theme-toggle aria-pressed="<?= ($currentTheme ?? null) === 'dark' ? 'true' : 'false' ?>"
        aria-label="<?= e(__('pref.theme_toggle')) ?>" title="<?= e(__('pref.theme_toggle')) ?>">
    <?= icon('sun', 'theme-icon--sun') ?><?= icon('moon', 'theme-icon--moon') ?>
</button>

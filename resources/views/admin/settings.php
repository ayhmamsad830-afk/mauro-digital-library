<?php /** @var array $values @var list<int> $perPageOptions @var list<array> $books */
$val = static fn (string $k) => old($k, $values[$k]); ?>
<form class="card card--pad admin-narrow-form" method="post" action="<?= e(route('admin.settings.update')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="field">
        <label class="label" for="s-pp"><?= e(__('admin.set_per_page')) ?></label>
        <select class="select" id="s-pp" name="catalog_per_page"<?= field_error('catalog_per_page') ? ' aria-invalid="true" aria-describedby="s-pp-err"' : '' ?>>
            <?php foreach ($perPageOptions as $o): ?><option value="<?= e($o) ?>"<?= (string) $val('catalog_per_page') === (string) $o ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?>
        </select>
        <?php if ($err = field_error('catalog_per_page')): ?><p class="error-text" id="s-pp-err"><?= e($err) ?></p><?php endif; ?>
    </div>
    <div class="field">
        <label class="label" for="s-feat"><?= e(__('admin.set_featured')) ?></label>
        <select class="select" id="s-feat" name="featured_book_id" aria-describedby="s-feat-help<?= field_error('featured_book_id') ? ' s-feat-err' : '' ?>">
            <option value=""><?= e(__('admin.set_featured_auto')) ?></option>
            <?php foreach ($books as $b): ?><option value="<?= e($b['id']) ?>"<?= (string) $val('featured_book_id') === (string) $b['id'] ? ' selected' : '' ?>><?= e($b['title']) ?></option><?php endforeach; ?>
        </select>
        <p class="help" id="s-feat-help"><?= e(__('admin.set_featured_help')) ?></p>
        <?php if ($err = field_error('featured_book_id')): ?><p class="error-text" id="s-feat-err"><?= e($err) ?></p><?php endif; ?>
    </div>
    <div class="field">
        <label class="label" for="s-mail"><?= e(__('admin.set_contact')) ?></label>
        <input class="input ltr" id="s-mail" type="email" name="contact_email" maxlength="190" value="<?= e((string) $val('contact_email')) ?>" aria-describedby="s-mail-help<?= field_error('contact_email') ? ' s-mail-err' : '' ?>">
        <p class="help" id="s-mail-help"><?= e(__('admin.set_contact_help')) ?></p>
        <?php if ($err = field_error('contact_email')): ?><p class="error-text" id="s-mail-err"><?= e($err) ?></p><?php endif; ?>
    </div>
    <div class="form-actions"><button class="btn btn--primary" type="submit"><?= e(__('styleguide.save')) ?></button></div>
</form>

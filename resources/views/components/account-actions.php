<?php
/** Switch account + sign out (both POST with CSRF; "switch" lands on the sign-in page). */
?>
<div class="account-menu__actions">
    <form method="post" action="<?= e(route('logout')) ?>" class="account-menu__form">
        <?= csrf_field() ?>
        <input type="hidden" name="switch" value="1">
        <button type="submit" class="account-menu__item"><?= icon('switch-user', 'icon--sm') ?><span><?= e(__('nav.switch_account')) ?></span></button>
    </form>
    <form method="post" action="<?= e(route('logout')) ?>" class="account-menu__form">
        <?= csrf_field() ?>
        <button type="submit" class="account-menu__item account-menu__item--danger"><?= icon('logout', 'icon--sm icon--dir') ?><span><?= e(__('nav.sign_out')) ?></span></button>
    </form>
</div>

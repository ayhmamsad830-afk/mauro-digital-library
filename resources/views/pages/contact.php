<?php /** @var string|null $sent @var array<string,string> $values @var array<string,string> $errors @var string|null $error @var bool $emailDelivery */
?>
<div class="page-head page-head--contact">
    <div class="container container--narrow">
        <?= component('breadcrumbs', ['items' => [[__('nav.home'), route('home')], [__('contact.title'), null]]]) ?>
        <h1 class="page-head__title"><?= e(__('contact.title')) ?></h1>
        <p class="page-head__lead"><?= e(__('contact.lead')) ?></p>
    </div>
</div>
<div class="container container--narrow section contact">
    <div class="contact__grid">
        <aside class="contact__aside card">
            <span class="contact__aside-mark"><?= icon('mail', 'icon--lg') ?></span>
            <h2 class="h4"><?= e(__('contact.how_title')) ?></h2>
            <p class="muted small"><?= e(__('contact.how_text')) ?></p>
            <ul class="contact__topics" role="list">
                <li><?= icon('book', 'icon--sm') ?><?= e(__('contact.topic_books')) ?></li>
                <li><?= icon('user', 'icon--sm') ?><?= e(__('contact.topic_account')) ?></li>
                <li><?= icon('shield', 'icon--sm') ?><?= e(__('contact.topic_rights')) ?></li>
            </ul>
        </aside>

        <div class="contact__main card card--raised">
            <?php if ($sent !== null): ?>
                <div class="contact__success" role="status">
                    <span class="contact__success-mark"><?= icon('check', 'icon--lg') ?></span>
                    <h2 class="h4"><?= e(__('contact.received_title')) ?></h2>
                    <p><?= e(__($sent === 'sent' ? 'contact.received_notified' : 'contact.received_stored')) ?></p>
                    <a class="btn btn--secondary" href="<?= e(route('home')) ?>"><?= e(__('nav.home')) ?></a>
                </div>
            <?php else: ?>
                <?php if (!empty($error)): ?><?= component('alert', ['type' => 'danger', 'text' => (string) $error]) ?><?php endif; ?>
                <form class="contact__form" method="post" action="<?= e(route('contact.submit')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="contact__hp" aria-hidden="true">
                        <label for="contact-website">Website</label>
                        <input id="contact-website" name="website" type="text" tabindex="-1" autocomplete="off">
                    </div>
                    <div class="contact__row">
                        <div class="field">
                            <label class="label" for="contact-name"><?= e(__('contact.name')) ?></label>
                            <input class="input" id="contact-name" name="name" type="text" value="<?= e($values['name'] ?? '') ?>" maxlength="80" autocomplete="name" required<?php if (isset($errors['name'])): ?> aria-invalid="true" aria-describedby="contact-name-error"<?php endif; ?>>
                            <?php if (isset($errors['name'])): ?><p class="field__error" id="contact-name-error"><?= e(__($errors['name'])) ?></p><?php endif; ?>
                        </div>
                        <div class="field">
                            <label class="label" for="contact-email"><?= e(__('contact.email')) ?></label>
                            <input class="input" id="contact-email" name="email" type="email" dir="ltr" value="<?= e($values['email'] ?? '') ?>" maxlength="190" autocomplete="email" required<?php if (isset($errors['email'])): ?> aria-invalid="true" aria-describedby="contact-email-error"<?php endif; ?>>
                            <?php if (isset($errors['email'])): ?><p class="field__error" id="contact-email-error"><?= e(__($errors['email'])) ?></p><?php endif; ?>
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="contact-subject"><?= e(__('contact.subject')) ?></label>
                        <input class="input" id="contact-subject" name="subject" type="text" value="<?= e($values['subject'] ?? '') ?>" maxlength="150" required<?php if (isset($errors['subject'])): ?> aria-invalid="true" aria-describedby="contact-subject-error"<?php endif; ?>>
                        <?php if (isset($errors['subject'])): ?><p class="field__error" id="contact-subject-error"><?= e(__($errors['subject'])) ?></p><?php endif; ?>
                    </div>
                    <div class="field">
                        <label class="label" for="contact-message"><?= e(__('contact.message')) ?></label>
                        <textarea class="input textarea" id="contact-message" name="message" rows="7" maxlength="5000" required<?php if (isset($errors['message'])): ?> aria-invalid="true" aria-describedby="contact-message-error"<?php endif; ?>><?= e($values['message'] ?? '') ?></textarea>
                        <?php if (isset($errors['message'])): ?><p class="field__error" id="contact-message-error"><?= e(__($errors['message'])) ?></p><?php endif; ?>
                    </div>
                    <p class="help"><?= e(__($emailDelivery ? 'contact.delivery_email' : 'contact.delivery_stored')) ?></p>
                    <button class="btn btn--primary btn--lg" type="submit" data-loading-on-submit><?= icon('mail', 'icon--sm') ?><?= e(__('contact.send')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

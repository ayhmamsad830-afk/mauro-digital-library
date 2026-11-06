<?php
/**
 * Shared error body.
 * @var int $status
 * @var string|null $message
 * @var string $requestId
 * @var \Throwable|null $exception  (debug mode only)
 */
$titleKey = "errors.$status.title";
$messageKey = "errors.$status.message";
$title = __($titleKey) !== $titleKey ? __($titleKey) : \App\Core\ErrorHandler::statusText($status);
$text = $message ?? (__($messageKey) !== $messageKey ? __($messageKey) : __('errors.generic.message'));
?>
<section class="error-page">
    <div class="container error-page__inner">
        <p class="error-page__code" aria-hidden="true"><?= e($status) ?></p>
        <h1 class="error-page__title"><?= e($title) ?></h1>
        <p class="error-page__text"><?= e($text) ?></p>
        <a class="btn btn--primary" href="/"><?= e(__('errors.back_home')) ?></a>
        <?php if ($status >= 500): ?>
        <p class="muted small"><?= e(__('errors.request_id')) ?>: <code><?= e($requestId) ?></code></p>
        <?php endif; ?>
        <?php if (!empty($exception)): ?>
        <details class="debug" open>
            <summary><?= e(get_class($exception)) ?>: <?= e($exception->getMessage()) ?></summary>
            <pre dir="ltr"><?= e($exception->getFile() . ':' . $exception->getLine() . "\n\n" . $exception->getTraceAsString()) ?></pre>
        </details>
        <?php endif; ?>
    </div>
</section>

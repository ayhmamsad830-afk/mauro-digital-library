<?php /** @var list<array> $books @var bool $rail @var string|null $variant  'boxed' = catalogue card with a Read button */ ?>
<ul class="book-grid<?= !empty($rail) ? ' book-grid--rail' : '' ?><?= e(!empty($variant) ? ' book-grid--' . $variant : '') ?>" role="list">
    <?php foreach ($books as $book): ?>
        <li><?= component('book-card', ['book' => $book, 'badge' => $badge ?? null, 'variant' => $variant ?? null]) ?></li>
    <?php endforeach; ?>
</ul>

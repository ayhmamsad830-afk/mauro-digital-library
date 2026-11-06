<?php
/** @var array $book @var list<array> $related @var list<array> $reviews @var bool $isMember @var bool $isFavorite @var array|null $myReview @var string|null $reviewError */
$isFavorite = $isFavorite ?? false;
$myReview = $myReview ?? null;
$category = $book['categories'][0] ?? null;
$crumbs = [[__('nav.home'), route('home')], [__('nav.library'), route('books.index')]];
if ($category) { $crumbs[] = [localized($category, 'name'), route('categories.show', ['slug' => $category['slug']])]; }
$crumbs[] = [$book['title'], null];
$loginUrl = route('login', [], ['redirect' => route('books.show', ['slug' => $book['slug']])]);
$rightsLabels = ['owned' => __('book.rights_owned'), 'licensed' => __('book.rights_licensed'), 'public_domain' => __('book.rights_public_domain')];
?>
<div class="book-stage">
<div class="container">
    <?= component('breadcrumbs', ['items' => $crumbs]) ?>

    <div class="book-hero">
        <div class="book-hero__cover"><?= component('cover', ['book' => $book]) ?></div>

        <div class="book-hero__info">
            <div class="cluster">
                <?php foreach ($book['categories'] as $c): ?>
                    <a class="badge badge--primary" href="<?= e(route('categories.show', ['slug' => $c['slug']])) ?>"><?= e(localized($c, 'name')) ?></a>
                <?php endforeach; ?>
                <span class="badge"><?= e(language_name($book['language'])) ?></span>
                <?php if (($book['visibility'] ?? 'public') === 'members'): ?><span class="badge badge--accent"><?= e(__('book.members_only')) ?></span><?php endif; ?>
            </div>
            <h1 class="book-hero__title display" lang="<?= e($book['language']) ?>" dir="auto"><?= e($book['title']) ?></h1>
            <?php if (!empty($book['title_en']) && $book['language'] !== 'en'): ?><p class="book-hero__subtitle"><span lang="en" dir="ltr"><?= e($book['title_en']) ?></span></p><?php endif; ?>
            <p class="book-hero__authors">
                <?= e(__('book.by')) ?>
                <?= component('author-names', ['authors' => $book['authors'], 'links' => true]) ?>
            </p>
            <?php if ($book['rating_count'] > 0): ?>
                <p class="cluster book-hero__rating"><a href="#reviews" class="book-hero__rating-link"><?= component('rating', ['avg' => $book['rating_avg'], 'count' => $book['rating_count']]) ?></a></p>
            <?php endif; ?>

            <div class="book-hero__actions btn-group">
                <a class="btn btn--primary btn--lg" href="<?= e(route('reader.show', ['slug' => $book['slug']])) ?>"><?= icon('book') ?><?= e(__('book.read_now')) ?></a>
                <?php if ($isMember): ?>
                    <form class="favorite-form" method="post" action="<?= e(route('member.favorite', ['bookId' => $book['id']])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="redirect" value="<?= e(route('books.show', ['slug' => $book['slug']])) ?>">
                        <button type="submit" class="btn btn--<?= $isFavorite ? 'secondary' : 'ghost' ?> btn--lg" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>">
                            <?= icon('heart', $isFavorite ? 'is-on' : '') ?><?= e($isFavorite ? __('book.in_favorites') : __('book.add_favorite')) ?>
                        </button>
                    </form>
                <?php else: ?>
                    <a class="btn btn--ghost btn--lg" href="<?= e($loginUrl) ?>" title="<?= e(__('book.favorite_login_hint')) ?>"><?= icon('heart') ?><?= e(__('book.add_favorite')) ?></a>
                <?php endif; ?>
            </div>
            <p class="small muted book-hero__note"><?= icon('shield', 'icon--sm') ?> <?= e(__('book.protected_note')) ?></p>

            <dl class="book-facts">
                <?php if ($book['publisher']): ?><div><dt><?= e(__('book.publisher')) ?></dt><dd dir="auto"><?= e($book['publisher']) ?></dd></div><?php endif; ?>
                <?php if ($book['publication_year']): ?><div><dt><?= e(__('book.year')) ?></dt><dd><?= e($book['publication_year']) ?></dd></div><?php endif; ?>
                <?php if (!empty($book['edition'])): ?><div><dt><?= e(__('book.edition')) ?></dt><dd dir="auto"><?= e($book['edition']) ?></dd></div><?php endif; ?>
                <?php if ($book['page_count']): ?><div><dt><?= e(__('book.pages')) ?></dt><dd><?= e(format_number((int) $book['page_count'])) ?></dd></div><?php endif; ?>
                <div><dt><?= e(__('book.language')) ?></dt><dd><?= e(language_name($book['language'])) ?></dd></div>
                <?php if (!empty($book['rights_status'])): ?><div><dt><?= e(__('book.rights')) ?></dt><dd><?= e($rightsLabels[$book['rights_status']] ?? '') ?></dd></div><?php endif; ?>
                <?php if (!empty($book['isbn'])): ?><div class="book-facts__isbn"><dt><?= e(__('book.isbn')) ?></dt><dd class="ltr"><?= e($book['isbn']) ?></dd></div><?php endif; ?>
            </dl>
        </div>
    </div>
</div>
</div>

<div class="container book-page">
    <div class="book-body">
        <section class="book-body__main" aria-labelledby="about-title">
            <h2 id="about-title" class="book-section-title"><?= e(__('book.about')) ?></h2>
            <?php $useEn = locale() === 'en' && !empty($book['description_en']); ?>
            <div class="prose book-description" lang="<?= e($useEn ? 'en' : $book['language']) ?>" dir="auto"><?php foreach (preg_split('/\R{2,}/u', trim($useEn ? $book['description_en'] : $book['description'])) as $para): ?><p><?= nl2br(e($para)) ?></p><?php endforeach; ?></div>

            <section class="reviews" id="reviews" aria-labelledby="reviews-title">
                <div class="section-head">
                    <h2 id="reviews-title" class="book-section-title"><?= e(__('reviews.title')) ?></h2>
                    <?php if (!$isMember): ?><a class="btn btn--ghost btn--sm" href="<?= e($loginUrl) ?>"><?= icon('star', 'icon--sm') ?><?= e(__('reviews.login_to_rate')) ?></a><?php endif; ?>
                </div>
                <?php if ($isMember): ?>
                    <form class="review-form card card--pad<?= $myReview ? ' my-review' : '' ?>" method="post" action="<?= e(route('member.review.save', ['slug' => $book['slug']])) ?>">
                        <?= csrf_field() ?>
                        <h3 class="h4"><?= e($myReview ? __('member.your_review') : __('member.write_review')) ?></h3>
                        <?php if (!empty($reviewError)): ?><?= component('alert', ['type' => 'danger', 'text' => (string) $reviewError]) ?><?php endif; ?>
                        <?php if ($myReview && $myReview['status'] === 'hidden'): ?><p class="help"><?= e(__('member.review_hidden')) ?></p><?php endif; ?>
                        <?php $current = (int) old('rating', $myReview['rating'] ?? 0); ?>
                        <fieldset class="rating-input">
                            <legend class="visually-hidden"><?= e(__('member.your_rating')) ?></legend>
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" id="rate-<?= $i ?>" name="rating" value="<?= $i ?>"<?= $current === $i ? ' checked' : '' ?> required>
                                <label for="rate-<?= $i ?>" title="<?= e(__('reviews.stars', ['n' => $i])) ?>"><?= icon('star') ?><span class="visually-hidden"><?= e(__('reviews.stars', ['n' => $i])) ?></span></label>
                            <?php endfor; ?>
                        </fieldset>
                        <div class="field">
                            <label class="label" for="review-comment"><?= e(__('member.comment_optional')) ?></label>
                            <textarea class="textarea" id="review-comment" name="comment" maxlength="2000" rows="3" dir="auto"><?= e((string) old('comment', $myReview['comment'] ?? '')) ?></textarea>
                        </div>
                        <div class="btn-group">
                            <button class="btn btn--primary btn--sm" type="submit"><?= e(__('member.save_review')) ?></button>
                        </div>
                    </form>
                    <?php if ($myReview): ?>
                        <form method="post" action="<?= e(route('member.review.delete', ['slug' => $book['slug']])) ?>" class="review-delete"><?= csrf_field() ?>
                            <button class="btn btn--plain btn--sm" type="submit"><?= e(__('member.delete_review')) ?></button></form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($reviews): ?>
                    <ul class="review-list" role="list">
                        <?php foreach ($reviews as $r): ?>
                        <li class="review card">
                            <div class="review__head">
                                <span class="avatar" aria-hidden="true"><?= e(mb_substr($r['user_name'], 0, 1)) ?></span>
                                <div>
                                    <strong><?= e($r['user_name']) ?></strong>
                                    <div class="stars" aria-label="<?= e(__('reviews.stars', ['n' => $r['rating']])) ?>" role="img">
                                        <?php for ($i = 1; $i <= 5; $i++): ?><?= icon('star', $i <= $r['rating'] ? 'is-on' : '') ?><?php endfor; ?>
                                    </div>
                                </div>
                                <time class="muted xsmall review__date" datetime="<?= e($r['created_at']) ?>"><?= e(format_date($r['created_at'])) ?></time>
                            </div>
                            <?php if ($r['comment']): ?><p><?= e($r['comment']) ?></p><?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="muted"><?= e(__('reviews.none')) ?></p>
                <?php endif; ?>
            </section>
        </section>

        <aside class="book-body__side">
            <div class="card card--pad">
                <h2 class="h4"><?= icon('info', 'icon--sm') ?> <?= e(__('book.reading_info_title')) ?></h2>
                <ul class="plain-list small muted">
                    <li><?= e(__('book.reading_info_1')) ?></li>
                    <li><?= e(__('book.reading_info_2')) ?></li>
                    <li><?= e(__('book.reading_info_3')) ?></li>
                </ul>
            </div>
        </aside>
    </div>

    <?php if ($related): ?>
    <section class="section" aria-labelledby="related-title">
        <?= component('section-head', ['title' => __('book.related'), 'sub' => __('book.related_sub'), 'id' => 'related-title']) ?>
        <?= component('book-grid', ['books' => $related, 'rail' => true]) ?>
    </section>
    <?php endif; ?>
</div>

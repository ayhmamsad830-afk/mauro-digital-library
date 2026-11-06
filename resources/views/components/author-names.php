<?php
/**
 * Book authors in stored order, joined for the current locale:
 *   ar: «أ، ب، ج»        en: "A and B" / "A, B and C"
 * Each name is isolated in <bdi> so Arabic names inside an English sentence
 * (and vice versa) never reorder the punctuation around them.
 * @var list<array{name: string, slug?: string}> $authors
 * @var bool $links  link each name to the catalogue filtered by that author
 */
$authors = array_values($authors ?? []);
$links = !empty($links);
$n = count($authors);
foreach ($authors as $i => $a):
    if ($i > 0): ?><?= e(author_separator($i, $n)) ?><?php endif;
    if ($links && !empty($a['slug'])): ?><bdi><a href="<?= e(route('books.index', [], ['author' => $a['slug']])) ?>"><?= e($a['name']) ?></a></bdi><?php
    else: ?><bdi><?= e($a['name']) ?></bdi><?php
    endif;
endforeach;

<?php /** @var string $status */ $map = ['published' => 'success', 'draft' => 'warning', 'archived' => '', 'active' => 'success', 'inactive' => '']; ?>
<span class="badge badge--<?= e($map[$status] ?? '') ?>"><?= e(__('admin.status_' . $status)) ?></span>

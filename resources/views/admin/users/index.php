<?php /** @var \App\Support\Paginator $users @var array $filters @var int $selfId @var callable $pageUrl */ ?>
<div class="admin-toolbar">
    <form class="admin-filter" method="get" action="<?= e(route('admin.users.index')) ?>" role="search">
        <label class="visually-hidden" for="au-q"><?= e(__('admin.users_search')) ?></label>
        <input class="input" id="au-q" type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="<?= e(__('admin.users_search')) ?>">
        <label class="visually-hidden" for="au-role"><?= e(__('admin.col_role')) ?></label>
        <select class="select" id="au-role" name="role" data-autosubmit>
            <option value=""><?= e(__('admin.all_roles')) ?></option>
            <?php foreach (['user', 'admin'] as $r): ?><option value="<?= e($r) ?>"<?= $filters['role'] === $r ? ' selected' : '' ?>><?= e(__('admin.role_' . $r)) ?></option><?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="au-status"><?= e(__('admin.col_status')) ?></label>
        <select class="select" id="au-status" name="status" data-autosubmit>
            <option value=""><?= e(__('filter.all')) ?></option>
            <?php foreach (['active', 'suspended'] as $s): ?><option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(__('admin.status_' . $s)) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn--ghost" type="submit"><?= icon('search', 'icon--sm') ?><?= e(__('search.submit')) ?></button>
    </form>
    <p class="muted small"><?= e(__('admin.users_total', ['count' => format_number($users->total)])) ?></p>
</div>

<?php if ($users->items): ?>
<div class="table-wrap">
    <table class="table table--stack">
        <thead><tr>
            <th scope="col"><?= e(__('admin.col_name')) ?></th>
            <th scope="col"><?= e(__('admin.col_role')) ?></th>
            <th scope="col"><?= e(__('admin.col_status')) ?></th>
            <th scope="col"><?= e(__('admin.col_activity')) ?></th>
            <th scope="col"><?= e(__('admin.col_last_login')) ?></th>
            <th scope="col"><span class="visually-hidden"><?= e(__('admin.actions')) ?></span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($users->items as $u): $self = (int) $u['id'] === $selfId; ?>
            <tr>
                <td class="wrap"><strong dir="auto"><?= e($u['name'] !== '' ? $u['name'] : '—') ?></strong><br><span class="muted xsmall ltr"><?= e($u['email']) ?></span><?php if ($self): ?> <span class="badge"><?= e(__('admin.you')) ?></span><?php endif; ?></td>
                <td data-label="<?= e(__('admin.col_role')) ?>"><span class="badge<?= $u['role'] === 'admin' ? ' badge--accent' : '' ?>"><?= e(__('admin.role_' . $u['role'])) ?></span></td>
                <td data-label="<?= e(__('admin.col_status')) ?>"><span class="badge badge--<?= $u['status'] === 'active' ? 'success' : 'danger' ?>"><?= e(__('admin.status_' . $u['status'])) ?></span></td>
                <td class="xsmall" data-label="<?= e(__('admin.col_activity')) ?>"><?= e(__('admin.user_activity', ['books' => (int) $u['books_count'], 'reviews' => (int) $u['reviews_count']])) ?></td>
                <td class="xsmall" data-label="<?= e(__('admin.col_last_login')) ?>"><span class="ltr"><?= e($u['last_login_at'] ? substr((string) $u['last_login_at'], 0, 16) . ' UTC' : '—') ?></span></td>
                <td>
                    <?php if (!$self): ?>
                    <div class="row-actions">
                        <form method="post" action="<?= e(route('admin.users.status', ['id' => $u['id']])) ?>"><?= csrf_field() ?>
                            <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'suspended' : 'active' ?>">
                            <input type="hidden" name="redirect" value="<?= e(current_url()) ?>">
                            <button class="btn btn--<?= $u['status'] === 'active' ? 'danger' : 'ghost' ?> btn--sm" type="submit"><?= e($u['status'] === 'active' ? __('admin.suspend') : __('admin.activate')) ?></button>
                        </form>
                        <form method="post" action="<?= e(route('admin.users.role', ['id' => $u['id']])) ?>"><?= csrf_field() ?>
                            <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
                            <input type="hidden" name="redirect" value="<?= e(current_url()) ?>">
                            <button class="btn btn--ghost btn--sm" type="submit"><?= e($u['role'] === 'admin' ? __('admin.make_user') : __('admin.make_admin')) ?></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= component('pagination', ['paginator' => $users, 'pageUrl' => $pageUrl]) ?>
<?php else: ?>
    <?= component('empty-state', ['icon' => 'users', 'title' => __('admin.users_empty')]) ?>
<?php endif; ?>

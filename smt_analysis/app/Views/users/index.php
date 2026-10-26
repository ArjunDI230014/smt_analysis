<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php
$formState = session()->getFlashdata('user_form') ?? [];
$reopen = ($formState['mode'] ?? '') === 'create' ? 'user-create' : (($formState['mode'] ?? '') === 'edit' ? 'user-actions-' . (int) $formState['id'] : '');
$createValues = ($formState['mode'] ?? '') === 'create' ? $formState['values'] : [];
$createErrors = ($formState['mode'] ?? '') === 'create' ? $formState['errors'] : [];
$icon = static function (string $name, int $size = 20): string {
    $paths = [
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>',
        'active' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'inactive' => '<circle cx="12" cy="12" r="9"/><path d="M9 9v6m6-6v6"/>',
        'archive' => '<rect x="3" y="3" width="18" height="4" rx="1"/><path d="M5 7v13h14V7m-10 5h6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'arrow' => '<path d="m9 6 6 6-6 6"/>',
        'shield' => '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
    ];
    return '<svg aria-hidden="true" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $paths[$name] . '</svg>';
};
?>
<link rel="stylesheet" href="<?= base_url('assets/users.css') ?>?v=<?= filemtime(FCPATH . 'assets/users.css') ?>">
<div id="user-management" data-user-reopen="<?= esc($reopen, 'attr') ?>">
    <div class="page-heading user-page-heading">
        <div><p class="eyebrow">WORKSPACE / TEAM</p><h1>User management</h1><p class="muted">A clear view of your team and who has access.</p></div>
        <details id="user-create" class="user-create">
            <summary class="button"><?= $icon('plus', 17) ?> Add user</summary>
            <div class="user-management-content">
                <header class="user-dialog-heading"><div><p class="eyebrow">GROW YOUR TEAM</p><h2 id="create-user-title">Add a new user</h2><p>Give a team member access to your workspace.</p></div><button class="user-close" type="button" data-user-close aria-label="Close add user" hidden><?= $icon('close') ?></button></header>
                <form method="post" action="<?= site_url('admin/users') ?>" class="user-account-form">
                    <?= csrf_field() ?>
                    <div class="user-form-fields">
                        <label for="create-name">Full name<input id="create-name" name="name" required maxlength="120" autocomplete="name" placeholder="e.g. Alex Morgan" value="<?= esc($createValues['name'] ?? '', 'attr') ?>" <?= isset($createErrors['name']) ? 'aria-invalid="true" aria-describedby="create-name-error"' : '' ?>></label>
                        <?php if (isset($createErrors['name'])): ?><p class="user-field-error" id="create-name-error"><?= esc($createErrors['name']) ?></p><?php endif ?>
                        <label for="create-email">Email address<input id="create-email" name="email" type="email" required maxlength="190" autocomplete="email" placeholder="alex@company.com" value="<?= esc($createValues['email'] ?? '', 'attr') ?>" <?= isset($createErrors['email']) ? 'aria-invalid="true" aria-describedby="create-email-error"' : '' ?>></label>
                        <?php if (isset($createErrors['email'])): ?><p class="user-field-error" id="create-email-error"><?= esc($createErrors['email']) ?></p><?php endif ?>
                        <label for="create-password">Password</label><div class="user-password-field"><input id="create-password" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="create-password-help<?= isset($createErrors['password']) ? ' create-password-error' : '' ?>"><button type="button" data-user-password-toggle aria-controls="create-password" aria-pressed="false">Show</button></div>
                        <p id="create-password-help" class="user-field-hint">Use 12–72 bytes. Share the password privately with your team member.</p>
                        <?php if (isset($createErrors['password'])): ?><p class="user-field-error" id="create-password-error"><?= esc($createErrors['password']) ?></p><?php endif ?>
                        <fieldset class="user-role-options"><legend>Access level</legend>
                            <?php foreach (['user' => ['User', 'Explore dashboards and reports'], 'admin' => ['Administrator', 'Manage data, connections, and users']] as $value => $option): ?>
                            <label class="user-role-option"><input type="radio" name="role" value="<?= $value ?>" <?= ($createValues['role'] ?? 'user') === $value ? 'checked' : '' ?> required><span><strong><?= $option[0] ?></strong><small><?= $option[1] ?></small></span></label>
                            <?php endforeach ?>
                        </fieldset>
                        <?php if (isset($createErrors['role'])): ?><p class="user-field-error"><?= esc($createErrors['role']) ?></p><?php endif ?>
                    </div>
                    <footer class="user-form-footer"><button type="button" class="button secondary" data-user-close hidden>Cancel</button><button type="submit" class="button">Create account <?= $icon('arrow', 15) ?></button></footer>
                </form>
            </div>
        </details>
    </div>

    <div class="user-overview" aria-label="Workspace account totals">
        <?php foreach (['all' => ['Total users', 'total', 'users', 'Across your workspace'], 'active' => ['Active', 'active', 'active', 'Can access the workspace'], 'inactive' => ['Inactive', 'inactive', 'inactive', 'Access temporarily paused'], 'archived' => ['Archived', 'archived', 'archive', 'Kept for your records']] as $key => [$label, $stat, $symbol, $description]): ?>
        <a class="user-stat user-stat-<?= $key ?>" href="<?= esc(site_url('admin/users') . ($key === 'all' ? '' : '?status=' . $key)) ?>" data-user-filter-link <?= $status === $key ? 'aria-current="true"' : '' ?>>
            <span class="user-stat-label"><?= $label ?><span class="user-stat-icon"><?= $icon($symbol, 18) ?></span></span><strong><?= number_format($stats[$stat]) ?></strong><span class="user-stat-description"><?= $description ?></span>
        </a>
        <?php endforeach ?>
    </div>

    <section class="panel user-directory" aria-labelledby="members-heading">
        <div class="user-directory-heading"><div><h2 id="members-heading">Team directory <span id="user-match-count" class="user-count"><?= number_format($total) ?> <?= $total === 1 ? 'user' : 'users' ?></span></h2><p>Find a team member to update their details or access.</p></div><span class="user-shared-note"><?= $icon('shield', 15) ?> Shared across both modules</span></div>
        <form class="user-directory-filters" method="get" action="<?= site_url('admin/users') ?>">
            <label class="user-search-field" for="user-search"><span class="sr-only">Search users</span><?= $icon('search', 18) ?><input id="user-search" type="search" name="q" value="<?= esc($search, 'attr') ?>" placeholder="Search by name or email…" maxlength="190" autocomplete="off" aria-controls="user-results" aria-describedby="user-search-feedback"></label>
            <label for="user-role">Role<select id="user-role" name="role"><?php foreach (['all' => 'All roles', 'admin' => 'Administrator', 'user' => 'User'] as $value => $label): ?><option value="<?= $value ?>" <?= $role === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
            <label for="user-status">Status<select id="user-status" name="status"><?php foreach (['all' => 'All statuses', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $value => $label): ?><option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
            <button class="button secondary user-search-submit" type="submit">Search</button><a class="text-link user-reset" href="<?= site_url('admin/users') ?>" data-user-reset>Reset</a>
        </form>
        <p id="user-search-feedback" class="user-search-feedback" role="status" aria-live="polite">Search results update as you type.</p>
        <div id="user-results" aria-busy="false">
            <?php if ($users): ?>
            <div class="user-table-scroll"><table class="user-table">
                <caption class="sr-only">Workspace users, sorted by name</caption>
                <thead><tr><th scope="col">Team member</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col" class="user-joined">Date added</th><th scope="col"><span class="sr-only">Account actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <?php
                    $self = (int) $user['id'] === (int) session('user_id');
                    $accountStatus = $user['archived'] ? 'Archived' : ($user['active'] ? 'Active' : 'Inactive');
                    $nameParts = preg_split('/\s+/u', trim($user['name']));
                    $initials = mb_strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));
                    ?>
                    <tr>
                        <td><div class="user-member-identity"><span class="avatar user-avatar<?= $user['role'] === 'admin' ? ' user-avatar-admin' : '' ?>" aria-hidden="true"><?= esc($initials) ?></span><div><span class="user-member-name"><?= esc($user['name']) ?><?php if ($self): ?><span class="user-you">You</span><?php endif ?></span><span class="user-member-email"><?= esc($user['email']) ?></span></div></div></td>
                        <td><span class="user-role-badge<?= $user['role'] === 'admin' ? ' user-role-admin' : '' ?>"><?= $user['role'] === 'admin' ? $icon('shield', 13) . ' Administrator' : 'User' ?></span></td>
                        <td><span class="user-status user-status-<?= strtolower($accountStatus) ?>"><?= $accountStatus ?></span></td>
                        <td class="user-joined"><time datetime="<?= esc(substr($user['created_at'], 0, 10), 'attr') ?>"><?= esc(date('M j, Y', strtotime($user['created_at']))) ?></time></td>
                        <td class="user-member-action-cell"><?= view('users/editor', compact('user', 'self', 'accountStatus', 'formState', 'icon')) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php else: ?>
            <div class="user-empty"><span class="user-empty-icon"><?= $icon('search', 26) ?></span><h3>No matching accounts</h3><p>Try a different name or email, or adjust your filters.</p><a class="button secondary small-button" href="<?= site_url('admin/users') ?>" data-user-reset>Clear filters</a></div>
            <?php endif ?>
            <nav class="pagination user-directory-pagination" aria-label="User directory pages"><span><?= $total ? 'Showing ' . number_format(($page - 1) * $perPage + 1) . '–' . number_format(min($page * $perPage, $total)) . ' of ' . number_format($total) . ' users' : '0 users' ?></span><div><span class="user-page-number">Page <?= $page ?> of <?= $pages ?></span>
                <?php foreach ([-1 => 'Previous', 1 => 'Next'] as $offset => $label): ?>
                    <?php if ($page + $offset >= 1 && $page + $offset <= $pages): ?><a class="button secondary small-button" href="<?= esc(site_url('admin/users') . '?' . http_build_query(['q' => $search, 'role' => $role, 'status' => $status, 'page' => $page + $offset])) ?>"><?= $label ?></a><?php else: ?><span class="button secondary small-button user-page-disabled" aria-disabled="true"><?= $label ?></span><?php endif ?>
                <?php endforeach ?>
            </div></nav>
        </div>
    </section>
    <p class="user-permissions-note"><?= $icon('shield', 15) ?><span><strong>Access, made clear.</strong> Administrators manage the workspace. Users can explore dashboards and reports.</span></p>
    <?php if ($recoveryUser ?? null): ?>
        <?php $user = $recoveryUser; $self = (int) $user['id'] === (int) session('user_id'); $accountStatus = $user['archived'] ? 'Archived' : ($user['active'] ? 'Active' : 'Inactive'); ?>
        <div class="user-recovery"><?= view('users/editor', compact('user', 'self', 'accountStatus', 'formState', 'icon')) ?></div>
    <?php endif ?>
</div>
<dialog id="user-editor" class="user-dialog"><div id="user-editor-content"></div></dialog>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/user-search.js') ?>?v=<?= filemtime(FCPATH . 'assets/user-search.js') ?>" defer></script>
<?= $this->endSection() ?>

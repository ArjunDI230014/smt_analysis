<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-heading"><div><p class="eyebrow">WORKSPACE ACCESS</p><h1>The right view for your team.</h1><p class="muted">Administrators manage reports and accounts. Users can explore dashboards.</p></div></div>
<section class="panel"><div class="panel-heading"><div><h2>Create an account</h2><p>Use a unique password of at least 12 characters.</p></div></div><form class="user-form" method="post" action="<?= site_url('admin/users') ?>"><?= csrf_field() ?><label>Full name<input name="name" required maxlength="120" autocomplete="name"></label><label>Email address<input name="email" type="email" required maxlength="190" autocomplete="email"></label><label>Password<input name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></label><label>Access level<select name="role"><option value="user">User · view dashboards</option><option value="admin">Admin · full access</option></select></label><button class="button" type="submit">Create account</button></form></section>
<section class="user-directory" aria-labelledby="members-heading">
<div class="section-heading"><div><h2 id="members-heading">Team members</h2><p class="muted small">Manage passwords and account access in one place.</p></div><span class="tag" id="user-match-count"><?= number_format($total) ?> matching accounts</span></div>
<form class="panel user-directory-filters" method="get" action="<?= site_url('admin/users') ?>">
<label for="user-search">Find a user<input id="user-search" type="search" name="q" value="<?= esc($search) ?>" placeholder="Start typing a name or email…" maxlength="190" autocomplete="off" aria-controls="user-results" aria-describedby="user-search-feedback"></label>
<label for="user-status">Account status<select id="user-status" name="status"><?php foreach (['all' => 'All accounts', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $value => $label): ?><option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
<button class="button" type="submit">Search</button><a class="text-link" href="<?= site_url('admin/users') ?>">Reset</a>
</form>
<p id="user-search-feedback" class="small muted" role="status" aria-live="polite">Matches update as you type.</p>
<div id="user-results">
<?php if ($total): ?><p class="small muted">Showing <?= ($page - 1) * $perPage + 1 ?>–<?= min($page * $perPage, $total) ?> of <?= number_format($total) ?> · Sorted by name</p><?php endif ?>
<div class="user-card-grid">
<?php foreach ($users as $user): ?>
<?php $self = (int) $user['id'] === (int) session('user_id'); $accountStatus = $user['archived'] ? 'Archived' : ($user['active'] ? 'Active' : 'Inactive'); ?>
<article class="panel user-card">
<header class="user-card-header">
<span class="avatar" aria-hidden="true"><?= esc(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
<div class="user-card-identity"><h3><?= esc($user['name']) ?></h3><p><?= esc($user['email']) ?></p><span class="small muted"><?= esc(ucfirst($user['role'])) ?><?= $self ? ' · Your account' : '' ?></span></div>
<span class="user-status user-status-<?= strtolower($accountStatus) ?>"><?= $accountStatus ?></span>
</header>
<div class="user-card-body">
<details class="user-action-box password-action">
<summary><span>Change password<small>Set a new password for this account</small></span><span class="disclosure-icon" aria-hidden="true">＋</span></summary>
<form class="user-password-form" method="post" action="<?= site_url('admin/users/' . $user['id'] . '/password') ?>">
<?= csrf_field() ?>
<label for="password-<?= $user['id'] ?>">New password<input id="password-<?= $user['id'] ?>" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-help-<?= $user['id'] ?>"></label>
<label for="confirm-<?= $user['id'] ?>">Confirm password<input id="confirm-<?= $user['id'] ?>" name="password_confirmation" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
<p id="password-help-<?= $user['id'] ?>" class="small muted">Use 12–72 bytes. Share the new password privately.</p>
<button class="button small-button" type="submit">Save password</button>
</form></details>
<?php if (!$self): ?>
<div class="user-action-box user-access-box"><div><h4>Account access</h4><p><?= $user['archived'] ? 'Restore this account to its previous access status.' : 'Pause access or archive this account for later.' ?></p></div>
<div class="user-access-buttons">
<?php if (!$user['archived']): ?>
<form method="post" action="<?= site_url('admin/users/' . $user['id'] . '/toggle') ?>"><?= csrf_field() ?><button class="button secondary small-button" type="submit"><?= $user['active'] ? 'Deactivate' : 'Activate' ?></button></form>
<?php endif ?>
<form method="post" action="<?= site_url('admin/users/' . $user['id'] . ($user['archived'] ? '/restore' : '/archive')) ?>"><?= csrf_field() ?><button class="button secondary small-button" type="submit"><?= $user['archived'] ? 'Restore account' : 'Archive account' ?></button></form>
</div></div>
<details class="user-action-box user-delete-box"><summary><span>Delete account<small>Permanently remove this account</small></span><span class="disclosure-icon" aria-hidden="true">＋</span></summary>
<div class="user-delete-confirm"><p>Delete <strong><?= esc($user['email']) ?></strong>? This cannot be undone. Imported reports will be preserved.</p>
<form method="post" action="<?= site_url('admin/users/' . $user['id'] . '/delete') ?>"><?= csrf_field() ?><button class="button secondary danger-button small-button" type="submit">Confirm permanent deletion</button></form></div></details>
<?php else: ?><div class="user-self-note">You can change your password here. Your own account cannot be archived or deleted.</div><?php endif ?>
</div></article>
<?php endforeach ?>
</div>
<?php if (!$users): ?><section class="panel empty"><h3>No matching accounts</h3><p>Try another name, email, or account status.</p><a class="text-link" href="<?= site_url('admin/users') ?>">Clear filters</a></section><?php endif ?>
<?php if ($pages > 1): ?>
<nav class="pagination user-directory-pagination" aria-label="User directory pages"><span>Page <?= $page ?> of <?= $pages ?></span><div>
<?php if ($page > 1): ?><a class="button secondary small-button" href="<?= esc(site_url('admin/users') . '?' . http_build_query(['q' => $search, 'status' => $status, 'page' => $page - 1])) ?>">← Previous</a><?php endif ?>
<?php if ($page < $pages): ?><a class="button secondary small-button" href="<?= esc(site_url('admin/users') . '?' . http_build_query(['q' => $search, 'status' => $status, 'page' => $page + 1])) ?>">Next →</a><?php endif ?>
</div></nav>
<?php endif ?>
</div>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/user-search.js') ?>" defer></script>
<?= $this->endSection() ?>

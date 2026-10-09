<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?> · Summit Analytics</title>
    <link rel="icon" type="image/png" href="<?= base_url('assets/images/summit-logo.png') ?>">
    <script src="<?= base_url('assets/theme.js') ?>?v=<?= filemtime(FCPATH . 'assets/theme.js') ?>"></script>
    <link rel="stylesheet" href="<?= base_url('assets/app.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/enhancements.css') ?>?v=<?= filemtime(FCPATH . 'assets/enhancements.css') ?>">
    <script src="<?= base_url('assets/workspace.js') ?>?v=<?= filemtime(FCPATH . 'assets/workspace.js') ?>" defer></script>
</head>
<body>
<?php $currentUser = session('user'); $path = trim(uri_string(), '/'); ?>
<?php ob_start(); ?>
<div class="appearance-controls" role="group" aria-label="Appearance">
    <button class="theme-toggle" id="theme-toggle" type="button" title="Switch appearance"><span class="theme-icon" aria-hidden="true">☾</span><span class="theme-label">Dark mode</span></button>
</div>
<?php $appearanceControls = ob_get_clean(); ?>
<a class="skip-link" href="#main-content">Skip to content</a>
<?php if (!$currentUser): ?><div class="auth-appearance"><?= $appearanceControls ?></div><?php endif ?>
<?php if ($currentUser): ?>
<aside class="sidebar">
    <a class="brand brand-logo" href="<?= site_url('/') ?>" aria-label="Summit Analytics home"><img src="<?= base_url('assets/images/summit-logo.png') ?>" alt="Summit" width="136" height="50"><span class="brand-caption">CSAT ANALYTICS</span></a>
    <p class="nav-caption">WORKSPACE</p>
    <button class="nav-toggle" id="nav-toggle" type="button" aria-expanded="false" aria-controls="workspace-navigation" aria-label="Open navigation" hidden><svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg><span>Menu</span></button>
    <nav id="workspace-navigation" aria-label="Main navigation">
        <?php $overviewActive = in_array($path, ['', 'dashboard'], true) || str_starts_with($path, 'admin/agents/'); ?>
        <a class="<?= $overviewActive ? 'active' : '' ?>" <?= $overviewActive ? 'aria-current="page"' : '' ?> href="<?= site_url('dashboard') ?>"><svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg> Overview</a>
        <?php if ($currentUser['role'] === 'admin'): ?>
        <a class="<?= $path === 'admin/uploads' ? 'active' : '' ?>" <?= $path === 'admin/uploads' ? 'aria-current="page"' : '' ?> href="<?= site_url('admin/uploads') ?>"><svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V3m-5 5 5-5 5 5M4 15v5a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-5"/></svg> Upload reports</a>
        <a class="<?= $path === 'admin/users' ? 'active' : '' ?>" <?= $path === 'admin/users' ? 'aria-current="page"' : '' ?> href="<?= site_url('admin/users') ?>"><svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v2"/></svg> Manage users</a>
        <?php endif ?>
    </nav>
    <div class="sidebar-note"><span class="status-dot"></span> Daily CSAT workspace<p>One worksheet.<br>A clearer view of your service.</p></div>
    <div class="profile"><span class="avatar"><?= esc(mb_strtoupper(mb_substr($currentUser['name'], 0, 1))) ?></span><div><strong><?= esc($currentUser['name']) ?></strong><small><?= $currentUser['role'] === 'admin' ? 'Administrator' : 'Viewer' ?></small></div></div>
    <form class="signout-form" action="<?= site_url('logout') ?>" method="post"><?= csrf_field() ?><button class="signout" type="submit" aria-label="Sign out" title="Sign out"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H5v14h4M9 12h12m-4-4 4 4-4 4"/></svg><span>Sign out</span></button></form>
</aside>
<?php endif ?>
<main class="<?= $currentUser ? 'main' : 'auth-main' ?>" id="main-content" tabindex="-1">
    <?php if ($currentUser): ?><header class="topbar"><span class="breadcrumb">Workspace <span class="muted">/</span> <strong><?= esc($title) ?></strong></span><div class="topbar-actions"><span class="access-badge"><?= $currentUser['role'] === 'admin' ? 'Admin access' : 'View access' ?></span><?= $appearanceControls ?></div></header><?php endif ?>
    <div class="<?= $currentUser ? 'content' : '' ?>">
        <?php foreach (['error', 'success'] as $flash): ?>
            <?php if (session()->getFlashdata($flash)): ?><div role="alert" class="notice <?= $flash ?>"><?= esc(session()->getFlashdata($flash)) ?></div><?php endif ?>
        <?php endforeach ?>
        <?= $this->renderSection('content') ?>
    </div>
</main>
<?= $this->renderSection('scripts') ?>
</body>
</html>

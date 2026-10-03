<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?> · Summit Analytics</title>
    <link rel="icon" type="image/png" href="<?= base_url('assets/images/summit-logo.png') ?>">
    <script src="<?= base_url('assets/theme.js') ?>"></script>
    <link rel="stylesheet" href="<?= base_url('assets/app.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/enhancements.css') ?>">
</head>
<body>
<button class="theme-toggle" id="theme-toggle" type="button" aria-pressed="false"><span class="theme-icon" aria-hidden="true">☾</span><span class="theme-label">Dark mode</span></button>
<?php $currentUser = session('user'); $path = trim(uri_string(), '/'); ?>
<?php if ($currentUser): ?>
<aside class="sidebar">
    <a class="brand brand-logo" href="<?= site_url('/') ?>" aria-label="Summit Analytics home"><img src="<?= base_url('assets/images/summit-logo.png') ?>" alt="Summit" width="136" height="50"><span class="brand-caption">CSAT ANALYTICS</span></a>
    <p class="nav-caption">WORKSPACE</p>
    <nav aria-label="Main navigation">
        <a class="<?= in_array($path, ['', 'dashboard'], true) ? 'active' : '' ?>" href="<?= site_url('dashboard') ?>"><span aria-hidden="true">▦</span> Overview</a>
        <?php if ($currentUser['role'] === 'admin'): ?>
        <a class="<?= $path === 'admin/uploads' ? 'active' : '' ?>" href="<?= site_url('admin/uploads') ?>"><span aria-hidden="true">↥</span> Upload reports</a>
        <a class="<?= $path === 'admin/users' ? 'active' : '' ?>" href="<?= site_url('admin/users') ?>"><span aria-hidden="true">♙</span> Manage users</a>
        <?php endif ?>
    </nav>
    <div class="sidebar-note"><span class="status-dot"></span> Daily CSAT workspace<p>One worksheet.<br>A clearer view of your service.</p></div>
    <div class="profile"><span class="avatar"><?= esc(mb_strtoupper(mb_substr($currentUser['name'], 0, 1))) ?></span><div><strong><?= esc($currentUser['name']) ?></strong><small><?= $currentUser['role'] === 'admin' ? 'Administrator' : 'Viewer' ?></small></div></div>
    <form class="signout-form" action="<?= site_url('logout') ?>" method="post"><?= csrf_field() ?><button class="signout" type="submit"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H5v14h4M9 12h12m-4-4 4 4-4 4"/></svg><span>Sign out</span></button></form>
</aside>
<?php endif ?>
<main class="<?= $currentUser ? 'main' : 'auth-main' ?>">
    <?php if ($currentUser): ?><header class="topbar"><span>Workspace <span class="muted">/</span> <?= esc($title) ?></span><span class="access-badge"><?= $currentUser['role'] === 'admin' ? 'Admin access' : 'View access' ?></span></header><?php endif ?>
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

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?> · SMT Analytics</title>
    <script src="<?= base_url('assets/theme.js') ?>"></script>
    <link rel="stylesheet" href="<?= base_url('assets/app.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/enhancements.css') ?>">
</head>
<body>
<button class="theme-toggle" id="theme-toggle" type="button" aria-pressed="false">Dark mode</button>
<?php $currentUser = session('user'); $path = trim(uri_string(), '/'); ?>
<?php if ($currentUser): ?>
<aside class="sidebar">
    <a class="brand" href="<?= site_url('/') ?>"><span class="brand-mark">s.</span><span>SMT <strong>Analytics</strong><small>CUSTOMER EXPERIENCE</small></span></a>
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
    <form action="<?= site_url('logout') ?>" method="post"><?= csrf_field() ?><button class="signout" type="submit">Sign out ↗</button></form>
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

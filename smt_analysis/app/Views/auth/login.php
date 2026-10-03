<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="login-layout">
    <section class="login-story"><div class="brand brand-logo light"><img src="<?= base_url('assets/images/summit-logo.png') ?>" alt="Summit" width="136" height="50"><span class="brand-caption">CSAT ANALYTICS</span></div><div><p class="eyebrow">LISTEN. UNDERSTAND. IMPROVE.</p><h1>Every response<br>tells a story.</h1><p>Turn your daily customer feedback into a clear picture of service performance.</p><div class="story-bars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div></div><small>Daily CSAT · Customer experience analytics</small></section>
    <section class="login-form"><p class="eyebrow">YOUR ANALYTICS WORKSPACE</p><h2>Welcome back</h2><p class="muted">Sign in to explore your team's customer satisfaction.</p><form method="post" action="<?= site_url('login') ?>"><?= csrf_field() ?><label>Email address<input name="email" type="email" required maxlength="190" autocomplete="username" placeholder="you@company.com"></label><label>Password<span class="password-field"><input id="auth-password" name="password" type="password" required autocomplete="current-password" placeholder="Enter your password"><button class="password-toggle" type="button" aria-controls="auth-password" aria-pressed="false" aria-label="Show password">Show</button></span></label><button class="button full" type="submit">Sign in <span>→</span></button></form><p class="small muted">Need access? Register using your sheet email.</p><a class="button secondary full" href="<?= site_url('register') ?>">Create account</a></section>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/auth.js') ?>" defer></script>
<?= $this->endSection() ?>

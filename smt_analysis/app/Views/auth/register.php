<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="login-layout">
<section class="login-story"><div class="brand brand-logo light"><img src="<?= base_url('assets/images/summit-logo.png') ?>" alt="Summit" width="136" height="50"><span class="brand-caption">CSAT ANALYTICS</span></div><div><p class="eyebrow">YOUR TEAM WORKSPACE</p><h1>Your account.<br>Your insights.</h1><p>Create an account using the agent email listed in your team's report.</p></div></section>
<section class="login-form">
<h2>Create your account</h2><p class="muted">Enter your sheet email and choose a password. Your name will come from your team's report.</p>
<form method="post" action="<?= site_url('register') ?>"><?= csrf_field() ?><label>Agent email<input type="email" name="email" maxlength="190" required autocomplete="email" placeholder="you@company.com"></label><label>Password<span class="password-field"><input id="auth-password" type="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password"><button class="password-toggle" type="button" aria-controls="auth-password" aria-pressed="false" aria-label="Show password">Show</button></span></label><label>Confirm password<span class="password-field"><input id="auth-password_confirmation" type="password" name="password_confirmation" minlength="12" maxlength="72" required autocomplete="new-password"><button class="password-toggle" type="button" aria-controls="auth-password_confirmation" aria-pressed="false" aria-label="Show password confirmation">Show</button></span></label><p class="small muted">Use at least 12 characters.</p><button class="button full" type="submit">Create account</button></form>
<p class="small muted">Already have an account?</p><a class="button secondary full" href="<?= site_url('login') ?>">Sign in</a>
</section></div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/auth.js') ?>" defer></script>
<?= $this->endSection() ?>

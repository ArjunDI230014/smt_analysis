<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="login-layout">
<section class="login-story"><div class="brand brand-logo light"><img src="<?= base_url('assets/images/summit-logo.png') ?>" alt="Summit" width="136" height="50"><span class="brand-caption">CSAT ANALYTICS</span></div><div><p class="eyebrow">YOUR TEAM WORKSPACE</p><h1>Your account.<br>Your insights.</h1><p>Create an account using the agent email listed in your team's report.</p></div></section>
<section class="login-form">
<h2>Create your account</h2><p class="muted">Enter your sheet email and choose a password. Your name will come from your team's report.</p>
<form method="post" action="<?= site_url('register') ?>"><?= csrf_field() ?><label>Agent email<input type="email" name="email" maxlength="190" required autocomplete="email" placeholder="you@company.com"></label><label>Password<input type="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password"></label><label>Confirm password<input type="password" name="password_confirmation" minlength="12" maxlength="72" required autocomplete="new-password"></label><p class="small muted">Use at least 12 characters.</p><button class="button full" type="submit">Create account</button></form>
<p class="small muted">Already have an account? <a href="<?= site_url('login') ?>">Sign in</a></p>
</section></div>
<?= $this->endSection() ?>

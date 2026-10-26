<?php
$id = (int) $user['id'];
$editing = ($formState['mode'] ?? '') === 'edit' && (int) ($formState['id'] ?? 0) === $id;
$values = $editing ? $formState['values'] : $user;
$errors = $editing ? $formState['errors'] : [];
?>
<details id="user-actions-<?= $id ?>" class="user-member-actions" data-user-name="<?= esc($user['name'], 'attr') ?>">
    <summary class="button secondary small-button" aria-label="Manage <?= esc($user['name'], 'attr') ?>">Manage <?= $icon('arrow', 13) ?></summary>
    <div class="user-management-content">
        <header class="user-dialog-heading"><div><p class="eyebrow">ACCOUNT SETTINGS</p><h2 id="user-title-<?= $id ?>">Manage account</h2><p><?= esc($user['email']) ?><?= $self ? ' · Your account' : '' ?></p></div><button class="user-close" type="button" data-user-close aria-label="Close account settings" hidden><?= $icon('close') ?></button></header>
        <div class="user-editor-identity"><span class="avatar user-avatar" aria-hidden="true"><?= esc(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span><div><strong><?= esc($user['name']) ?></strong><span><?= $user['role'] === 'admin' ? 'Administrator' : 'User' ?></span></div><span class="user-status user-status-<?= strtolower($accountStatus) ?>"><?= $accountStatus ?></span></div>
        <form class="user-account-form" method="post" action="<?= site_url('admin/users/' . $id . '/update') ?>">
            <?= csrf_field() ?>
            <div class="user-form-fields">
                <h3>Account details</h3>
                <label for="name-<?= $id ?>">Full name<input id="name-<?= $id ?>" name="name" value="<?= esc($values['name'], 'attr') ?>" maxlength="120" required autocomplete="name" <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="name-error-' . $id . '"' : '' ?>></label>
                <?php if (isset($errors['name'])): ?><p class="user-field-error" id="name-error-<?= $id ?>"><?= esc($errors['name']) ?></p><?php endif ?>
                <label for="email-<?= $id ?>">Email address<input id="email-<?= $id ?>" name="email" type="email" value="<?= esc($values['email'], 'attr') ?>" maxlength="190" required autocomplete="email" <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error-' . $id . '"' : '' ?>></label>
                <?php if (isset($errors['email'])): ?><p class="user-field-error" id="email-error-<?= $id ?>"><?= esc($errors['email']) ?></p><?php endif ?>
                <label for="role-<?= $id ?>">Access level<select id="role-<?= $id ?>" name="role" data-native-control <?= $self ? 'aria-describedby="self-role-' . $id . '"' : '' ?>><option value="user" <?= $values['role'] === 'user' ? 'selected' : '' ?> <?= $self ? 'disabled' : '' ?>>User · view dashboards and reports</option><option value="admin" <?= $values['role'] === 'admin' ? 'selected' : '' ?>>Administrator · full workspace access</option></select></label>
                <?php if ($self): ?><p class="user-field-hint" id="self-role-<?= $id ?>">Your administrator access is protected. Another administrator can change your role.</p><?php endif ?>
                <?php if (isset($errors['role'])): ?><p class="user-field-error"><?= esc($errors['role']) ?></p><?php endif ?>
            </div>
            <footer class="user-form-footer"><button type="button" class="button secondary" data-user-close hidden>Cancel</button><button type="submit" class="button">Save changes</button></footer>
        </form>
        <div class="user-secondary-actions">
            <details class="user-action-box password-action"><summary><span>Reset password<small>Set a new password for this account</small></span><span class="disclosure-icon" aria-hidden="true">＋</span></summary>
                <form class="user-password-form" method="post" action="<?= site_url('admin/users/' . $id . '/password') ?>">
                    <?= csrf_field() ?>
                    <label for="password-<?= $id ?>">New password</label><div class="user-password-field"><input id="password-<?= $id ?>" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-help-<?= $id ?>"><button type="button" data-user-password-toggle aria-controls="password-<?= $id ?>" aria-pressed="false">Show</button></div>
                    <label for="confirm-<?= $id ?>">Confirm new password<input id="confirm-<?= $id ?>" name="password_confirmation" type="password" required minlength="12" maxlength="72" autocomplete="new-password" data-password-confirm="password-<?= $id ?>"></label>
                    <p id="password-help-<?= $id ?>" class="small muted">Use 12–72 bytes. Share the new password privately.</p><button class="button secondary small-button" type="submit">Update password</button>
                </form>
            </details>
            <?php if (!$self): ?>
                <section class="user-access-section"><div><h3>Account access</h3><p><?= $user['archived'] ? 'Restoring returns this account to its previous access status.' : ($user['active'] ? 'Deactivate to pause sign-in. You can reactivate the account anytime.' : 'This account cannot sign in. Activate it to restore access.') ?></p></div><div class="user-access-buttons">
                    <?php if (!$user['archived']): ?><form method="post" action="<?= site_url('admin/users/' . $id . '/toggle') ?>"><?= csrf_field() ?><button class="button secondary small-button" type="submit"><?= $user['active'] ? 'Deactivate user' : 'Activate user' ?></button></form><?php endif ?>
                    <?php if ($user['archived']): ?><form method="post" action="<?= site_url('admin/users/' . $id . '/restore') ?>"><?= csrf_field() ?><button class="button secondary small-button" type="submit">Restore account</button></form><?php endif ?>
                </div></section>
                <?php if (!$user['archived']): ?><details class="user-action-box"><summary><span>Archive account<small>Block access and keep the account for later</small></span><span class="disclosure-icon" aria-hidden="true">＋</span></summary><div class="user-action-confirm"><p>Archive <?= esc($user['name']) ?>? They will lose access to the workspace. You can restore this account anytime.</p><form method="post" action="<?= site_url('admin/users/' . $id . '/archive') ?>"><?= csrf_field() ?><button class="button secondary small-button" type="submit">Confirm archive</button></form></div></details><?php endif ?>
                <details class="user-action-box user-delete-box"><summary><span>Delete account<small>Permanently remove this team member</small></span><span class="disclosure-icon" aria-hidden="true">＋</span></summary><div class="user-action-confirm"><p>Delete <strong><?= esc($user['email']) ?></strong>? Imported reports will be preserved. This cannot be undone.</p><form method="post" action="<?= site_url('admin/users/' . $id . '/delete') ?>"><?= csrf_field() ?><label class="user-delete-checkbox"><input type="checkbox" required> <span>I understand this permanently deletes the account.</span></label><button class="button danger-button small-button" type="submit">Permanently delete account</button></form></div></details>
            <?php else: ?><p class="user-self-note"><?= $icon('shield', 15) ?> You cannot deactivate, archive, or delete your own account.</p><?php endif ?>
        </div>
    </div>
</details>


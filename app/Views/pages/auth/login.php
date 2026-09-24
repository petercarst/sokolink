<?php
declare(strict_types=1);
/**
 * Log in.
 *
 * Note what is NOT here: no hint about which accounts exist. A login failure in
 * Phase 3 returns one generic message whether the email is unknown or the
 * password is wrong, because a different message for each is an account
 * enumeration oracle (FR-AUTH-09, USER_FLOWS.md Flow A).
 */
?>
<h1 class="t-display-md tw-mb-2">Log in</h1>
<p class="t-body-md t-muted tw-mb-8">
    Welcome back. Log in to see your orders, addresses and reorder list.
</p>

<form method="post" action="<?= e(route('auth.login.submit')) ?>" data-validate>
    <?= csrf_field() ?>

    <?= component('field', [
        'name' => 'email', 'label' => 'Email address', 'type' => 'email',
        'required' => true, 'autocomplete' => 'email',
        'placeholder' => 'you@example.co.tz',
    ]) ?>

    <div class="sl-field">
        <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-2">
            <label class="sl-label tw-mb-0" for="login-password">
                Password<span class="sl-required" aria-hidden="true">*</span>
                <span class="visually-hidden"> (required)</span>
            </label>
            <a class="t-micro sl-link-quiet tw-inline-block tw-py-1" href="<?= e(route('auth.forgot')) ?>">Forgotten it?</a>
        </div>

        <div class="tw-flex tw-gap-2">
            <input class="sl-input" type="password" id="login-password" name="password"
                   required autocomplete="current-password">
            <button class="sl-btn sl-btn-outline-light sl-btn-icon" type="button"
                    data-password-toggle="login-password" aria-pressed="false" aria-label="Show password">
                <?= component('icon', ['name' => 'eye', 'size' => 18]) ?>
            </button>
        </div>
    </div>

    <label class="sl-check tw-mb-6">
        <input type="checkbox" name="remember" value="1">
        <span class="sl-check-label">
            Keep me logged in on this device
            <span class="t-micro t-muted tw-block">Do not use this on a shared or public computer.</span>
        </span>
    </label>

    <button class="sl-btn sl-btn-primary sl-btn-lg sl-btn-block tw-mb-4" type="submit">Log in</button>
</form>

<p class="t-caption t-muted tw-text-center tw-mb-6">
    New here? <a href="<?= e(route('auth.register')) ?>">Create an account</a>
</p>

<hr class="sl-divider">

<p class="t-caption t-muted tw-text-center tw-mb-0">
    Want to sell on SokoLink?
    <a href="<?= e(route('auth.register.seller')) ?>">Apply to sell</a>
</p>

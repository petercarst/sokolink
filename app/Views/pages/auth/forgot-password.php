<?php
declare(strict_types=1);
/**
 * Request a password reset.
 *
 * The confirmation is deliberately identical whether or not the address is
 * registered. Saying "no account with that email" would let anyone test which
 * addresses have accounts here (USER_FLOWS.md Flow A).
 *
 * @var bool $sent
 */
?>
<?php if (!empty($sent)) : ?>

    <span class="sl-empty-icon" style="background:var(--c-status-success-bg);color:var(--c-status-success-fg)">
        <?= component('icon', ['name' => 'mail', 'size' => 26]) ?>
    </span>

    <h1 class="t-display-md tw-mt-6 tw-mb-4">Check your email</h1>

    <p class="t-body-md t-muted tw-mb-6">
        If that address has an account, we have sent a link to reset the password. It expires in
        60 minutes and can only be used once.
    </p>

    <p class="t-caption t-muted tw-mb-8">
        Nothing arrived? Check the spam folder, then try again. We show this same message whether or
        not the address is registered, so that nobody can use this page to find out who has an
        account here.
    </p>

    <a class="sl-btn sl-btn-primary sl-btn-block tw-mb-4" href="<?= e(route('auth.login')) ?>">
        Back to log in
    </a>

    <p class="t-caption t-muted tw-text-center tw-mb-0">
        <a href="<?= e(route('auth.forgot')) ?>">Send it again</a>
    </p>

<?php else : ?>

    <h1 class="t-display-md tw-mb-2">Reset your password</h1>
    <p class="t-body-md t-muted tw-mb-8">
        Enter the email address on your account and we will send you a link to set a new password.
    </p>

    <form method="post" action="<?= e(route('auth.forgot.submit')) ?>" data-validate>
        <?= csrf_field() ?>
    
        <?= component('field', [
            'name' => 'email', 'label' => 'Email address', 'type' => 'email',
            'required' => true, 'autocomplete' => 'email',
            'placeholder' => 'you@example.co.tz',
        ]) ?>

        <button class="sl-btn sl-btn-primary sl-btn-lg sl-btn-block tw-mb-4" type="submit">
            Send reset link
        </button>
    </form>

    <p class="t-caption t-muted tw-text-center tw-mb-0">
        Remembered it? <a href="<?= e(route('auth.login')) ?>">Log in</a>
    </p>

    <p class="t-micro t-muted tw-text-center tw-mt-6 tw-mb-0">
        <a class="sl-link-quiet" href="<?= e(route('auth.forgot')) ?>?sent=1">Preview the confirmation screen</a>
    </p>

<?php endif; ?>

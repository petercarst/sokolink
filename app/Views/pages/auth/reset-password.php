<?php
declare(strict_types=1);
/**
 * Set a new password from a reset link.
 *
 * In Phase 3 the token is single use, time limited, stored hashed, and setting
 * a new password destroys every other session for that account - because if the
 * reset was triggered by a compromise, leaving the attacker logged in defeats
 * the point (FR-AUTH-05).
 *
 * @var string $token
 * @var bool   $hasToken
 */
?>
<?php if (empty($hasToken)) : ?>

    <span class="sl-empty-icon" style="background:var(--c-status-warn-bg);color:var(--c-status-warn-fg)">
        <?= component('icon', ['name' => 'alert', 'size' => 26]) ?>
    </span>

    <h1 class="t-display-md tw-mt-6 tw-mb-4">This link is not valid</h1>

    <p class="t-body-md t-muted tw-mb-8">
        The reset link is missing its token, has already been used, or has expired. Reset links last
        60 minutes and work once.
    </p>

    <a class="sl-btn sl-btn-primary sl-btn-block tw-mb-4" href="<?= e(route('auth.forgot')) ?>">
        Request a new link
    </a>

    <p class="t-caption t-muted tw-text-center tw-mb-0">
        <a href="<?= e(route('auth.login')) ?>">Back to log in</a>
    </p>

<?php else : ?>

    <h1 class="t-display-md tw-mb-2">Choose a new password</h1>
    <p class="t-body-md t-muted tw-mb-8">
        Pick something you have not used on this account before.
    </p>

    <form method="post" action="<?= e(route('auth.reset.submit')) ?>" data-validate>
        <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="sl-field">
            <label class="sl-label" for="new-password">
                New password<span class="sl-required" aria-hidden="true">*</span>
                <span class="visually-hidden"> (required)</span>
            </label>
            <div class="tw-flex tw-gap-2">
                <input class="sl-input" type="password" id="new-password" name="password"
                       required minlength="10" autocomplete="new-password"
                       aria-describedby="new-password-help">
                <button class="sl-btn sl-btn-outline-light sl-btn-icon" type="button"
                        data-password-toggle="new-password" aria-pressed="false" aria-label="Show password">
                    <?= component('icon', ['name' => 'eye', 'size' => 18]) ?>
                </button>
            </div>
            <span class="sl-help" id="new-password-help">At least 10 characters.</span>
        </div>

        <?= component('field', [
            'name' => 'password_confirm', 'label' => 'Confirm new password', 'type' => 'password',
            'required' => true, 'autocomplete' => 'new-password',
        ]) ?>

        <div class="sl-alert sl-alert-info tw-mb-6">
            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'shield', 'size' => 18]) ?></span>
            <span class="t-caption">
                Setting a new password logs you out everywhere else on every device.
            </span>
        </div>

        <button class="sl-btn sl-btn-primary sl-btn-lg sl-btn-block tw-mb-4" type="submit">
            Set new password
        </button>
    </form>

    <p class="t-caption t-muted tw-text-center tw-mb-0">
        <a href="<?= e(route('auth.login')) ?>">Back to log in</a>
    </p>

<?php endif; ?>

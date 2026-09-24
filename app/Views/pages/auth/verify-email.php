<?php
declare(strict_types=1);
/**
 * Email verification.
 *
 * The state is decided by what happened to the token in the URL, not by a query
 * parameter: `success` means this request consumed a valid token, `expired`
 * means it consumed nothing.
 *
 * An unverified customer can browse and build a basket but cannot place an
 * order. That check lives in the service layer, not in this page (FR-AUTH-01).
 *
 * @var string      $state      pending|success|expired
 * @var string|null $email      the signed-in address, if there is one
 * @var bool        $canResend  a signed-in, still-unverified account
 */
?>
<?php if ($state === 'success') : ?>

    <span class="sl-empty-icon" style="background:var(--c-status-success-bg);color:var(--c-status-success-fg)">
        <?= component('icon', ['name' => 'check', 'size' => 26]) ?>
    </span>

    <h1 class="t-display-md tw-mt-6 tw-mb-4">Email confirmed</h1>

    <p class="t-body-md t-muted tw-mb-8">
        Your account is active. You can place orders, choose a collection point and track everything
        from your dashboard.
    </p>

    <a class="sl-btn sl-btn-aloe sl-btn-lg sl-btn-block tw-mb-3" href="<?= e(route('catalog.index')) ?>">
        Start shopping
    </a>
    <a class="sl-btn sl-btn-outline-light sl-btn-block" href="<?= e(route('home')) ?>">
        Back to the marketplace
    </a>

<?php elseif ($state === 'expired') : ?>

    <span class="sl-empty-icon" style="background:var(--c-status-warn-bg);color:var(--c-status-warn-fg)">
        <?= component('icon', ['name' => 'clock', 'size' => 26]) ?>
    </span>

    <h1 class="t-display-md tw-mt-6 tw-mb-4">That link has expired</h1>

    <p class="t-body-md t-muted tw-mb-8">
        Verification links last <?= e((string) config('security.verify_token_hours', 48)) ?> hours. Request a
        new one and we will email it straight away. The old link stops working either way.
    </p>

    <form method="post" action="<?= e(route('auth.verify.resend')) ?>">
        <?= csrf_field() ?>
        <button class="sl-btn sl-btn-primary sl-btn-lg sl-btn-block tw-mb-4" type="submit">
            Send a new link
        </button>
    </form>

    <p class="t-caption t-muted tw-text-center tw-mb-0">
        <a href="<?= e(route('auth.login')) ?>">Back to log in</a>
    </p>

<?php else : ?>

    <span class="sl-empty-icon" style="background:var(--c-status-info-bg);color:var(--c-status-info-fg)">
        <?= component('icon', ['name' => 'mail', 'size' => 26]) ?>
    </span>

    <h1 class="t-display-md tw-mt-6 tw-mb-4">Confirm your email</h1>

    <p class="t-body-md t-muted tw-mb-6">
        <?php if ($email !== null) : ?>
            We sent a link to <strong><?= e($email) ?></strong>. Open it to finish setting up your
            account.
        <?php else : ?>
            We sent a link to the address you registered with. Open it to finish setting up your
            account.
        <?php endif; ?>
    </p>

    <p class="t-caption t-muted tw-mb-8">
        You can browse and build a basket now, but you will need to confirm this address before you
        can place an order.
    </p>

    <?php if ($canResend) : ?>
        <form method="post" action="<?= e(route('auth.verify.resend')) ?>" class="tw-mb-4">
            <?= csrf_field() ?>
            <button class="sl-btn sl-btn-outline-light sl-btn-block" type="submit">
                Resend the email
            </button>
        </form>
    <?php else : ?>
        <p class="t-caption t-muted tw-mb-4">
            <a href="<?= e(route('auth.login')) ?>">Sign in</a> and we can send the link again.
        </p>
    <?php endif; ?>

    <a class="sl-btn sl-btn-ghost sl-btn-block tw-mb-6" href="<?= e(route('catalog.index')) ?>">
        Browse in the meantime
    </a>

<?php endif; ?>

<hr class="sl-divider">

<p class="t-micro t-muted tw-text-center tw-mb-0">
    Links last <?= e((string) config('security.verify_token_hours', 48)) ?> hours and can be used once.
</p>

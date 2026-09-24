<?php
declare(strict_types=1);
/**
 * 500.
 *
 * The user gets a generic message plus a reference id. The exception detail
 * goes to storage/logs/app.log against that same reference, so support can look
 * it up without anyone seeing a stack trace with a database path in it
 * (NFR-SEC-09).
 *
 * The full trace is rendered ONLY when APP_DEBUG is true, which is off in
 * production by configuration and verified by the production checklist.
 *
 * @var string|null    $reference
 * @var \Throwable|null $exception
 * @var bool|null      $isPreview
 */
?>
<section class="sl-section-air">
    <div class="sl-container-read sl-container tw-text-center">

        <?php if (!empty($isPreview)) : ?>
            <div class="tw-mb-8 tw-text-left">
                <?= component('devnote', [
                    'onDark' => true,
                    'text'   => 'Design preview of the 500 page. The real page is served by the error handler and carries a genuine log reference.',
                ]) ?>
            </div>
        <?php endif; ?>

        <p class="t-eyebrow t-muted-dark tw-mb-6">Error 500</p>

        <h1 class="t-display-lg tw-mb-6">Something went wrong on our side.</h1>

        <p class="t-body-lg t-muted-dark tw-mb-8" style="max-width:46ch;margin-inline:auto">
            <?= e($message ?? 'An unexpected error occurred. Nothing you did caused this.') ?>
            Your basket and any order already placed are unaffected.
        </p>

        <?php if (!empty($reference)) : ?>
            <p class="t-caption t-muted-dark tw-mb-10">
                Quote this reference if you contact support:
                <span class="t-code tw-ml-2" style="color:var(--c-on-dark)"><?= e($reference) ?></span>
            </p>
        <?php endif; ?>

        <div class="tw-flex tw-flex-wrap tw-gap-4 tw-justify-center">
            <a class="sl-btn sl-btn-outline-dark" href="<?= e(route('home')) ?>">Back to the homepage</a>
            <a class="sl-btn sl-btn-ghost-dark" href="<?= e(route('page.contact')) ?>">Contact support</a>
        </div>

        <?php if (!empty($exception)) : ?>
            <div class="tw-mt-12 tw-text-left">
                <div class="sl-card-cinematic">
                    <p class="t-eyebrow t-muted-dark tw-mb-3">Debug detail - shown because APP_DEBUG is true</p>
                    <p class="t-body-strong tw-mb-2"><?= e($exception::class) ?></p>
                    <p class="t-caption t-muted-dark tw-mb-3"><?= e($exception->getMessage()) ?></p>
                    <p class="t-micro t-muted-dark tw-mb-3">
                        <?= e($exception->getFile()) ?>:<?= e((string) $exception->getLine()) ?>
                    </p>
                    <pre class="t-code t-micro tw-overflow-auto tw-mb-0"
                         style="max-height:18rem;color:var(--c-shade-40)"><?= e($exception->getTraceAsString()) ?></pre>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

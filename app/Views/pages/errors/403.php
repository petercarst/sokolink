<?php
declare(strict_types=1);
/**
 * 403.
 *
 * Says what happened without revealing whether the resource exists - "you
 * cannot see this" and "this does not exist" should be indistinguishable to
 * someone probing for other people's orders (NFR-SEC-05).
 *
 * @var int       $status
 * @var string    $message
 * @var bool|null $isPreview
 */
?>
<section class="sl-section-air">
    <div class="sl-container-read sl-container tw-text-center">

        <?php if (!empty($isPreview)) : ?>
            <div class="tw-mb-8 tw-text-left">
                <?= component('devnote', [
                    'onDark' => true,
                    'text'   => 'Design preview of the 403 page. The real page is served by the error handler.',
                ]) ?>
            </div>
        <?php endif; ?>

        <p class="t-eyebrow t-muted-dark tw-mb-6">Error 403</p>

        <span style="color:var(--c-shade-40)"><?= component('icon', ['name' => 'lock', 'size' => 32]) ?></span>

        <h1 class="t-display-lg tw-mt-6 tw-mb-6">We could not accept that request.</h1>

        <p class="t-body-lg t-muted-dark tw-mb-10" style="max-width:50ch;margin-inline:auto">
            <?= e($message ?? 'Your account does not have permission to do that.') ?>
        </p>

        <p class="t-caption t-muted-dark tw-mb-10" style="max-width:50ch;margin-inline:auto">
            This page reads the same whether you lack permission or the thing does not exist,
            so it cannot be used to discover other people's orders.
        </p>

        <div class="tw-flex tw-flex-wrap tw-gap-4 tw-justify-center">
            <a class="sl-btn sl-btn-outline-dark" href="<?= e(route('home')) ?>">Back to the homepage</a>
            <a class="sl-btn sl-btn-ghost-dark" href="<?= e(route('page.contact')) ?>">Contact support</a>
        </div>
    </div>
</section>

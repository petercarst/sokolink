<?php
declare(strict_types=1);
/**
 * 404 / 405.
 *
 * An error page is still a page: it says what happened in plain words and
 * offers a way forward rather than leaving the user at a dead end.
 *
 * @var int         $status
 * @var string      $message
 * @var bool|null   $isPreview
 */
?>
<section class="sl-section-air">
    <div class="sl-container-read sl-container tw-text-center">

        <?php if (!empty($isPreview)) : ?>
            <div class="tw-mb-8 tw-text-left">
                <?= component('devnote', [
                    'onDark' => true,
                    'text'   => 'Design preview of the ' . $status . ' page. The real page is served by the error handler.',
                ]) ?>
            </div>
        <?php endif; ?>

        <p class="t-eyebrow t-muted-dark tw-mb-6">Error <?= e((string) ($status ?? 404)) ?></p>

        <h1 class="t-display-lg tw-mb-6">We could not find that page.</h1>

        <p class="t-body-lg t-muted-dark tw-mb-10" style="max-width:46ch;margin-inline:auto">
            <?= e($message ?? 'The address may be mistyped, or the product or store may no longer be listed.') ?>
        </p>

        <div class="tw-flex tw-flex-wrap tw-gap-4 tw-justify-center">
            <a class="sl-btn sl-btn-outline-dark" href="<?= e(route('home')) ?>">Back to the homepage</a>
            <a class="sl-btn sl-btn-ghost-dark" href="<?= e(route('catalog.index')) ?>">Browse all products</a>
        </div>

        <hr class="sl-divider tw-my-12">

        <p class="t-caption t-muted-dark tw-mb-4">Looking for something specific?</p>
        <form method="get" action="<?= e(route('search')) ?>" role="search"
              class="tw-flex tw-gap-2 tw-max-w-md tw-mx-auto">
            <label class="visually-hidden" for="e404-q">Search products</label>
            <input class="sl-input sl-input-dark" style="border-radius:var(--r-pill)" type="search"
                   id="e404-q" name="q" placeholder="Search products...">
            <button class="sl-btn sl-btn-outline-dark" type="submit">Search</button>
        </form>
    </div>
</section>

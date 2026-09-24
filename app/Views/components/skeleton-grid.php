<?php
declare(strict_types=1);
/**
 * Loading state for a product grid (NFR-USA-03).
 *
 * The skeleton mirrors the real card's shape so the layout does not jump when
 * content arrives. The shimmer is disabled under prefers-reduced-motion.
 *
 * @var int  $count
 * @var bool $onDark
 */
$count = (int) ($count ?? 8);
?>
<div class="sl-grid sl-grid-4" aria-hidden="true">
    <?php for ($i = 0; $i < $count; $i++) : ?>
        <div class="sl-product-card">
            <div class="sl-skeleton sl-skeleton-media"></div>
            <div class="sl-product-body">
                <div class="sl-skeleton sl-skeleton-text" style="width:40%"></div>
                <div class="sl-skeleton sl-skeleton-title" style="width:85%"></div>
                <div class="sl-skeleton sl-skeleton-text" style="width:55%"></div>
            </div>
        </div>
    <?php endfor; ?>
</div>
<p class="visually-hidden" role="status">Loading products</p>

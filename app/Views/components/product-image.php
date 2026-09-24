<?php
declare(strict_types=1);
/**
 * Product imagery.
 *
 * A real photograph when the seller has uploaded one, and a deterministic tonal
 * panel when they have not. The panel carries a small PLACEHOLDER mark so
 * nobody reviewing the build mistakes it for shipped artwork - and, equally
 * important, so a catalogue full of them cannot be presented as though the
 * photography exists.
 *
 * `path` is product_images.stored_path: a random filename under
 * public/uploads/products/. The original upload name is never used as a path
 * (NFR-SEC-06), so there is nothing here to traverse with.
 *
 * @var string|null $path   stored_path, or null for the placeholder
 * @var string      $tone   tone key, used only when there is no path
 * @var string      $label  product name, used for the initial and the alt text
 * @var bool        $mark   optional, default true - show the PLACEHOLDER mark
 */
$path  = $path  ?? null;
$tone  = $tone  ?? 'slate';
$label = $label ?? '';
$mark  = $mark  ?? true;

if (is_string($path) && $path !== '') :
    ?>
    <img loading="lazy" decoding="async"
         src="<?= e(asset('uploads/products/' . ltrim($path, '/'))) ?>"
         alt="<?= e($label) ?>">
    <?php
    return;
endif;

/** tone key => [background from, background to, ink] */
$tones = [
    'amber'  => ['#f4d9a0', '#e8b85c', '#4a3508'],
    'sand'   => ['#ece3d2', '#d9c9aa', '#4a3f2a'],
    'cream'  => ['#f5f2e8', '#e4ddc8', '#4a452f'],
    'clay'   => ['#e8cdbb', '#d3a488', '#4a2f1e'],
    'forest' => ['#cfe0cf', '#9dbb9f', '#1e3320'],
    'mint'   => ['#d9f5e3', '#a9e0c1', '#10352a'],
    'rose'   => ['#f2d3d3', '#dfa8a8', '#5a1414'],
    'sky'    => ['#d6e6f2', '#a8c6dd', '#13334a'],
    'slate'  => ['#e2e2e6', '#c2c2ca', '#3f3f46'],
];

[$from, $to, $ink] = $tones[$tone] ?? $tones['slate'];
$initial = initials($label, 1);

// Unique per render, not per (tone, label): the same product can legitimately
// appear twice on one page, and two <linearGradient> elements sharing an id
// makes the second reference resolve to the first.
$GLOBALS['__sl_gradient_seq'] = ($GLOBALS['__sl_gradient_seq'] ?? 0) + 1;
$uid = 'pg' . $GLOBALS['__sl_gradient_seq'];
?>
<svg class="sl-placeholder" viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice"
     role="img" aria-label="<?= e($label !== '' ? $label . ' - placeholder image' : 'Placeholder image') ?>">
    <defs>
        <linearGradient id="<?= e($uid) ?>" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="<?= e($from) ?>"/>
            <stop offset="100%" stop-color="<?= e($to) ?>"/>
        </linearGradient>
    </defs>
    <rect width="400" height="300" fill="url(#<?= e($uid) ?>)"/>
    <circle cx="316" cy="64" r="92" fill="<?= e($ink) ?>" opacity=".055"/>
    <circle cx="88" cy="246" r="118" fill="<?= e($ink) ?>" opacity=".045"/>
    <text x="200" y="168" text-anchor="middle"
          font-family="Inter, Helvetica, Arial, sans-serif" font-size="108" font-weight="330"
          fill="<?= e($ink) ?>" opacity=".38"><?= e($initial) ?></text>
    <?php if ($mark) : ?>
        <text x="16" y="286" font-family="Inter, Helvetica, Arial, sans-serif"
              font-size="11" font-weight="500" letter-spacing="1.4"
              fill="<?= e($ink) ?>" opacity=".42">PLACEHOLDER</text>
    <?php endif; ?>
</svg>

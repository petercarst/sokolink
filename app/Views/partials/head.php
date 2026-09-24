<?php
declare(strict_types=1);
/**
 * Document head.
 *
 * Asset load order matters and is documented in docs/DESIGN_SYSTEM.md section 6:
 *
 *   1. bootstrap.min.css    base component library
 *   2. design-tokens.css    tokens + Bootstrap variable overrides
 *   3. app.css              our component classes
 *   4. tailwind.build.css   UTILITIES LAST
 *
 * Utilities must come last. Tailwind utilities and our .sl-* component classes
 * are both single-class selectors, so specificity ties and source order decides.
 * With app.css last, `.sl-btn { display:inline-flex }` beat `.tw-hidden`, and
 * buttons meant to be hidden on small screens stayed visible and pushed the
 * layout 150px wider than the viewport. Caught by the responsive audit.
 *
 * @var string $title
 * @var string $metaDesc
 * @var string $appName
 * @var string $track   'cinematic' | 'transactional'
 */
$track    = $track ?? 'cinematic';
$appName  = $appName ?? 'SokoLink';
$metaDesc = $metaDesc ?? '';
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(($title ?? '') !== '' ? $title . ' - ' . $appName : $appName) ?></title>
<meta name="description" content="<?= e($metaDesc) ?>">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="<?= $track === 'cinematic' ? '#000000' : '#fbfbf5' ?>">
<meta name="referrer" content="strict-origin-when-cross-origin">

<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preload" href="<?= e(asset('fonts/inter-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>

<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/design-tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/tailwind.build.css')) ?>">

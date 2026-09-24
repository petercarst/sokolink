<?php
declare(strict_types=1);
/**
 * Public site layout.
 *
 * A page chooses ONE track and the layout follows it end to end - nav, body,
 * footer and mobile menu all share the polarity. The reference is explicit
 * that the two tracks are never blended.
 *
 *   cinematic      near-black canvas, editorial, product photography leads
 *   transactional  cream canvas, dense, for cart, checkout and anything where
 *                  the user is doing a job rather than browsing
 *
 * @var string $content
 * @var string $track
 */
// The DEFAULT is the light track. The two tracks still exist and a page that
// asks for cinematic still gets it; what changed is which one a page gets when
// it does not ask. The marketing pages read as paper with black accents rather
// than as a black site with light panels.
$track = $track ?? 'transactional';
?>
<!doctype html>
<html lang="<?= e((string) config('app.locale', 'en')) ?>"
      data-currency="<?= e((string) config('app.currency')) ?>"
      data-currency-symbol="<?= e((string) config('app.currency_symbol')) ?>">
<head>
    <?= partial('head', ['title' => $title ?? '', 'metaDesc' => $metaDesc ?? '', 'track' => $track]) ?>
</head>
<body class="track-<?= e($track) ?>">

<a class="skip-link visually-hidden-focusable" href="#main">Skip to main content</a>

<?= partial('nav-public', ['track' => $track]) ?>

<?= partial('flash', ['flashes' => $flashes ?? []]) ?>

<main id="main" tabindex="-1">
    <?= $content ?>
</main>

<?= partial('footer-public', ['track' => $track, 'currentYear' => $currentYear ?? (int) gmdate('Y')]) ?>

<?= partial('scripts') ?>
</body>
</html>

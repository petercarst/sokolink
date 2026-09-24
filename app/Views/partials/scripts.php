<?php
declare(strict_types=1);
/**
 * Scripts, loaded at the end of body.
 *
 * Bootstrap's bundle is self-hosted (no CDN, works offline, CSP stays
 * 'self'-only). Our own modules are vanilla ES modules with no build step.
 */
?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" type="module"></script>

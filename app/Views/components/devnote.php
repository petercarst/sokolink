<?php
declare(strict_types=1);
/**
 * A deliberately unmissable notice that something is not real yet.
 *
 * The brief is explicit: do not pretend that mock login, payment or order
 * processing is real. This component is how that promise is kept on screen,
 * everywhere the UI would otherwise imply working functionality.
 *
 * Removed in Phase 4 as each feature becomes real.
 *
 * @var string $text
 * @var bool   $onDark
 */
$onDark = $onDark ?? false;
?>
<p class="sl-devnote<?= $onDark ? ' sl-devnote-dark' : '' ?> tw-mb-0" role="note">
    <span class="tw-shrink-0"><?= component('icon', ['name' => 'info', 'size' => 14]) ?></span>
    <span><?= e($text ?? '') ?></span>
</p>

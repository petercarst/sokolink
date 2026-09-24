<?php
declare(strict_types=1);
/**
 * Inline SVG icon.
 *
 * @var string $name
 * @var int    $size   optional, default 20
 * @var string $class  optional extra classes
 *
 * Inline rather than an icon font: no extra request, inherits currentColor in
 * both tracks, and screen readers ignore it because every icon here is
 * decorative - the accessible name always comes from adjacent text or an
 * aria-label on the control.
 */
$size  = $size  ?? 20;
$class = $class ?? '';

$paths = [
    'search'        => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    'cart'          => '<path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="20" r="1.2"/><circle cx="18" cy="20" r="1.2"/>',
    'user'          => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
    'menu'          => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    'close'         => '<path d="M6 6l12 12M18 6L6 18"/>',
    'chevron-right' => '<path d="m9 5 7 7-7 7"/>',
    'chevron-left'  => '<path d="m15 5-7 7 7 7"/>',
    'chevron-down'  => '<path d="m5 9 7 7 7-7"/>',
    'arrow-right'   => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
    'arrow-left'    => '<path d="M20 12H4m6-6-6 6 6 6"/>',
    'check'         => '<path d="m4 12 5 5L20 6"/>',
    'check-circle'  => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
    'plus'          => '<path d="M12 5v14M5 12h14"/>',
    'minus'         => '<path d="M5 12h14"/>',
    'trash'         => '<path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"/>',
    'star'          => '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.7l5.9-.9z"/>',
    'info'          => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    'alert'         => '<path d="M12 4 2.5 20h19z"/><path d="M12 10v4M12 17h.01"/>',
    'shield'        => '<path d="M12 3 5 6v6c0 4.5 3 7.7 7 9 4-1.3 7-4.5 7-9V6z"/><path d="m9 12 2 2 4-4"/>',
    'store'         => '<path d="M4 9h16v11H4z"/><path d="M3 9 5 4h14l2 5"/><path d="M9 20v-6h6v6"/>',
    'truck'         => '<path d="M2 7h11v9H2z"/><path d="M13 10h4l3 3v3h-7z"/><circle cx="6" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
    'package'       => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5 12 12l8-4.5M12 12v9"/>',
    'clock'         => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'map-pin'       => '<path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    'phone'         => '<path d="M6 3h4l2 5-2.5 1.5a12 12 0 0 0 5 5L16 12l5 2v4a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4 5.2 2 2 0 0 1 6 3z"/>',
    'mail'          => '<path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/>',
    'bell'          => '<path d="M6 9a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5h-15S6 13 6 9z"/><path d="M10 18a2 2 0 0 0 4 0"/>',
    'filter'        => '<path d="M3 5h18l-7 8v5l-4 2v-7z"/>',
    'repeat'        => '<path d="M4 9a5 5 0 0 1 5-5h9"/><path d="m15 1 3 3-3 3"/><path d="M20 15a5 5 0 0 1-5 5H6"/><path d="m9 23-3-3 3-3"/>',
    'qr'            => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4z"/><path d="M14 14h2v2h-2zM18 14h2v2h-2zM16 16h2v2h-2zM14 18h2v2h-2zM18 18h2v2h-2z"/>',
    'leaf'          => '<path d="M5 19c0-8 6-13 14-13 0 8-5 14-13 14H5z"/><path d="M5 19c3-3 6-5 9-6"/>',
    'basket'        => '<path d="m5 9 3-5M19 9l-3-5"/><path d="M3 9h18l-1.5 10H4.5z"/><path d="M10 13v3M14 13v3"/>',
    'home'          => '<path d="m3 11 9-7 9 7"/><path d="M6 10v10h12V10"/>',
    'heart'         => '<path d="M12 20s-7-4.5-7-9.5A3.8 3.8 0 0 1 12 8a3.8 3.8 0 0 1 7 2.5C19 15.5 12 20 12 20z"/>',
    'baby'          => '<circle cx="12" cy="9" r="4"/><path d="M6 21a6 6 0 0 1 12 0"/><path d="M10 8h.01M14 8h.01"/>',
    'cup'           => '<path d="M5 6h12v7a6 6 0 0 1-12 0z"/><path d="M17 8h2a2 2 0 0 1 0 4h-2"/><path d="M4 21h14"/>',
    'drop'          => '<path d="M12 3s6 6.5 6 10a6 6 0 0 1-12 0c0-3.5 6-10 6-10z"/>',
    'bottle'        => '<path d="M10 2h4v3l1.5 2.5A5 5 0 0 1 16 10v9a2 2 0 0 1-2 2h-4a2 2 0 0 1-2-2v-9c0-.9.2-1.8.5-2.5L10 5z"/>',
    'grain'         => '<path d="M12 21V8"/><path d="M12 12c-3 0-5-2-5-5 3 0 5 2 5 5zM12 12c3 0 5-2 5-5-3 0-5 2-5 5z"/><path d="M12 18c-3 0-5-2-5-5 3 0 5 2 5 5zM12 18c3 0 5-2 5-5-3 0-5 2-5 5z"/>',
    'spice'         => '<path d="M7 8h10l-1 12H8z"/><path d="M9 8V5a3 3 0 0 1 6 0v3"/>',
    'shirt'         => '<path d="m8 3 4 2 4-2 4 3-2.5 3V21H6.5V9L4 6z"/>',
    'spray'         => '<path d="M9 8h6v13H9z"/><path d="M11 8V4h4"/><path d="M18 4h.01M18 7h.01M21 5h.01"/>',
    'roll'          => '<ellipse cx="12" cy="6" rx="6" ry="3"/><path d="M6 6v12a6 3 0 0 0 12 0V6"/>',
    'smile'         => '<circle cx="12" cy="12" r="9"/><path d="M8.5 14a4.5 4.5 0 0 0 7 0"/><path d="M9 9h.01M15 9h.01"/>',
    'egg'           => '<path d="M12 3c3.5 0 6 5 6 9a6 6 0 0 1-12 0c0-4 2.5-9 6-9z"/>',
    'bowl'          => '<path d="M3 11h18a9 9 0 0 1-18 0z"/><path d="M8 8c0-2 1-3 2-3M14 8c0-2 1-3 2-3"/>',
    'external'      => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    'lock'          => '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    'eye'           => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z"/><circle cx="12" cy="12" r="2.5"/>',
    'eye-off'       => '<path d="M3 3l18 18"/><path d="M10.6 6.2A9.9 9.9 0 0 1 12 6c6.5 0 10 6 10 6a17 17 0 0 1-3.4 3.9M6.5 8.1A17 17 0 0 0 2 12s3.5 6 10 6a9.6 9.6 0 0 0 3.2-.5"/>',
];

$path = $paths[$name] ?? $paths['info'];
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?= e($size) ?>" height="<?= e($size) ?>"
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
     stroke-linecap="round" stroke-linejoin="round"
     class="<?= e($class) ?>" aria-hidden="true" focusable="false"><?= $path ?></svg>

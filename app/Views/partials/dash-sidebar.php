<?php
declare(strict_types=1);
/**
 * Dashboard sidebar, built from DashboardNav.
 *
 * Rendered twice per page (pinned desktop rail + mobile offcanvas), so it takes
 * an $idPrefix - duplicate ids break label association and anchors.
 *
 * In Phase 3 the item list is filtered by PermissionService. That filtering is
 * a courtesy: every route behind these links is guarded server-side, because
 * hiding a link is never the access control (NFR-SEC-05).
 *
 * @var string $role
 * @var list<array<string,mixed>> $nav
 * @var array{label:string,badge:string,colour:string} $meta
 * @var string $idPrefix
 * @var bool   $compact  true inside the offcanvas, where the header is separate
 */
$idPrefix = $idPrefix ?? 'd';
$compact  = $compact ?? false;
?>
<?php if (!$compact) : ?>
    <a class="sl-brand tw-mb-2" href="<?= e(route('home')) ?>">
        <span class="sl-brand-mark" aria-hidden="true">S</span>
        <span>SokoLink</span>
    </a>
    <p class="tw-mb-6">
        <?= component('badge', ['status' => 'neutral', 'label' => $meta['badge']]) ?>
    </p>
<?php endif; ?>

<nav aria-label="<?= e($meta['label']) ?>">
    <?php foreach ($nav as $gi => $group) : ?>
        <div class="sl-side-group">
            <h2 class="sl-side-heading" id="<?= e($idPrefix) ?>-grp-<?= e((string) $gi) ?>">
                <?= e($group['heading']) ?>
            </h2>

            <ul class="tw-list-none tw-p-0 tw-m-0" aria-labelledby="<?= e($idPrefix) ?>-grp-<?= e((string) $gi) ?>">
                <?php foreach ($group['items'] as $item) : ?>
                    <?php
                    // A section stays current while a detail page under it is open,
                    // because detail routes are named as children:
                    // seller.orders -> seller.orders.show
                    $currentRoute = (string) \App\Core\Router::currentName();
                    $isCurrent = $currentRoute === $item['route']
                        || str_starts_with($currentRoute, $item['route'] . '.');
                    ?>
                    <li>
                        <a class="sl-side-link <?= $isCurrent ? 'is-current' : '' ?>"
                           href="<?= e(route($item['route'])) ?>"
                           <?= $isCurrent ? 'aria-current="page"' : '' ?>>
                            <span class="sl-side-icon"><?= component('icon', ['name' => $item['icon'], 'size' => 18]) ?></span>
                            <span class="sl-side-label"><?= e($item['label']) ?></span>
                            <?php if (!empty($item['count'])) : ?>
                                <span class="sl-side-count">
                                    <?= e((string) $item['count']) ?>
                                    <span class="visually-hidden">needing attention</span>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</nav>

<hr class="sl-divider">

<a class="sl-side-link" href="<?= e(route('home')) ?>">
    <span class="sl-side-icon"><?= component('icon', ['name' => 'arrow-left', 'size' => 18]) ?></span>
    <span class="sl-side-label">Back to marketplace</span>
</a>

<form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
    <?= csrf_field() ?>
    <input type="hidden" name="feature" value="logout">
    <button type="submit" class="sl-side-link tw-w-full tw-text-left">
        <span class="sl-side-icon"><?= component('icon', ['name' => 'lock', 'size' => 18]) ?></span>
        <span class="sl-side-label">Log out</span>
    </button>
</form>

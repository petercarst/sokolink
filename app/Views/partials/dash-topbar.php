<?php
declare(strict_types=1);
/**
 * Dashboard top bar: hamburger on mobile, identity and quick actions.
 *
 * The signed-in identity is sample text in Phase 1. Phase 3.2 reads it from the
 * session-derived actor, never from anything the browser sends.
 *
 * @var string $role
 * @var array{label:string,badge:string,colour:string} $meta
 */
$people = [
    'customer' => ['Asha Mwinyi',  'asha@example.co.tz'],
    'seller'   => ['Mama Lishe Provisions', 'seller.mama.lishe@sokolink.test'],
    'delivery' => ['Juma Kileo',   'agent.juma@sokolink.test'],
    'support'  => ['Neema Support', 'support@sokolink.test'],
    'admin'    => ['Platform Admin', 'admin@sokolink.test'],
];
[$who, $email] = $people[$role] ?? ['Sample User', 'user@sokolink.test'];
?>
<header class="sl-dash-top">
    <div class="sl-container-wide sl-container tw-flex tw-items-center tw-gap-3">

        <button class="sl-btn sl-btn-ghost sl-btn-icon lg:tw-hidden" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#dashNav"
                aria-controls="dashNav" aria-label="Open navigation">
            <?= component('icon', ['name' => 'menu', 'size' => 22]) ?>
        </button>

        <a class="sl-brand lg:tw-hidden" href="<?= e(route('home')) ?>">
            <span class="sl-brand-mark" aria-hidden="true">S</span>
        </a>

        <p class="t-heading-md tw-mb-0 tw-hidden lg:tw-block"><?= e($meta['label']) ?></p>

        <div class="tw-flex tw-items-center tw-gap-2 tw-ml-auto">
            <a class="sl-btn sl-btn-ghost sl-btn-icon" href="<?= e(route('page.contact')) ?>"
               aria-label="Help and support">
                <?= component('icon', ['name' => 'info', 'size' => 20]) ?>
            </a>

            <div class="dropdown">
                <button class="sl-btn sl-btn-ghost tw-gap-2" type="button"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="sl-avatar" style="width:32px;height:32px;font-size:12px"><?= e(initials($who)) ?></span>
                    <span class="tw-hidden sm:tw-inline t-caption"><?= e($who) ?></span>
                    <?= component('icon', ['name' => 'chevron-down', 'size' => 16]) ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end tw-mt-2" style="border-radius:var(--r-lg);min-width:15rem">
                    <li class="tw-px-4 tw-py-2">
                        <span class="t-body-strong tw-block"><?= e($who) ?></span>
                        <span class="t-micro t-muted"><?= e($email) ?></span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><span class="dropdown-item-text t-micro t-muted">Sample identity - Phase 1 preview</span></li>
                </ul>
            </div>
        </div>
    </div>
</header>

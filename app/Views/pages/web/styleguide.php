<?php
declare(strict_types=1);
/**
 * Living styleguide.
 *
 * Everything the design system contains, on one page, rendered by the same
 * components the real pages use. If something here looks wrong, it is wrong
 * everywhere - which is the point of reviewing the system before the 85th
 * screen rather than after it.
 *
 * @var list<array<string,mixed>> $products
 * @var list<array{method:string,pattern:string,name:?string}> $routes
 */

$colourGroups = [
    'Canvases' => [
        ['--c-canvas-night', '#000000', 'Cinematic page canvas'],
        ['--c-canvas-night-el', '#0a0a0a', 'Cards on the cinematic track'],
        ['--c-surface-dark-el', '#1e2c31', 'Teal-shifted dark surface, sparingly'],
        ['--c-canvas-light', '#ffffff', 'Cards on the light track'],
        ['--c-canvas-cream', '#fbfbf5', 'Transactional page canvas'],
    ],
    'Accents (light track only)' => [
        ['--c-aloe-10', '#c1fbd4', 'Featured fill, affirmative pill'],
        ['--c-pistachio-10', '#d4f9e0', 'Wide section band'],
    ],
    'Shade ladder' => [
        ['--c-shade-30', '#d4d4d8', 'Chip fill, skeleton, hairline on dark'],
        ['--c-shade-40', '#a1a1aa', 'Secondary text ON DARK only - fails AA on white'],
        ['--c-shade-50', '#71717a', 'Secondary text on light (4.8:1)'],
        ['--c-shade-60', '#52525b', 'Tertiary on light'],
        ['--c-shade-70', '#3f3f46', 'Pressed state of the primary pill'],
    ],
    'Status (DS-EXT-01)' => [
        ['--c-status-success-bg', '#c1fbd4', 'Reuses aloe - no new hue'],
        ['--c-status-info-bg', '#d4f9e0', 'Reuses pistachio - no new hue'],
        ['--c-status-warn-bg', '#fdf0c4', 'NEW hue - needs approval'],
        ['--c-status-danger-bg', '#fbd5d5', 'NEW hue - needs approval'],
        ['--c-status-neutral-bg', '#d4d4d8', 'Reuses shade-30'],
    ],
];

$typeScale = [
    ['t-display-xxl', '96 / 330 / +2.4px', 'Cinematic hero'],
    ['t-display-xl',  '70 / 330', 'Section opener'],
    ['t-display-lg',  '55 / 330', 'Page title'],
    ['t-display-md',  '48 / 330', 'Sub-section, price'],
    ['t-heading-xl',  '28 / 500', 'Card title'],
    ['t-heading-lg',  '24 / 400', 'Compact card title'],
    ['t-heading-md',  '20 / 500', 'Section sub-heading'],
    ['t-heading-sm',  '18 / 500', 'Mini-section label'],
    ['t-body-lg',     '18 / 550', 'Marketing lead'],
    ['t-body-md',     '16 / 420', 'Default UI body'],
    ['t-body-strong', '16 / 550', 'Emphasised run'],
    ['t-caption',     '14 / 500', 'Helper copy'],
    ['t-micro',       '13 / 500', 'Fine print'],
    ['t-eyebrow',     '12 / 400 caps', 'Eyebrow above headings'],
];

$statusExamples = [
    'success' => ['collected', 'delivered', 'completed', 'paid', 'approved', 'published'],
    'info'    => ['confirmed', 'preparing', 'ready_for_pickup', 'ready_for_dispatch', 'out_for_delivery', 'assigned'],
    'warn'    => ['pending_payment', 'awaiting_seller', 'collection_overdue', 'waiting_customer', 'pending_approval'],
    'danger'  => ['rejected_seller', 'cancelled_customer', 'delivery_failed', 'returned_to_seller', 'expired_unpaid', 'suspended'],
    'neutral' => ['draft', 'archived', 'closed', 'refunded', 'resolved'],
];

$sectionClass = 'sl-section-tight';
?>

<section class="sl-section-tight">
    <div class="sl-container">
        <p class="t-eyebrow t-muted tw-mb-4">Phase 1 &middot; design system</p>
        <h1 class="t-display-lg tw-mb-6">SokoLink design system</h1>
        <p class="t-body-lg t-muted tw-mb-8" style="max-width:60ch">
            Every token and component, rendered by the same code the real pages use. Based on the
            supplied getdesign.md analysis, with the extensions and accessibility adaptations
            documented in <code class="t-code">docs/DESIGN_SYSTEM.md</code>.
        </p>

        <nav aria-label="Styleguide sections" class="tw-flex tw-flex-wrap tw-gap-2">
            <?php
            $nav = [
                'tracks' => 'Tracks', 'colour' => 'Colour', 'type' => 'Typography',
                'shape' => 'Shape & depth', 'buttons' => 'Buttons', 'badges' => 'Badges',
                'forms' => 'Forms', 'cards' => 'Cards', 'data' => 'Data', 'states' => 'States',
                'icons' => 'Icons', 'deviations' => 'Deviations', 'routes' => 'Routes',
            ];
            foreach ($nav as $id => $label) : ?>
                <a class="sl-chip" href="#<?= e($id) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</section>

<!-- ============================ TRACKS ============================ -->
<section class="<?= e($sectionClass) ?>" id="tracks">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-4">1. The two tracks</h2>
        <p class="t-body-md t-muted tw-mb-8" style="max-width:62ch">
            A page picks one track and follows it end to end - nav, body, footer and mobile menu all
            share the polarity. There is no third option and no blending. This is the reference's own
            rule and it is what makes the system feel deliberate rather than mixed.
        </p>

        <div class="sl-grid sl-grid-2">
            <div class="track-cinematic" style="border-radius:var(--r-lg);padding:var(--s-xxl)">
                <p class="t-eyebrow t-muted-dark tw-mb-3">Cinematic</p>
                <p class="t-display-md tw-mb-4">Editorial black</p>
                <p class="t-body-md t-muted-dark tw-mb-5">
                    Home, catalogue, product, store, about, selling. Photography leads, one action per
                    band, display type at weight 330. No aloe or pistachio here.
                </p>
                <a class="sl-btn sl-btn-outline-dark" href="<?= e(route('home')) ?>">See the homepage</a>
            </div>

            <div class="sl-card" style="background:var(--c-canvas-cream)">
                <p class="t-eyebrow t-muted tw-mb-3">Transactional</p>
                <p class="t-display-md tw-mb-4">Working cream</p>
                <p class="t-body-md t-muted tw-mb-5">
                    Basket, checkout, legal pages and every dashboard. Dense, scannable, built for
                    someone doing a job. Mints appear here and only here.
                </p>
                <a class="sl-btn sl-btn-primary" href="<?= e(route('cart')) ?>">See the basket</a>
            </div>
        </div>
    </div>
</section>

<!-- ============================ COLOUR ============================ -->
<section class="<?= e($sectionClass) ?>" id="colour">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-8">2. Colour</h2>

        <?php foreach ($colourGroups as $groupName => $swatches) : ?>
            <h3 class="t-heading-md tw-mb-4"><?= e($groupName) ?></h3>
            <div class="sl-grid sl-grid-4 tw-mb-10">
                <?php foreach ($swatches as [$token, $hex, $use]) : ?>
                    <div class="sl-card sl-card-tight">
                        <div style="height:64px;border-radius:var(--r-md);background:<?= e($hex) ?>;
                                    border:1px solid var(--c-hairline-light)" aria-hidden="true"></div>
                        <p class="t-code t-micro tw-mt-3 tw-mb-1"><?= e($token) ?></p>
                        <p class="t-code t-micro t-muted tw-mb-2"><?= e($hex) ?></p>
                        <p class="t-micro t-muted tw-mb-0"><?= e($use) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ========================== TYPOGRAPHY ========================== -->
<section class="<?= e($sectionClass) ?>" id="type">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-4">3. Typography</h2>
        <p class="t-body-md t-muted tw-mb-8" style="max-width:62ch">
            Inter variable, self-hosted, using the optical-size axis for the display tier. The thin
            330 weight is the brand signature. Below 48px the weight floors at 400 - see
            <a href="#deviations">deviations</a>.
        </p>

        <div class="sl-card tw-mb-8">
            <?php foreach ($typeScale as [$class, $spec, $use]) : ?>
                <div class="tw-flex tw-flex-wrap tw-items-baseline tw-gap-4 tw-py-4"
                     style="border-bottom:1px solid var(--c-hairline-light)">
                    <div style="flex:0 0 12rem">
                        <p class="t-code t-micro tw-mb-1"><?= e($class) ?></p>
                        <p class="t-micro t-muted tw-mb-0"><?= e($spec) ?> &middot; <?= e($use) ?></p>
                    </div>
                    <p class="<?= e($class) ?> tw-mb-0" style="flex:1 1 16rem">Ordering made simple</p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sl-card">
            <h3 class="t-heading-md tw-mb-3">Codes, SKUs and money</h3>
            <p class="t-body-md t-muted tw-mb-4">
                Monospace with <code class="t-code">ss02</code> (disambiguates l / I / 1) and tabular
                figures. It matters when someone reads a collection code aloud in a shop.
            </p>
            <p class="t-code tw-mb-2">SL-2026-9F3K2A &middot; ALZ-OIL-5L &middot; 1lI0O</p>
            <p class="tabular t-body-strong tw-mb-0"><?= e(money('28500')) ?> &middot; <?= e(money('92000')) ?> &middot; <?= e(money('2400')) ?></p>
        </div>
    </div>
</section>

<!-- ======================== SHAPE AND DEPTH ======================= -->
<section class="<?= e($sectionClass) ?>" id="shape">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-8">4. Shape, spacing and depth</h2>

        <div class="sl-grid sl-grid-2">
            <div class="sl-card">
                <h3 class="t-heading-md tw-mb-4">Radius</h3>
                <?php foreach ([['xs','4px'],['sm','5px'],['md','8px'],['lg','12px'],['xl','20px'],['pill','9999px']] as [$k,$v]) : ?>
                    <div class="tw-flex tw-items-center tw-gap-4 tw-py-2">
                        <span style="width:56px;height:36px;background:var(--c-shade-30);border-radius:var(--r-<?= e($k) ?>)" aria-hidden="true"></span>
                        <span class="t-code t-micro" style="width:6rem">--r-<?= e($k) ?></span>
                        <span class="t-micro t-muted"><?= e($v) ?></span>
                    </div>
                <?php endforeach; ?>
                <p class="t-micro t-muted tw-mt-4 tw-mb-0">
                    Buttons are always <code class="t-code">pill</code>. Variants change fill and
                    border, never shape.
                </p>
            </div>

            <div class="sl-card">
                <h3 class="t-heading-md tw-mb-4">Spacing (base 8px)</h3>
                <?php foreach ([['xxs','2'],['xs','4'],['sm','8'],['md','12'],['lg','16'],['xl','24'],['xxl','32'],['huge','64']] as [$k,$v]) : ?>
                    <div class="tw-flex tw-items-center tw-gap-4 tw-py-1">
                        <span style="width:<?= e($v) ?>px;height:16px;background:var(--c-aloe-10)" aria-hidden="true"></span>
                        <span class="t-code t-micro" style="width:6rem">--s-<?= e($k) ?></span>
                        <span class="t-micro t-muted"><?= e($v) ?>px</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <h3 class="t-heading-md tw-mt-10 tw-mb-4">Elevation</h3>
        <div class="sl-grid sl-grid-4">
            <div class="sl-card"><p class="t-code t-micro tw-mb-2">level 0</p><p class="t-micro t-muted tw-mb-0">Flat. Default surface.</p></div>
            <div class="sl-card sl-card-raised"><p class="t-code t-micro tw-mb-2">--e-3</p><p class="t-micro t-muted tw-mb-0">Stacked tiny shadows. Light track only. The paper halo.</p></div>
            <div class="sl-card" style="box-shadow:var(--e-4);border-color:transparent"><p class="t-code t-micro tw-mb-2">--e-4</p><p class="t-micro t-muted tw-mb-0">Modal and floating panels.</p></div>
            <div class="track-cinematic" style="border-radius:var(--r-lg);padding:var(--s-xl);box-shadow:var(--e-1);border:1px solid var(--c-hairline-dark)">
                <p class="t-code t-micro tw-mb-2">--e-1</p>
                <p class="t-micro t-muted-dark tw-mb-0">Inset top sheen. Dark track only. No drop shadow on black.</p>
            </div>
        </div>
    </div>
</section>

<!-- =========================== BUTTONS ============================ -->
<section class="<?= e($sectionClass) ?>" id="buttons">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-8">5. Buttons</h2>

        <div class="sl-card tw-mb-6">
            <h3 class="t-heading-md tw-mb-4">Light track</h3>
            <div class="tw-flex tw-flex-wrap tw-gap-3 tw-mb-6">
                <button class="sl-btn sl-btn-primary">Primary</button>
                <button class="sl-btn sl-btn-aloe">Affirmative</button>
                <button class="sl-btn sl-btn-outline-light">Secondary</button>
                <button class="sl-btn sl-btn-ghost">Tertiary</button>
                <button class="sl-btn sl-btn-danger">Destructive</button>
                <button class="sl-btn sl-btn-primary" disabled>Disabled</button>
            </div>
            <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-3">
                <button class="sl-btn sl-btn-primary sl-btn-sm">Small (36px)</button>
                <button class="sl-btn sl-btn-primary">Default (44px)</button>
                <button class="sl-btn sl-btn-primary sl-btn-lg">Large (52px)</button>
                <button class="sl-btn sl-btn-outline-light sl-btn-icon" aria-label="Example icon button">
                    <?= component('icon', ['name' => 'plus', 'size' => 18]) ?>
                </button>
            </div>
            <p class="t-micro t-muted tw-mt-4 tw-mb-0">
                Every size clears 44px except the small variant, which is only used inside a card
                where a larger target already exists nearby.
            </p>
        </div>

        <div class="track-cinematic" style="border-radius:var(--r-lg);padding:var(--s-xxl)">
            <h3 class="t-heading-md tw-mb-4">Cinematic track</h3>
            <div class="tw-flex tw-flex-wrap tw-gap-3">
                <button class="sl-btn sl-btn-outline-dark">Primary on dark</button>
                <button class="sl-btn sl-btn-ghost-dark">Tertiary on dark</button>
                <button class="sl-btn sl-btn-outline-dark" disabled>Disabled</button>
            </div>
            <p class="t-micro t-muted-dark tw-mt-4 tw-mb-0">
                One primary action per band. The mint pill never appears on this track.
            </p>
        </div>
    </div>
</section>

<!-- ============================ BADGES ============================ -->
<section class="<?= e($sectionClass) ?>" id="badges">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-4">6. Status badges</h2>
        <p class="t-body-md t-muted tw-mb-8" style="max-width:62ch">
            The reference has no semantic status colours and forbids a third canvas. A marketplace
            has to distinguish <em>delivered</em> from <em>rejected</em>, so DS-EXT-01 adds two
            desaturated hues. Colour is never the only signal: every badge carries a text label and a
            dot.
        </p>

        <div class="sl-card tw-mb-6">
            <?php foreach ($statusExamples as $tone => $statuses) : ?>
                <div class="tw-py-4" style="border-bottom:1px solid var(--c-hairline-light)">
                    <p class="t-code t-micro tw-mb-3"><?= e($tone) ?></p>
                    <div class="tw-flex tw-flex-wrap tw-gap-2">
                        <?php foreach ($statuses as $status) : ?>
                            <?= component('badge', ['status' => $status]) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sl-grid sl-grid-2">
            <div class="sl-card">
                <h3 class="t-heading-md tw-mb-4">Chips and tags</h3>
                <div class="tw-flex tw-flex-wrap tw-gap-2">
                    <span class="sl-chip">Neutral</span>
                    <span class="sl-chip sl-chip-mint">Featured</span>
                    <span class="sl-chip is-active">Active filter</span>
                </div>
            </div>
            <div class="track-cinematic" style="border-radius:var(--r-lg);padding:var(--s-xxl)">
                <h3 class="t-heading-md tw-mb-4">On dark</h3>
                <div class="tw-flex tw-flex-wrap tw-gap-2">
                    <?= component('badge', ['status' => 'ready_for_pickup', 'onDark' => true]) ?>
                    <?= component('badge', ['status' => 'delivery_failed', 'onDark' => true]) ?>
                    <span class="sl-chip sl-chip-dark">Collect</span>
                </div>
                <p class="t-micro t-muted-dark tw-mt-4 tw-mb-0">
                    Badges become outlines on the cinematic track - the mints stay on light.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ============================= FORMS ============================ -->
<section class="<?= e($sectionClass) ?>" id="forms">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-4">7. Forms</h2>
        <p class="t-body-md t-muted tw-mb-8" style="max-width:62ch">
            Every field is rendered by one component that wires the label, help text and error text
            together with <code class="t-code">aria-describedby</code> and sets
            <code class="t-code">aria-invalid</code>. Doing that by hand on 40 forms is how it gets
            missed.
        </p>

        <div class="sl-grid sl-grid-2">
            <div class="sl-card">
                <h3 class="t-heading-md tw-mb-4">States</h3>
                <?= component('field', ['name' => 'sg_text', 'label' => 'Text field', 'value' => '', 'placeholder' => 'Placeholder text', 'help' => 'Help text sits under the field.']) ?>
                <?= component('field', ['name' => 'sg_req', 'label' => 'Required field', 'required' => true, 'value' => 'With a value']) ?>
                <?= component('field', ['name' => 'sg_err', 'label' => 'Field with an error', 'value' => 'not-an-email', 'error' => 'Enter a valid email address, for example you@example.co.tz.']) ?>
                <?= component('field', ['name' => 'sg_sel', 'label' => 'Select', 'type' => 'select', 'options' => ['' => 'Choose one', 'a' => 'Click and collect', 'b' => 'Home delivery']]) ?>
                <?= component('field', ['name' => 'sg_ta', 'label' => 'Textarea', 'type' => 'textarea', 'help' => 'Grows vertically only.']) ?>
                <div class="sl-field">
                    <label class="sl-label" for="sg_dis">Disabled</label>
                    <input class="sl-input" id="sg_dis" type="text" value="Cannot be edited" disabled>
                </div>
            </div>

            <div>
                <div class="sl-card tw-mb-6">
                    <h3 class="t-heading-md tw-mb-4">Choices</h3>
                    <label class="sl-check">
                        <input type="checkbox" checked>
                        <span class="sl-check-label">Checkbox with a longer label that wraps onto a second line so the alignment can be checked</span>
                    </label>
                    <label class="sl-check">
                        <input type="radio" name="sg_radio" checked>
                        <span class="sl-check-label">Radio, selected</span>
                    </label>
                    <label class="sl-check">
                        <input type="radio" name="sg_radio">
                        <span class="sl-check-label">Radio, not selected</span>
                    </label>
                </div>

                <div class="sl-card tw-mb-6">
                    <h3 class="t-heading-md tw-mb-4">Option cards</h3>
                    <div class="tw-flex tw-flex-col tw-gap-3">
                        <label class="sl-option is-selected">
                            <input type="radio" name="sg_opt" class="tw-mt-1" checked>
                            <span><span class="t-body-strong tw-block">Selected</span>
                            <span class="t-micro t-muted">Mint fill marks the choice.</span></span>
                        </label>
                        <label class="sl-option">
                            <input type="radio" name="sg_opt" class="tw-mt-1">
                            <span><span class="t-body-strong tw-block">Unselected</span>
                            <span class="t-micro t-muted">Hairline border only.</span></span>
                        </label>
                        <label class="sl-option is-disabled">
                            <input type="radio" name="sg_opt" class="tw-mt-1" disabled>
                            <span><span class="t-body-strong tw-block">Unavailable</span>
                            <span class="t-micro t-muted">With the reason stated, never just greyed out.</span></span>
                        </label>
                    </div>
                </div>

                <div class="sl-card">
                    <h3 class="t-heading-md tw-mb-4">Quantity stepper</h3>
                    <?= component('quantity', ['value' => 2, 'max' => 12, 'name' => 'sg_qty', 'label' => 'example product', 'unitPrice' => '6200.00']) ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================= CARDS ============================ -->
<section class="<?= e($sectionClass) ?>" id="cards">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-8">8. Cards</h2>

        <div class="sl-grid sl-grid-4 tw-mb-10">
            <div class="sl-card"><p class="t-heading-md tw-mb-2">Standard</p><p class="t-micro t-muted tw-mb-0">Hairline border, 32px padding.</p></div>
            <div class="sl-card sl-card-raised"><p class="t-heading-md tw-mb-2">Raised</p><p class="t-micro t-muted tw-mb-0">The stacked-shadow paper halo.</p></div>
            <div class="sl-card sl-card-featured"><p class="t-heading-md tw-mb-2">Featured</p><p class="t-micro tw-mb-0">Aloe fill, the reference's featured pattern.</p></div>
            <div class="sl-card sl-card-band"><p class="t-heading-md tw-mb-2">Band</p><p class="t-micro tw-mb-0">Pistachio, for a wide category band.</p></div>
        </div>

        <h3 class="t-heading-md tw-mb-4">Product card, both tracks</h3>
        <div class="sl-grid sl-grid-2 tw-mb-6">
            <div class="sl-card" style="background:var(--c-canvas-cream)">
                <p class="t-eyebrow t-muted tw-mb-4">Light</p>
                <?= component('product-card', ['product' => $products[0]]) ?>
            </div>
            <div class="track-cinematic" style="border-radius:var(--r-lg);padding:var(--s-xxl)">
                <p class="t-eyebrow t-muted-dark tw-mb-4">Cinematic</p>
                <?= component('product-card', ['product' => $products[0], 'onDark' => true]) ?>
            </div>
        </div>

        <h3 class="t-heading-md tw-mb-4">Placeholder imagery (OQ-06c)</h3>
        <div class="sl-grid sl-grid-4">
            <?php foreach (['amber', 'forest', 'sky', 'rose'] as $tone) : ?>
                <div class="sl-photo-frame" style="aspect-ratio:4/3">
                    <?= component('product-image', ['tone' => $tone, 'label' => ucfirst($tone) . ' Sample']) ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="t-micro t-muted tw-mt-3 tw-mb-0">
            Deterministic tonal panels standing in for merchant photography, each marked PLACEHOLDER
            so nobody mistakes them for shipped artwork. The cinematic track depends on real photos.
        </p>
    </div>
</section>

<!-- ============================= DATA ============================= -->
<section class="<?= e($sectionClass) ?>" id="data">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-8">9. Data display</h2>

        <h3 class="t-heading-md tw-mb-4">Stat tiles</h3>
        <div class="sl-grid sl-grid-4 tw-mb-10">
            <?php
            $stats = [
                ['Orders today', '47', '+12% on last week', 'up'],
                ['Revenue today', money_compact('2840000'), '+4% on last week', 'up'],
                ['Awaiting collection', '9', '2 overdue', 'down'],
                ['Failed deliveries', '1', 'down from 4', 'up'],
            ];
            foreach ($stats as [$label, $value, $delta, $dir]) : ?>
                <div class="sl-stat">
                    <p class="sl-stat-label tw-mb-0"><?= e($label) ?></p>
                    <p class="sl-stat-value tw-mb-0"><?= e($value) ?></p>
                    <p class="sl-stat-delta sl-stat-delta-<?= e($dir) ?> tw-mb-0"><?= e($delta) ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <h3 class="t-heading-md tw-mb-4">Table</h3>
        <p class="t-micro t-muted tw-mb-4">
            Resize below 768px: the table reflows into stacked label/value cards rather than scrolling
            sideways. A horizontally scrolling order table on a phone is unusable.
        </p>
        <div class="sl-card sl-card-flush tw-mb-10">
            <div class="sl-table-scroll">
            <table class="sl-table sl-table-reflow">
                <caption class="visually-hidden">Example order list</caption>
                <thead>
                    <tr>
                        <th scope="col">Order</th><th scope="col">Customer</th>
                        <th scope="col">Fulfilment</th><th scope="col">Status</th>
                        <th scope="col" class="sl-num">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = [
                        ['SL-2026-9F3K2A', 'Asha M.',   'Collect',  'ready_for_pickup', '40900'],
                        ['SL-2026-7B1X9C', 'Baraka J.', 'Delivery', 'out_for_delivery', '34000'],
                        ['SL-2026-2D8M4T', 'Neema K.',  'Collect',  'collected',        '12400'],
                        ['SL-2026-5H2P7Q', 'Joseph S.', 'Delivery', 'delivery_failed',  '28500'],
                    ];
                    foreach ($rows as [$ref, $cust, $ful, $status, $total]) : ?>
                        <tr>
                            <td data-label="Order"><span class="t-code"><?= e($ref) ?></span></td>
                            <td data-label="Customer"><?= e($cust) ?></td>
                            <td data-label="Fulfilment"><?= e($ful) ?></td>
                            <td data-label="Status"><?= component('badge', ['status' => $status]) ?></td>
                            <td data-label="Total" class="sl-num tabular"><?= e(money($total)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <h3 class="t-heading-md tw-mb-4">Timeline</h3>
        <div class="sl-card">
            <ol class="sl-timeline">
                <li class="sl-timeline-item is-done">
                    <p class="t-body-strong tw-mb-1">Order placed</p>
                    <p class="t-caption t-muted tw-mb-0"><?= time_tag('2026-09-19 06:14:00', true) ?></p>
                </li>
                <li class="sl-timeline-item is-done">
                    <p class="t-body-strong tw-mb-1">Seller accepted</p>
                    <p class="t-caption t-muted tw-mb-0"><?= time_tag('2026-09-19 07:02:00', true) ?></p>
                </li>
                <li class="sl-timeline-item is-current">
                    <p class="t-body-strong tw-mb-1">Being prepared</p>
                    <p class="t-caption t-muted tw-mb-0">In progress</p>
                </li>
                <li class="sl-timeline-item">
                    <p class="t-body-strong tw-mb-1">Ready to collect</p>
                    <p class="t-caption t-muted tw-mb-0">Not reached yet</p>
                </li>
            </ol>
        </div>
    </div>
</section>

<!-- ============================ STATES ============================ -->
<section class="<?= e($sectionClass) ?>" id="states">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-4">10. Loading, empty, success, error</h2>
        <p class="t-body-md t-muted tw-mb-8" style="max-width:62ch">
            Every data-driven screen defines all four. An empty state always offers a way forward -
            a dead end is worse than a wrong guess.
        </p>

        <h3 class="t-heading-md tw-mb-4">Alerts</h3>
        <div class="tw-flex tw-flex-col tw-gap-3 tw-mb-10">
            <?php foreach ([
                ['success', 'check-circle', 'Your order was placed. The collection code is on the order page.'],
                ['info', 'info', 'Payment is being confirmed. The status updates on its own.'],
                ['warn', 'alert', 'This store does not stock every item in this part of the order.'],
                ['danger', 'alert', 'Only 2 left of Chai Bora Loose Leaf Tea. Reduce the quantity to continue.'],
            ] as [$tone, $icon, $text]) : ?>
                <div class="sl-alert sl-alert-<?= e($tone) ?>">
                    <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => $icon, 'size' => 18]) ?></span>
                    <span><?= e($text) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <h3 class="t-heading-md tw-mb-4">Empty state</h3>
        <div class="tw-mb-10">
            <?= component('empty-state', [
                'icon' => 'cart', 'title' => 'Your basket is empty',
                'text' => 'Once you add something it will appear here, grouped by seller.',
                'actionUrl' => route('catalog.index'), 'actionLabel' => 'Start shopping',
                'secondaryUrl' => route('home'), 'secondaryLabel' => 'Back to homepage',
            ]) ?>
        </div>

        <h3 class="t-heading-md tw-mb-4">Loading skeleton</h3>
        <?= component('skeleton-grid', ['count' => 4]) ?>

        <h3 class="t-heading-md tw-mt-10 tw-mb-4">Error pages</h3>
        <div class="tw-flex tw-flex-wrap tw-gap-3">
            <a class="sl-btn sl-btn-outline-light" href="<?= e(route('styleguide.error', ['code' => 403])) ?>">Preview 403</a>
            <a class="sl-btn sl-btn-outline-light" href="<?= e(route('styleguide.error', ['code' => 404])) ?>">Preview 404</a>
            <a class="sl-btn sl-btn-outline-light" href="<?= e(route('styleguide.error', ['code' => 500])) ?>">Preview 500</a>
        </div>

        <h3 class="t-heading-md tw-mt-10 tw-mb-4">The honesty marker</h3>
        <?= component('devnote', [
            'text' => 'This component appears anywhere the interface would otherwise imply working '
                    . 'functionality. It is deliberately unmissable, and it names the phase that makes the thing real.',
        ]) ?>
    </div>
</section>

<!-- ============================= ICONS ============================ -->
<section class="<?= e($sectionClass) ?>" id="icons">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-4">11. Icons</h2>
        <p class="t-body-md t-muted tw-mb-8" style="max-width:62ch">
            Inline SVG, inheriting <code class="t-code">currentColor</code> so one set works in both
            tracks. All are decorative and hidden from assistive technology - the accessible name
            always comes from adjacent text or the control's own label.
        </p>

        <div class="sl-card">
            <div class="tw-flex tw-flex-wrap tw-gap-4">
                <?php foreach ([
                    'search','cart','user','menu','close','chevron-right','chevron-left','chevron-down',
                    'arrow-right','arrow-left','check','check-circle','plus','minus','trash','star',
                    'info','alert','shield','store','truck','package','clock','map-pin','phone','mail',
                    'bell','filter','repeat','qr','leaf','basket','home','heart','baby','cup','drop',
                    'bottle','grain','spice','shirt','spray','roll','smile','egg','bowl','external',
                    'lock','eye','eye-off',
                ] as $iconName) : ?>
                    <span class="tw-flex tw-flex-col tw-items-center tw-gap-1" style="width:76px">
                        <span style="color:var(--c-shade-70)"><?= component('icon', ['name' => $iconName, 'size' => 22]) ?></span>
                        <span class="t-micro t-muted" style="font-size:10px;text-align:center"><?= e($iconName) ?></span>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- =========================== DEVIATIONS ========================= -->
<section class="<?= e($sectionClass) ?>" id="deviations">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-4">12. Where this departs from the reference</h2>
        <p class="t-body-md t-muted tw-mb-8" style="max-width:62ch">
            Recorded here rather than applied silently. Each one needs your approval.
        </p>

        <div class="sl-card sl-card-flush">
            <div class="sl-table-scroll">
            <table class="sl-table sl-table-reflow">
                <caption class="visually-hidden">Documented deviations from the supplied design reference</caption>
                <thead>
                    <tr><th scope="col">Ref</th><th scope="col">Reference says</th><th scope="col">We do</th><th scope="col">Why</th></tr>
                </thead>
                <tbody>
                    <?php foreach ([
                        ['DS-EXT-01', 'Only two mint accents; no third canvas colour', 'Added desaturated amber and red for status only', 'A marketplace must distinguish delivered from rejected. Signalling a failed delivery with text alone is worse for users.'],
                        ['DS-EXT-02', 'Not addressed', 'Every badge has a label and a dot, never colour alone', 'Colour-only status fails for colour-blind users.'],
                        ['A11Y-01', 'Display weight 330 at all display sizes', 'Weight floors at 400 below 48px', 'Thin weights on black lose legibility at small sizes.'],
                        ['A11Y-02', 'shade-40 as tertiary text on light', 'shade-50 on light; shade-40 is dark-track only', 'shade-40 on white is about 2.6:1 and fails WCAG AA for body text.'],
                        ['A11Y-03', 'Pure black body text on white', '#0a0a0a for body on the light track', 'Maximum contrast causes halation for some readers.'],
                        ['A11Y-04', 'No focus treatment specified', '2px offset focus ring, polarity-aware', 'Keyboard users need to see where they are.'],
                        ['A11Y-05', 'Not addressed', 'Transitions capped at 200ms and disabled under reduced-motion', 'Respects the OS-level preference.'],
                        ['TYPE-01', 'ss03 for geometric single-storey a and g', 'ss03 globally, but NOT cv11/cv05', 'In Inter those give a single-storey a that reads as a different typeface and hurts legibility in dense tables.'],
                        ['TRACK-01', 'Marketing is cinematic; transactional is light', 'Legal pages and checkout are on the light track', 'Several hundred words of body text on pure black is measurably harder to read.'],
                        ['BRAND-01', 'Aloe and pistachio never appear on the cinematic track', 'The logo mark keeps its aloe fill on both tracks', 'It is an identity element rather than an accent. Flagged for your call.'],
                    ] as [$ref, $says, $does, $why]) : ?>
                        <tr>
                            <td data-label="Ref"><span class="t-code t-micro"><?= e($ref) ?></span></td>
                            <td data-label="Reference says"><span class="t-caption"><?= e($says) ?></span></td>
                            <td data-label="We do"><span class="t-caption"><?= e($does) ?></span></td>
                            <td data-label="Why"><span class="t-caption t-muted"><?= e($why) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</section>

<!-- ============================ ROUTES ============================ -->
<section class="<?= e($sectionClass) ?>" id="routes">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-4">13. Routes built so far</h2>
        <p class="t-body-md t-muted tw-mb-8" style="max-width:62ch">
            Read live from the router, so this list cannot drift from reality. The six dashboards
            arrive in Phase 1b.
        </p>

        <div class="sl-card sl-card-flush">
            <div class="sl-table-scroll">
            <table class="sl-table sl-table-reflow">
                <caption class="visually-hidden">Registered routes</caption>
                <thead>
                    <tr><th scope="col">Method</th><th scope="col">Path</th><th scope="col">Name</th><th scope="col">Open</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($routes as $r) : ?>
                        <tr>
                            <td data-label="Method"><span class="sl-chip"><?= e($r['method']) ?></span></td>
                            <td data-label="Path"><span class="t-code t-micro"><?= e($r['pattern']) ?></span></td>
                            <td data-label="Name"><span class="t-code t-micro t-muted"><?= e($r['name'] ?? '-') ?></span></td>
                            <td data-label="Open">
                                <?php if ($r['method'] === 'GET' && !str_contains($r['pattern'], '{')) : ?>
                                    <a class="t-micro" href="<?= e(url($r['pattern'])) ?>">View</a>
                                <?php else : ?>
                                    <span class="t-micro t-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</section>

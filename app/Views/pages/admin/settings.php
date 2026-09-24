<?php
declare(strict_types=1);
/**
 * System settings.
 *
 * Typed key/value settings with change auditing (FR-ADM-10). Every change
 * records the before and after values, because "who turned the delivery retry
 * limit down to 1?" needs an answer.
 *
 * Secrets are NOT here. Database credentials, SMTP passwords and payment
 * webhook secrets live in .env, outside the web root and outside version
 * control - not in a table that a compromised admin session could read.
 *
 * @var array<string,list<array<string,mixed>>> $grouped
 */
$typeHelp = [
    'int'     => 'Whole number',
    'decimal' => 'Decimal number',
    'string'  => 'Text',
    'enum'    => 'One of a fixed set',
    'bool'    => 'On or off',
];
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'System settings',
        'subtitle' => 'Platform-wide configuration. Every change records the old and new value.',
    ]) ?>

    <div class="sl-alert sl-alert-warn tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'lock', 'size' => 18]) ?></span>
        <span>
            <strong>Secrets are not on this page, by design.</strong>
            Database credentials, the SMTP password and payment webhook secrets live in the
            <span class="t-code">.env</span> file, outside the web root and outside version control.
            Putting them in a table would mean a compromised admin session could read them.
        </span>
    </div>

    <form method="post" action="<?= e(route('preview.submit')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="feature" value="settings_save">

        <?php foreach ($grouped as $group => $settings) : ?>
            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-5"><?= e($group) ?></h2>

                <?php foreach ($settings as $setting) : ?>
                    <?php $id = 'set-' . preg_replace('/[^a-z0-9]+/i', '-', $setting['key']); ?>
                    <div class="tw-flex tw-flex-wrap tw-items-end tw-gap-4 tw-py-4"
                         style="border-bottom:1px solid var(--c-hairline-light)">
                        <div class="tw-flex-1" style="min-width:16rem">
                            <label class="sl-label tw-mb-1" for="<?= e($id) ?>">
                                <span class="t-code"><?= e($setting['key']) ?></span>
                            </label>
                            <span class="t-micro t-muted">
                                <?= e($typeHelp[$setting['type']] ?? $setting['type']) ?>
                            </span>
                        </div>

                        <div style="min-width:12rem">
                            <?php if ($setting['type'] === 'enum') : ?>
                                <select class="sl-select" id="<?= e($id) ?>" name="settings[<?= e($setting['key']) ?>]">
                                    <?php foreach (['admin' => 'Admin assigns', 'pool' => 'Open pool'] as $value => $label) : ?>
                                        <option value="<?= e($value) ?>"<?= $setting['value'] === $value ? ' selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else : ?>
                                <input class="sl-input <?= in_array($setting['type'], ['int', 'decimal'], true) ? 'tabular' : '' ?>"
                                       id="<?= e($id) ?>"
                                       type="<?= in_array($setting['type'], ['int', 'decimal'], true) ? 'number' : 'text' ?>"
                                       <?= $setting['type'] === 'decimal' ? 'step="0.1"' : '' ?>
                                       name="settings[<?= e($setting['key']) ?>]"
                                       value="<?= e($setting['value']) ?>">
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <section class="sl-card tw-mb-6">
            <h2 class="t-heading-xl tw-mb-2">Why are you changing this?</h2>
            <p class="t-caption t-muted tw-mb-5">
                Recorded in the audit log alongside the before and after values.
            </p>
            <?= component('field', [
                'name' => 'change_reason', 'label' => 'Reason', 'required' => true,
                'placeholder' => 'For example: shortening the collection window for chilled goods',
            ]) ?>
            <button class="sl-btn sl-btn-primary sl-btn-lg" type="submit">Save settings</button>
        </section>
    </form>

    <div class="sl-card">
        <h2 class="t-heading-md tw-mb-3">Where configuration actually lives</h2>
        <?= component('detail-list', ['items' => [
            ['label' => 'Business rules (this page)', 'value' => 'Database, audited'],
            ['label' => 'Secrets and credentials', 'value' => '.env, git-ignored'],
            ['label' => 'Environment differences', 'value' => '.env per machine'],
            ['label' => 'Design tokens', 'value' => 'design-tokens.css'],
        ]]) ?>
        <p class="t-micro t-muted tw-mt-4 tw-mb-0">
            The split matters: a value someone should be able to change at 2am without a deploy
            belongs in the database. A value that would be dangerous to expose belongs in
            <span class="t-code">.env</span>.
        </p>
    </div>
</div>

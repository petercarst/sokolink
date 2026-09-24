<?php
declare(strict_types=1);
/**
 * Accessible form field.
 *
 * Every input gets a real <label for>, help text and error text wired through
 * aria-describedby, and aria-invalid when it fails. That combination is what
 * makes a form usable with a screen reader, and it is easy to get wrong when
 * each form is hand-written - hence one component (NFR-USA-02).
 *
 * @var string      $name
 * @var string      $label
 * @var string      $type      text|email|password|tel|number|textarea|select
 * @var string      $value
 * @var string|null $help
 * @var string|null $error
 * @var bool        $required
 * @var string|null $placeholder
 * @var string|null $autocomplete
 * @var array<string,string> $options  for select
 * @var array<string,string> $attrs    extra attributes
 */
$type         = $type ?? 'text';
$name         = $name ?? '';
$id           = $id ?? ('f-' . preg_replace('/[^a-z0-9]+/i', '-', $name));
$value        = $value ?? old($name, '');
$error        = $error ?? error_for($name);
$required     = $required ?? false;
$options      = $options ?? [];
$attrs        = $attrs ?? [];
$describedBy  = [];

if (!empty($help))  { $describedBy[] = $id . '-help'; }
if (!empty($error)) { $describedBy[] = $id . '-error'; }

$attrString = '';
foreach ($attrs as $k => $v) {
    $attrString .= ' ' . e($k) . '="' . e($v) . '"';
}
if ($describedBy !== []) {
    $attrString .= ' aria-describedby="' . e(implode(' ', $describedBy)) . '"';
}
if (!empty($error)) {
    $attrString .= ' aria-invalid="true"';
}
if (!empty($autocomplete)) {
    $attrString .= ' autocomplete="' . e($autocomplete) . '"';
}
$cls = 'sl-input' . (!empty($error) ? ' is-invalid' : '');
?>
<div class="sl-field">
    <label class="sl-label" for="<?= e($id) ?>">
        <?= e($label ?? '') ?><?php if ($required) : ?><span class="sl-required" aria-hidden="true">*</span><span class="visually-hidden"> (required)</span><?php endif; ?>
    </label>

    <?php if ($type === 'textarea') : ?>
        <textarea class="sl-textarea<?= !empty($error) ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"
                  placeholder="<?= e($placeholder ?? '') ?>"<?= $required ? ' required' : '' ?><?= $attrString ?>><?= e($value) ?></textarea>

    <?php elseif ($type === 'select') : ?>
        <select class="sl-select<?= !empty($error) ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $required ? ' required' : '' ?><?= $attrString ?>>
            <?php foreach ($options as $optValue => $optLabel) : ?>
                <option value="<?= e((string) $optValue) ?>"<?= (string) $optValue === (string) $value ? ' selected' : '' ?>><?= e($optLabel) ?></option>
            <?php endforeach; ?>
        </select>

    <?php else : ?>
        <input class="<?= e($cls) ?>" type="<?= e($type) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"
               value="<?= e($value) ?>" placeholder="<?= e($placeholder ?? '') ?>"<?= $required ? ' required' : '' ?><?= $attrString ?>>
    <?php endif; ?>

    <?php if (!empty($help)) : ?>
        <span class="sl-help" id="<?= e($id) ?>-help"><?= e($help) ?></span>
    <?php endif; ?>

    <?php if (!empty($error)) : ?>
        <span class="sl-error" id="<?= e($id) ?>-error">
            <?= component('icon', ['name' => 'alert', 'size' => 14]) ?><?= e($error) ?>
        </span>
    <?php endif; ?>
</div>

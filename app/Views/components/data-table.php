<?php
declare(strict_types=1);
/**
 * Declarative data table.
 *
 * Most dashboard screens are a list of records with a status and an action.
 * Describing the columns instead of hand-writing 35 tables keeps them
 * consistent, keeps the mobile reflow and the accessible markup in one place,
 * and means a change to how a status or a money column renders happens once.
 *
 * Below 768px it reflows into stacked label/value cards via `data-label`,
 * rather than scrolling sideways.
 *
 * @var string $caption   required, used as the accessible table caption
 * @var bool   $showCaption
 * @var list<array<string,mixed>> $columns
 *      key        string   row key to read
 *      label      string   column heading
 *      type       string   text|code|badge|money|money_compact|datetime|date|
 *                          relative|number|chips|link|avatar|bool|muted|actions
 *      align      string   left|right
 *      href       string   row key holding a URL - renders the cell as a link
 *      badgeLabel string   row key holding an override label for a badge
 *      sub        string   row key holding secondary text under the value
 *      actions    list<array{label:string,href?:string,feature?:string,style?:string}>
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed>|null  $empty   passed to components/empty-state
 */
$columns     = $columns ?? [];
$rows        = $rows ?? [];
$showCaption = $showCaption ?? false;
$caption     = $caption ?? 'Data';

if ($rows === []) {
    echo component('empty-state', $empty ?? [
        'icon'  => 'package',
        'title' => 'Nothing here yet',
        'text'  => 'When there is something to show, it will appear in this list.',
    ]);
    return;
}

/** Renders one cell value according to its declared type. */
$cell = static function (array $col, array $row) : string {
    $type  = $col['type'] ?? 'text';
    $value = $col['key'] ?? null ? ($row[$col['key']] ?? null) : null;

    switch ($type) {
        case 'badge':
            $label = !empty($col['badgeLabel']) ? ($row[$col['badgeLabel']] ?? null) : null;
            return component('badge', array_filter([
                'status' => (string) $value,
                'label'  => $label,
            ], static fn ($v) => $v !== null));

        case 'money':
            return '<span class="tabular">' . e(money((string) $value)) . '</span>';

        case 'money_compact':
            return '<span class="tabular" title="' . e(money((string) $value)) . '">'
                 . e(money_compact((string) $value)) . '</span>';

        case 'number':
            return '<span class="tabular">' . e(number_format((float) $value)) . '</span>';

        case 'code':
            return '<span class="t-code">' . e((string) $value) . '</span>';

        case 'datetime':
            return $value ? time_tag((string) $value) : '<span class="t-muted">&mdash;</span>';

        case 'date':
            return $value ? e(local_date((string) $value)) : '<span class="t-muted">&mdash;</span>';

        case 'relative':
            return $value ? time_tag((string) $value, true) : '<span class="t-muted">&mdash;</span>';

        case 'bool':
            return $value
                ? '<span class="tw-inline-flex tw-items-center tw-gap-1">'
                    . component('icon', ['name' => 'check', 'size' => 16]) . '<span class="visually-hidden">Yes</span></span>'
                : '<span class="t-muted" aria-label="No">&mdash;</span>';

        case 'chips':
            $out = '<span class="tw-flex tw-flex-wrap tw-gap-1 tw-justify-end md:tw-justify-start">';
            foreach ((array) $value as $chip) {
                $out .= '<span class="sl-chip">' . e((string) $chip) . '</span>';
            }
            return $out . '</span>';

        case 'avatar':
            return '<span class="tw-inline-flex tw-items-center tw-gap-2">'
                 . '<span class="sl-avatar" style="width:32px;height:32px;font-size:12px">'
                 . e(initials((string) $value)) . '</span>'
                 . '<span>' . e((string) $value) . '</span></span>';

        case 'muted':
            return '<span class="t-muted">' . e((string) $value) . '</span>';

        default:
            return e((string) $value);
    }
};
?>
<div class="sl-card sl-card-flush">
    <div class="sl-table-scroll">
    <table class="sl-table sl-table-reflow">
        <caption class="<?= $showCaption ? 'tw-p-4 t-caption t-muted tw-text-left' : 'visually-hidden' ?>">
            <?= e($caption) ?>
        </caption>
        <thead>
            <tr>
                <?php foreach ($columns as $col) : ?>
                    <th scope="col" class="<?= ($col['align'] ?? '') === 'right' ? 'sl-num' : '' ?>">
                        <?php if (($col['label'] ?? '') === '') : ?>
                            <span class="visually-hidden">Actions</span>
                        <?php else : ?>
                            <?= e($col['label']) ?>
                        <?php endif; ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <?php foreach ($columns as $col) : ?>
                        <td data-label="<?= e($col['label'] ?? '') ?>"
                            class="<?= ($col['align'] ?? '') === 'right' ? 'sl-num' : '' ?>">

                            <?php if (($col['type'] ?? '') === 'actions') : ?>
                                <span class="tw-flex tw-gap-2 tw-justify-end md:tw-justify-start">
                                    <?php foreach ($col['actions'] ?? [] as $action) : ?>
                                        <?php
                                        $style = $action['style'] ?? 'sl-btn-outline-light';
                                        $href  = is_callable($action['href'] ?? null)
                                            ? $action['href']($row)
                                            : ($action['href'] ?? null);

                                        // An action that has nowhere to go for THIS row is not
                                        // rendered at all. "View" on an unpublished product has no
                                        // public page, and a disabled-looking link to nowhere is
                                        // worse than no link (no dead controls - Phase 1 rule).
                                        if (empty($action['feature']) && empty($action['post']) && ($href === null || $href === '')) {
                                            continue;
                                        }
                                        ?>
                                        <?php if (!empty($action['post'])) : ?>
                                            <form method="post" class="tw-m-0"
                                                  action="<?= e(is_callable($action['post']) ? $action['post']($row) : $action['post']) ?>">
                                                <?= csrf_field() ?>
                                                <?php foreach (($action['fields'] ?? []) as $field => $value) : ?>
                                                    <input type="hidden" name="<?= e($field) ?>"
                                                           value="<?= e((string) (is_callable($value) ? $value($row) : $value)) ?>">
                                                <?php endforeach; ?>
                                                <button type="submit" class="sl-btn <?= e($style) ?> sl-btn-sm">
                                                    <?= e($action['label']) ?>
                                                </button>
                                            </form>
                                        <?php elseif (!empty($action['feature'])) : ?>
                                            <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="feature" value="<?= e($action['feature']) ?>">
                                                <button type="submit" class="sl-btn <?= e($style) ?> sl-btn-sm">
                                                    <?= e($action['label']) ?>
                                                </button>
                                            </form>
                                        <?php else : ?>
                                            <a class="sl-btn <?= e($style) ?> sl-btn-sm" href="<?= e((string) $href) ?>">
                                                <?= e($action['label']) ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </span>

                            <?php elseif (!empty($col['href']) && !empty($row[$col['href']])) : ?>
                                <a href="<?= e((string) $row[$col['href']]) ?>"><?= $cell($col, $row) ?></a>
                                <?php if (!empty($col['sub']) && !empty($row[$col['sub']])) : ?>
                                    <span class="t-micro t-muted tw-block"><?= e((string) $row[$col['sub']]) ?></span>
                                <?php endif; ?>

                            <?php else : ?>
                                <?= $cell($col, $row) ?>
                                <?php if (!empty($col['sub']) && !empty($row[$col['sub']])) : ?>
                                    <span class="t-micro t-muted tw-block"><?= e((string) $row[$col['sub']]) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

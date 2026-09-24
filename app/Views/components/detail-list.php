<?php
declare(strict_types=1);
/**
 * Label/value list used on every detail panel.
 *
 * @var list<array{label:string,value:string,type?:string,badgeLabel?:string}> $items
 *      type: text (default) | badge | money | code | datetime | html
 */
$items = $items ?? [];
?>
<dl class="sl-dl">
    <?php foreach ($items as $item) : ?>
        <div>
            <dt><?= e($item['label']) ?></dt>
            <dd>
                <?php
                switch ($item['type'] ?? 'text') {
                    case 'badge':
                        echo component('badge', array_filter([
                            'status' => (string) $item['value'],
                            'label'  => $item['badgeLabel'] ?? null,
                        ], static fn ($v) => $v !== null));
                        break;
                    case 'money':
                        echo '<span class="tabular">' . e(money((string) $item['value'])) . '</span>';
                        break;
                    case 'code':
                        echo '<span class="t-code">' . e((string) $item['value']) . '</span>';
                        break;
                    case 'datetime':
                        echo time_tag((string) $item['value']);
                        break;
                    case 'html':
                        // Only ever used with strings this file builds itself.
                        echo $item['value'];
                        break;
                    default:
                        echo e((string) $item['value']);
                }
                ?>
            </dd>
        </div>
    <?php endforeach; ?>
</dl>

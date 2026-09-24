<?php
declare(strict_types=1);
/**
 * Page heading for a dashboard screen.
 *
 * @var string      $title
 * @var string|null $subtitle
 * @var list<array{label:string,url?:string,style?:string,icon?:string,post?:string,fields?:array<string,string|int>,confirm?:string,feature?:string}> $actions
 * @var list<array{label:string,url:?string}>|null $crumbs
 * @var string|null $badge
 *
 * An action is one of three things:
 *
 *   `post`     a POST form to that URL, with `fields` as hidden inputs. This is
 *              what a wired action uses.
 *   `url`      an ordinary link.
 *   `feature`  a POST to the Phase 1 sink, for the areas Phase 4 has not
 *              reached. It verifies a real CSRF token and says which phase
 *              connects it, rather than being a button that does nothing.
 */
$actions = $actions ?? [];
?>
<?php if (!empty($crumbs)) : ?>
    <?= partial('breadcrumbs', ['crumbs' => $crumbs]) ?>
<?php endif; ?>

<div class="sl-page-head">
    <div style="min-width:0">
        <h1 class="t-display-md tw-mb-2">
            <?= e($title ?? '') ?>
            <?php if (!empty($badge)) : ?>
                <span class="tw-align-middle tw-ml-2"><?= component('badge', ['status' => $badge]) ?></span>
            <?php endif; ?>
        </h1>
        <?php if (!empty($subtitle)) : ?>
            <p class="t-body-md t-muted tw-mb-0" style="max-width:70ch"><?= e($subtitle) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($actions !== []) : ?>
        <div class="sl-page-actions">
            <?php foreach ($actions as $action) : ?>
                <?php $style = $action['style'] ?? 'sl-btn-outline-light'; ?>

                <?php if (!empty($action['post'])) : ?>
                    <form method="post" action="<?= e($action['post']) ?>" class="tw-m-0"
                          <?= !empty($action['confirm']) ? 'data-confirm="' . e($action['confirm']) . '"' : '' ?>>
                        <?= csrf_field() ?>
                        <?php foreach (($action['fields'] ?? []) as $field => $value) : ?>
                            <input type="hidden" name="<?= e($field) ?>" value="<?= e((string) $value) ?>">
                        <?php endforeach; ?>
                        <button type="submit" class="sl-btn <?= e($style) ?>">
                            <?php if (!empty($action['icon'])) : ?>
                                <?= component('icon', ['name' => $action['icon'], 'size' => 18]) ?>
                            <?php endif; ?>
                            <?= e($action['label']) ?>
                        </button>
                    </form>

                <?php elseif (!empty($action['feature'])) : ?>
                    <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="feature" value="<?= e($action['feature']) ?>">
                        <button type="submit" class="sl-btn <?= e($style) ?>">
                            <?php if (!empty($action['icon'])) : ?>
                                <?= component('icon', ['name' => $action['icon'], 'size' => 18]) ?>
                            <?php endif; ?>
                            <?= e($action['label']) ?>
                        </button>
                    </form>
                <?php else : ?>
                    <a class="sl-btn <?= e($style) ?>" href="<?= e($action['url'] ?? '#') ?>">
                        <?php if (!empty($action['icon'])) : ?>
                            <?= component('icon', ['name' => $action['icon'], 'size' => 18]) ?>
                        <?php endif; ?>
                        <?= e($action['label']) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

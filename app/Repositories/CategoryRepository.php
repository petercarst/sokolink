<?php

declare(strict_types=1);

namespace App\Repositories;

final class CategoryRepository extends Repository
{
    protected string $table = 'categories';

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->selectOne(
            'SELECT * FROM categories WHERE slug = :slug AND is_active = 1',
            ['slug' => $slug]
        );
    }

    /**
     * Top-level categories with a live product count.
     *
     * The count is computed rather than stored. A denormalised counter would be
     * faster and would drift the first time a product was archived without the
     * counter being updated - and a category claiming eleven products that
     * shows four is worse than a slightly slower query.
     *
     * @return list<array<string,mixed>>
     */
    public function topLevelWithCounts(): array
    {
        return $this->select(
            "SELECT c.id, c.slug, c.name, c.icon, c.sort_order,
                    (SELECT COUNT(*)
                       FROM products p
                       JOIN sellers s ON s.id = p.seller_id
                       JOIN categories cc ON cc.id = p.category_id
                      WHERE (cc.id = c.id OR cc.parent_id = c.id)
                        AND p.status = 'published' AND s.status = 'active'
                    ) AS product_count
               FROM categories c
              WHERE c.parent_id IS NULL AND c.is_active = 1
              ORDER BY c.sort_order, c.name"
        );
    }

    /** @return list<array<string,mixed>> */
    public function childrenOf(int $parentId): array
    {
        return $this->select(
            'SELECT id, slug, name, icon, default_consumption_days
               FROM categories
              WHERE parent_id = :parent AND is_active = 1
              ORDER BY sort_order, name',
            ['parent' => $parentId]
        );
    }

    /**
     * The whole tree, flattened, for a <select>. Depth is stored on the row so
     * indentation does not need a recursive query - MariaDB 10.4 has CTEs, but
     * a two-level catalogue does not need one.
     *
     * @return list<array<string,mixed>>
     */
    public function flatTree(): array
    {
        return $this->select(
            'SELECT c.id, c.slug, c.name, c.depth, c.parent_id, c.default_consumption_days,
                    parent.name AS parent_name
               FROM categories c
               LEFT JOIN categories parent ON parent.id = c.parent_id
              WHERE c.is_active = 1
              ORDER BY COALESCE(parent.sort_order, c.sort_order), COALESCE(parent.name, c.name), c.sort_order, c.name'
        );
    }

    /**
     * The breadcrumb trail for a category: its parent, then itself.
     *
     * @return list<array<string,mixed>>
     */
    public function trailFor(int $categoryId): array
    {
        return $this->select(
            'SELECT c.id, c.slug, c.name, c.depth
               FROM categories c
              WHERE c.id = :id
                 OR c.id = (SELECT parent_id FROM categories WHERE id = :id2)
              ORDER BY c.depth',
            ['id' => $categoryId, 'id2' => $categoryId]
        );
    }

    /**
     * The category's default consumption estimate, used as the weakest source
     * of evidence for a reorder reminder - below the customer's own observed
     * interval and below the seller's own hint.
     */
    public function defaultConsumptionDays(int $categoryId): ?int
    {
        $days = $this->scalar(
            'SELECT default_consumption_days FROM categories WHERE id = :id',
            ['id' => $categoryId]
        );

        return $days === null ? null : (int) $days;
    }
}

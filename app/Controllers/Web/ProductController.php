<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Services\CatalogService;
use App\Support\View\Present;

final class ProductController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog = new CatalogService(),
    ) {
    }

    public function show(string $slug): Response
    {
        try {
            $view = $this->catalog->product($slug);
        } catch (DomainRuleException) {
            // A draft, archived or suspended product is a 404 to the public,
            // not a 403. Distinguishing the two would confirm that a product
            // exists to somebody guessing slugs.
            throw new HttpException(404, 'That product is no longer listed.');
        }

        $product = Present::product($view['product']);

        // The product page's stock is the total across every store, which is a
        // different number from any single store's shelf - so it is taken from
        // the availability rows rather than from the listing's per-row figure.
        $product['qty_available'] = (int) $view['total_stock'];
        $product['stock_state']   = match ((string) $view['stock_state']) {
            'out_of_stock' => 'out',
            'low_stock'    => 'low',
            default        => 'in',
        };

        return $this->view('web/product', [
            'title'    => $product['name'],
            'metaDesc' => str_limit($product['description'], 155),
            'product'  => $product,
            'stores'   => $this->availability($view['availability']),
            'reviews'  => Present::reviews($view['reviews']),
            'related'  => Present::products($view['related']),
        ], 'public');
    }

    /**
     * Stock per collection point.
     *
     * Each store carries its own figure: "3 left" across two shops is one or
     * two in each, and someone deciding where to collect from needs the number
     * for the shop they would actually walk into.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function availability(array $rows): array
    {
        return array_map(
            static function (array $row): array {
                $available = (int) $row['qty_available'];

                return [
                    'id'            => (int) $row['store_id'],
                    'slug'          => (string) $row['store_slug'],
                    'name'          => (string) $row['store_name'],
                    'district'      => (string) $row['district'],
                    'region'        => (string) $row['region'],
                    'qty_available' => $available,
                    'stock_state'   => match (true) {
                        $available <= 0 => 'out',
                        $available <= (int) $row['low_stock_threshold'] => 'low',
                        default => 'in',
                    },
                ];
            },
            $rows
        );
    }
}

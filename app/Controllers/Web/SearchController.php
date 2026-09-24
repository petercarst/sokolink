<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Response;
use App\Services\CatalogService;
use App\Support\View\Present;

/**
 * Full-text search.
 *
 * The search box is the one input on the public site that anybody will type
 * anything into, so two things matter here beyond finding products: an empty
 * query must not run a query, and a query full of fulltext operator characters
 * must not produce a 500. The second of those was a real bug, found by the
 * Phase 3 tests and fixed in ProductRepository::search().
 */
final class SearchController extends Controller
{
    private const PER_PAGE = 12;

    public function __construct(
        private readonly CatalogService $catalog = new CatalogService(),
    ) {
    }

    public function index(): Response
    {
        $request = $this->request();
        $query   = trim((string) $request->query('q', ''));

        $filters = [
            'q'          => $query,
            'category'   => (string) $request->query('category', ''),
            'seller'     => (string) $request->query('seller', ''),
            'min'        => (string) $request->query('min', ''),
            'max'        => (string) $request->query('max', ''),
            'stock'      => (string) $request->query('stock', ''),
            'fulfilment' => (string) $request->query('fulfilment', ''),
            'rating'     => (string) $request->query('rating', ''),
            'sort'       => (string) $request->query('sort', 'relevance'),
        ];

        $page   = max(1, (int) $request->query('page', 1));
        $result = $query === ''
            ? ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => self::PER_PAGE]
            : $this->results($filters, $page);

        return $this->view('web/search', [
            'title'           => $query === '' ? 'Search' : 'Results for ' . $query,
            'metaDesc'        => 'Search products across every approved seller on SokoLink.',
            'query'           => $query,
            'filters'         => $filters,
            'result'          => $result,
            // Shown when a search finds nothing. A dead end is worse than a
            // wrong guess (USER_FLOWS.md Flow D).
            'suggestions'     => Present::products($this->catalog->featured(4)),
            'categoryOptions' => $this->catalog->categoryFilterOptions(),
            'stores'          => Present::stores($this->catalog->storeFilterOptions()),
        ], 'public');
    }

    /**
     * Search results, narrowed by whatever filters are also set.
     *
     * browse() rather than search(): the results page carries the same filter
     * panel as the catalogue, and a query that ignored the price filter beside
     * it would be a control that does nothing. browse() matches on name and
     * brand with LIKE, which is the behaviour a filtered result set needs -
     * relevance ranking cannot be combined with an arbitrary WHERE and still
     * paginate correctly.
     *
     * @param  array<string,string> $filters
     * @return array<string,mixed>
     */
    private function results(array $filters, int $page): array
    {
        $result = $this->catalog->browse([
            'q'          => $filters['q'],
            'category'   => $filters['category'],
            'seller'     => $filters['seller'],
            'min_price'  => $filters['min'] !== '' ? $filters['min'] : null,
            'max_price'  => $filters['max'] !== '' ? $filters['max'] : null,
            'in_stock'   => $filters['stock'] === 'in',
            'fulfilment' => $filters['fulfilment'],
            'min_rating' => $filters['rating'] !== '' ? $filters['rating'] : null,
            'sort'       => $filters['sort'],
        ], $page, self::PER_PAGE);

        return [
            'items'    => Present::products($result['products']),
            'total'    => $result['total'],
            'page'     => $result['page'],
            'pages'    => $result['pages'],
            'per_page' => $result['perPage'],
        ];
    }
}

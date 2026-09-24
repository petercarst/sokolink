<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Services\CatalogService;
use App\Support\View\Present;

/**
 * The public listing, shared by /products and /category/{slug}.
 *
 * Phase 4: the mock catalogue is gone. Every product on this page is a
 * `published` row belonging to an `active` seller, and that condition lives in
 * ProductRepository rather than here - a controller is not where visibility
 * should be decided.
 */
final class CatalogController extends Controller
{
    private const PER_PAGE = 12;

    public function __construct(
        private readonly CatalogService $catalog = new CatalogService(),
    ) {
    }

    public function index(): Response
    {
        $filters = $this->filtersFromRequest();
        $page    = $this->page();

        $result = $this->catalog->browse($this->toServiceFilters($filters), $page, self::PER_PAGE);

        return $this->renderListing(
            title: 'All products',
            heading: 'Everything on SokoLink',
            intro: 'Browse the full catalogue from every approved seller. Filter by category, price, or how you want to receive your order.',
            category: null,
            filters: $filters,
            result: $result
        );
    }

    public function category(string $slug): Response
    {
        $filters             = $this->filtersFromRequest();
        $filters['category'] = $slug;

        try {
            $view = $this->catalog->category($slug, $this->toServiceFilters($filters), $this->page());
        } catch (DomainRuleException) {
            throw new HttpException(404, 'That category does not exist.');
        }

        $trail  = $view['trail'];
        $parent = count($trail) > 1 ? $trail[0] : null;

        $category = [
            'slug'   => (string) $view['category']['slug'],
            'name'   => (string) $view['category']['name'],
            'parent' => $parent !== null && (int) $parent['id'] !== (int) $view['category']['id']
                ? ['slug' => (string) $parent['slug'], 'name' => (string) $parent['name']]
                : null,
        ];

        return $this->renderListing(
            title: $category['name'],
            heading: $category['name'],
            intro: 'Products in ' . $category['name'] . ' from approved sellers.',
            category: $category,
            filters: $filters,
            result: $view['products']
        );
    }

    /**
     * The filters as the query string gives them.
     *
     * Kept in the view's own vocabulary (`min`, `max`, `stock`) because the
     * filter panel renders these values straight back into its inputs. They are
     * translated for the service in toServiceFilters() and never reach a query
     * in this form.
     *
     * @return array<string,string>
     */
    private function filtersFromRequest(): array
    {
        $request = $this->request();

        return [
            'q'          => trim((string) $request->query('q', '')),
            'category'   => (string) $request->query('category', ''),
            'seller'     => (string) $request->query('seller', ''),
            'min'        => (string) $request->query('min', ''),
            'max'        => (string) $request->query('max', ''),
            'stock'      => (string) $request->query('stock', ''),
            'fulfilment' => (string) $request->query('fulfilment', ''),
            'rating'     => (string) $request->query('rating', ''),
            'sort'       => (string) $request->query('sort', 'relevance'),
        ];
    }

    /**
     * @param  array<string,string> $filters
     * @return array<string,mixed>
     */
    private function toServiceFilters(array $filters): array
    {
        return [
            'q'          => $filters['q'],
            'category'   => $filters['category'],
            'seller'     => $filters['seller'],
            'min_price'  => $filters['min'] !== '' ? $filters['min'] : null,
            'max_price'  => $filters['max'] !== '' ? $filters['max'] : null,
            'in_stock'   => $filters['stock'] === 'in',
            'fulfilment' => $filters['fulfilment'],
            'min_rating' => $filters['rating'] !== '' ? $filters['rating'] : null,
            'sort'       => $filters['sort'],
        ];
    }

    private function page(): int
    {
        return max(1, (int) $this->request()->query('page', 1));
    }

    /**
     * @param array<string,mixed>|null $category
     * @param array<string,string>     $filters
     * @param array<string,mixed>      $result  from CatalogService::browse()
     */
    private function renderListing(
        string $title,
        string $heading,
        string $intro,
        ?array $category,
        array $filters,
        array $result
    ): Response {
        return $this->view('web/catalog', [
            'title'           => $title,
            'metaDesc'        => $intro,
            'heading'         => $heading,
            'intro'           => $intro,
            'category'        => $category,
            'filters'         => $filters,
            'result'          => [
                'items'    => Present::products($result['products']),
                'total'    => $result['total'],
                'page'     => $result['page'],
                'pages'    => $result['pages'],
                'per_page' => $result['perPage'],
            ],
            'demoState'       => '',
            'categoryOptions' => $this->catalog->categoryFilterOptions(),
            'stores'          => Present::stores($this->catalog->storeFilterOptions()),
        ], 'public');
    }
}

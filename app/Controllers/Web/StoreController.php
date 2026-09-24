<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Repositories\StoreRepository;
use App\Services\CatalogService;
use App\Support\View\Present;

final class StoreController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog = new CatalogService(),
        private readonly StoreRepository $stores = new StoreRepository(),
    ) {
    }

    public function show(string $slug): Response
    {
        try {
            $view = $this->catalog->store($slug, max(1, (int) $this->request()->query('page', 1)));
        } catch (DomainRuleException) {
            throw new HttpException(404, 'That store page does not exist.');
        }

        $products = $view['products']['products'];

        $store = Present::store(
            $view['store'],
            $view['hours'],
            (int) $view['products']['total']
        );

        // The seller's other branches, so someone looking at the wrong side of
        // town can find the right one.
        $sellerStores = Present::stores(
            $this->stores->allPublished((int) $view['store']['seller_id'])
        );

        return $this->view('web/store', [
            'title'        => $store['name'],
            'metaDesc'     => $store['name'] . ' in ' . $store['district'] . ', ' . $store['region']
                              . '. Order online and collect in store, or have it delivered.',
            'store'        => $store,
            'sellerStores' => $sellerStores,
            'products'     => Present::products($products),
        ], 'public');
    }
}

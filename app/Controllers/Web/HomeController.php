<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Response;
use App\Services\CatalogService;
use App\Support\View\Present;

final class HomeController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog = new CatalogService(),
    ) {
    }

    public function index(): Response
    {
        return $this->view('web/home', [
            'title'      => 'Order online, collect in store or get it delivered',
            'metaDesc'   => 'Buy from trusted local sellers, then collect from a store near you or have it delivered. Reorder the things you buy regularly in one tap.',
            'categories' => Present::categories($this->catalog->categoryNavigation()),
            'featured'   => Present::products($this->catalog->featured(8)),
            'stores'     => Present::stores($this->catalog->storeFilterOptions()),
        ], 'public');
    }
}

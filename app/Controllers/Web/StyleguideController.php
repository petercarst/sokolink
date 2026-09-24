<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Core\Router;
use App\Services\CatalogService;
use App\Support\View\Present;

/**
 * The living styleguide.
 *
 * Built first, on purpose: reviewing the design direction on one page is far
 * cheaper than discovering an inconsistency on screen 60
 * (docs/DEVELOPMENT_ROADMAP.md, "What I need from you to start Phase 1").
 */
final class StyleguideController extends Controller
{
    public function index(): Response
    {
        return $this->view('web/styleguide', [
            'title'    => 'Design system',
            'metaDesc' => 'Every token and component in the SokoLink design system, on one page.',
            // Real products, so the gallery shows what a card actually looks
            // like with the data it will receive - including an empty brand or
            // a missing photograph, which sample data always tidies away.
            'products' => Present::products((new CatalogService())->browse([], 1, 3)['products']),
            'routes'   => Router::all(),
            'track'    => 'transactional',
        ], 'public');
    }

    /**
     * Renders an error page on demand so the 403/404/500 designs can be
     * reviewed without having to cause a real fault.
     */
    public function errorPreview(string $code): Response
    {
        $status = (int) $code;

        if (!in_array($status, [403, 404, 500], true)) {
            throw new HttpException(404, 'No preview for that status code.');
        }

        return $this->view('errors/' . $status, [
            'title' => match ($status) {
                403     => 'Access denied',
                404     => 'Page not found',
                default => 'Something went wrong',
            },
            'status'    => $status,
            'message'   => match ($status) {
                403     => 'You do not have permission to view that page.',
                404     => 'We could not find that page.',
                default => 'An unexpected error occurred on our side.',
            },
            'reference' => $status === 500 ? 'A7F3C2' : null,
            'exception' => null,
            'isPreview' => true,
            'track'     => 'cinematic',
        ], 'public');
    }
}

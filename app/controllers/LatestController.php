<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Nft;
use App\Product;

/**
 * "Latest": the newest items across both catalogs (NFTs and digital-art
 * products) on one page. It has no filters or database of its own - it
 * runs the same Nft::search()/Product::search() the collection and shop
 * pages use, sorted newest-first, and renders the same nft-card/
 * product-card partials they already use for the grid.
 *
 * Gated the same as /collection and /shop (see Router::MARKETPLACE_GATE_PATHS):
 * only a marketplace-activated account reaches this method at all.
 */
final class LatestController extends Controller
{
    private const PER_CATALOG = 12;

    public function index(): void
    {
        $nfts = Nft::search(Nft::normalizeFilters(['sort' => 'newest', 'status' => 'listed']), self::PER_CATALOG);
        $products = Product::search(Product::normalizeFilters(['sort' => 'newest']), self::PER_CATALOG);

        $this->view('public/latest', [
            'title'    => 'Latest',
            'nfts'     => $nfts['items'],
            'products' => $products['items'],
        ]);
    }
}

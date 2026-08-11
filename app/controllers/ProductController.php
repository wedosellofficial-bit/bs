<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Lib\Fmt;
use App\Lib\Logger;
use App\Lib\RateLimiter;
use App\Lib\Request;
use App\Membership;
use App\Product;
use App\ProductOrders;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The digital-art product shop: search/filter/sort and the product page.
 *
 * A plain GET form rather than the AJAX-fragment pattern CollectionController
 * uses for NFTs - every filter change is a full page load. Slower to use,
 * but it works with JavaScript off, needs no client-side wiring, and the
 * filtering itself (Product::search()) is the same server-side, indexed
 * approach either way.
 */
final class ProductController extends Controller
{
    public function index(): void
    {
        $filters = Product::normalizeFilters($this->filtersFromQuery());
        $results = Product::search($filters);
        $user = Auth::user();

        $this->view('public/shop', [
            'title'       => 'Collections',
            'filters'     => $filters,
            'results'     => $results,
            'categories'  => Product::categories(),
            'tags'        => Product::allTags(),
            'priceBounds' => Product::priceBounds(),
            'sortOptions' => Product::sortOptions(),
            'isMember'    => Membership::isMember($user),
        ]);
    }

    public function show(array $params): void
    {
        $slug = (string) ($params['slug'] ?? '');
        $product = Product::findBySlug($slug);

        if ($product === null || $product['status'] !== Product::STATUS_LISTED) {
            $this->notFound('That product is not available.');
        }

        $user = Auth::user();
        $isMember = Membership::isMember($user);
        $locked = (bool) $product['category_is_members_only'] && !$isMember;

        $this->view('public/product-detail', [
            'title'      => (string) $product['name'],
            'product'    => $product,
            'tags'       => Product::tagsFor((int) $product['id']),
            'isMember'   => $isMember,
            'locked'     => $locked,
            'price'      => Product::effectivePriceMinor($product, $isMember),
            'alreadyOwned' => $user !== null && ProductOrders::ownsProduct((int) $user['id'], (int) $product['id']),
        ]);
    }

    public function buy(array $params): void
    {
        $user = Auth::requireVerified();
        $userId = (int) $user['id'];
        $slug = (string) ($params['slug'] ?? '');
        $product = Product::findBySlug($slug);

        if ($product === null) {
            $this->notFound('That product is not available.');
        }

        if (!RateLimiter::attempt('purchase', (string) $userId)) {
            $this->back('/products/' . $slug, 'error', RateLimiter::waitMessage('purchase', (string) $userId));
        }

        try {
            ProductOrders::purchase($userId, (int) $product['id'], Membership::isMember($user));
        } catch (RuntimeException $e) {
            $this->back('/products/' . $slug, 'error', $e->getMessage());
        } catch (Throwable $e) {
            Logger::write('product_order.purchase_failed', $e->getMessage(), [
                'user_id'    => $userId,
                'product_id' => (int) $product['id'],
            ]);

            $this->back('/products/' . $slug, 'error', 'The purchase could not be completed. Your balance has not been charged.');
        }

        $this->back(
            '/account/product-orders',
            'success',
            'Purchased. Your download is ready below.'
        );
    }

    /** @return array<string,mixed> */
    private function filtersFromQuery(): array
    {
        return [
            'category'  => Request::query('category'),
            'tag'       => Request::query('tag'),
            // The filter fields collect a dollar amount, same as the admin
            // price field, then convert to minor units here rather than
            // asking a shopper to type in cents. An unparsable value (a
            // half-typed "$1,2") is treated as no filter, not an error -
            // this is a filter box, not a form with a submit button to
            // reject.
            'min_price' => $this->parsePriceFilter(Request::query('min_price')),
            'max_price' => $this->parsePriceFilter(Request::query('max_price')),
            'q'         => Request::query('q'),
            'sort'      => Request::query('sort', 'newest'),
            'page'      => Request::queryInt('page', 1),
        ];
    }

    private function parsePriceFilter(string $raw): ?int
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            return Fmt::parseMoneyToMinor($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}

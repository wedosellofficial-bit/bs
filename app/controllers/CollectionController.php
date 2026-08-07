<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Lib\Fmt;
use App\Lib\Request;
use App\Lib\Response;
use App\Lib\View;
use App\Nft;
use App\Ordinals;

/**
 * The collection browser and NFT detail page.
 *
 * The browser is server-rendered first and then updated over AJAX. Both
 * paths run the same Nft::search() against the same normalised filters,
 * so a shared URL and an in-page filter change always produce the same
 * grid - which is the whole point of writing filter state to the query
 * string.
 */
final class CollectionController extends Controller
{
    public function index(): void
    {
        $filters = Nft::normalizeFilters($this->filtersFromQuery());
        $results = Nft::search($filters);

        $this->view('public/collection', [
            'title'        => 'Collection',
            'filters'      => $filters,
            'results'      => $results,
            'summary'      => $this->summary($results['total'], $filters),
            'emptyMessage' => $this->summary($results['total'], $filters),
            'facets'       => Nft::facets($filters['status'], $filters['collection']),
            'priceBounds'  => Nft::priceBounds($filters['status']),
            'collections'  => Nft::collections(),
            'sortOptions'  => Nft::sortOptions(),
        ]);
    }

    /**
     * AJAX endpoint for the grid.
     *
     * Returns rendered HTML rather than JSON rows: the card markup is
     * non-trivial (status badges, mono token ids, rarity), and duplicating
     * it in JavaScript would mean two templates to keep in step - and one
     * of them building HTML from strings on the client, which is where
     * escaping bugs come from.
     */
    public function results(): void
    {
        $filters = Nft::normalizeFilters($this->filtersFromQuery());
        $results = Nft::search($filters);
        $summary = $this->summary($results['total'], $filters);

        Response::json([
            'ok'      => true,
            'html'    => View::partial('partials/nft-grid', [
                'results'      => $results,
                'filters'      => $filters,
                'emptyMessage' => $summary,
            ]),
            'total'   => $results['total'],
            'page'    => $results['page'],
            'pages'   => $results['pages'],
            'summary' => $summary,
            'url'     => View::filterUrl($filters, []),
        ]);
    }

    public function show(array $params): void
    {
        $nft = Nft::find($this->id($params));

        if ($nft === null) {
            $this->notFound('That item is not in the catalogue.');
        }

        // Reserved items are inventory deliberately held back; they should
        // not be reachable by guessing an id.
        if ($nft['status'] === Nft::STATUS_RESERVED) {
            $this->notFound('That item is not currently available.');
        }

        $order = null;
        if (in_array($nft['status'], [Nft::STATUS_SOLD, Nft::STATUS_TRANSFERRED], true)) {
            $order = Database::first(
                'SELECT tx_hash, completed_at, status FROM orders WHERE nft_id = ?',
                [(int) $nft['id']]
            );
        }

        $this->view('public/nft-detail', [
            'title'      => (string) $nft['name'],
            'nft'        => $nft,
            'attributes' => Nft::attributes($nft),
            'order'      => $order,
            'explorerInscription' => Ordinals::explorerInscriptionUrl((string) $nft['token_id']),
            'explorerTx' => $order !== null && is_string($order['tx_hash'] ?? null)
                ? Ordinals::explorerTxUrl((string) $order['tx_hash'])
                : '',
            'related'    => $this->related($nft),
        ]);
    }

    /** @return array<string,mixed> */
    private function filtersFromQuery(): array
    {
        return [
            'collection' => Request::query('collection'),
            'min_price'  => Request::query('min_price'),
            'max_price'  => Request::query('max_price'),
            'traits'     => Request::queryList('traits'),
            'status'     => Request::query('status', 'listed'),
            'sort'       => Request::query('sort', 'newest'),
            'page'       => Request::queryInt('page', 1),
            'q'          => Request::query('q'),
        ];
    }

    /**
     * The line above the grid.
     *
     * When a filter set returns nothing, it names the filter to loosen
     * rather than saying "no results" - the user already knows there are
     * no results; what they need is which of their four selections is the
     * one doing the excluding.
     */
    private function summary(int $total, array $filters): string
    {
        if ($total > 0) {
            return $total === 1 ? '1 item' : number_format($total) . ' items';
        }

        $traitCount = array_sum(array_map('count', $filters['traits']));

        if ($traitCount > 0) {
            $types = array_keys($filters['traits']);

            if ($traitCount === 1) {
                return "No items match that trait. Clearing the {$types[0]} filter will widen the results.";
            }

            return sprintf(
                'No items have all of these at once. Try removing one — you have %s applied.',
                $this->humanList($types)
            );
        }

        if ($filters['min_price'] !== null || $filters['max_price'] !== null) {
            $bounds = Nft::priceBounds($filters['status']);

            return sprintf(
                'Nothing in that price range. Available items run from %s to %s.',
                Fmt::money($bounds['min']),
                Fmt::money($bounds['max'])
            );
        }

        if ($filters['q'] !== '') {
            return 'Nothing matches that search. Try a shorter term, or paste a full inscription id.';
        }

        if ($filters['collection'] !== '') {
            return 'This collection has nothing listed right now. Switch to All collections to see the rest of the store.';
        }

        return $filters['status'] === 'listed'
            ? 'Nothing is listed at the moment. Switch the status filter to Sold to browse past items.'
            : 'Nothing to show here yet.';
    }

    /**
     * "Background, Form and Ink" - an Oxford-comma-free list that reads
     * as a sentence rather than as a debug dump.
     *
     * @param list<string> $items
     */
    private function humanList(array $items): string
    {
        if (count($items) > 4) {
            $items = array_slice($items, 0, 3);
            $items[] = 'others';
        }

        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items) . ' and ' . $last;
    }

    /** A few other items from the same collection. */
    private function related(array $nft): array
    {
        if ($nft['collection_id'] === null) {
            return [];
        }

        return Database::all(
            "SELECT id, name, token_id, price_minor, status, preview_path, inscription_number
               FROM nfts
              WHERE collection_id = ? AND id <> ? AND status = 'listed'
              ORDER BY RAND()
              LIMIT 4",
            [(int) $nft['collection_id'], (int) $nft['id']]
        );
    }
}

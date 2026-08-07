<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth;
use App\Controllers\Controller;
use App\Database;
use App\Lib\Fmt;
use App\Lib\ImageStore;
use App\Lib\Logger;
use App\Lib\Request;
use App\Nft;
use App\Ordinals;
use InvalidArgumentException;
use Throwable;

final class AdminInventoryController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $status = Request::query('status', 'all');
        $search = Request::query('q');

        $clauses = ['1 = 1'];
        $params = [];

        if (in_array($status, ['listed', 'reserved', 'sold', 'transferred'], true)) {
            $clauses[] = 'n.status = ?';
            $params[] = $status;
        }

        if ($search !== '') {
            $clauses[] = '(n.name LIKE ? OR n.token_id LIKE ?)';
            $escaped = '%' . addcslashes($search, '%_\\') . '%';
            $params[] = $escaped;
            $params[] = $escaped;
        }

        $this->view('admin/inventory', [
            'title'  => 'Inventory',
            'items'  => Database::all(
                'SELECT n.*, c.name AS collection_name
                   FROM nfts n
                   LEFT JOIN collections c ON c.id = n.collection_id
                  WHERE ' . implode(' AND ', $clauses) . '
                  ORDER BY n.id DESC
                  LIMIT 200',
                $params
            ),
            'status' => $status,
            'search' => $search,
        ], 'layout/admin');
    }

    public function create(): void
    {
        Auth::requireAdmin();

        $this->view('admin/inventory-form', [
            'title'       => 'Add inscription',
            'item'        => null,
            'collections' => Nft::collections(false),
        ], 'layout/admin');
    }

    public function store(): void
    {
        $admin = Auth::requireAdmin();

        try {
            $data = $this->validateInput();
        } catch (InvalidArgumentException $e) {
            $this->back('/admin/inventory/new', 'error', $e->getMessage());
        }

        if (Nft::findByToken($data['token_id']) !== null) {
            $this->back('/admin/inventory/new', 'error', 'That inscription id is already in the catalogue.');
        }

        $image = ['image_path' => null, 'preview_path' => null, 'mime' => null, 'width' => null, 'height' => null];

        if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $image = ImageStore::storeUpload($_FILES['image']);
            } catch (Throwable $e) {
                $this->back('/admin/inventory/new', 'error', $e->getMessage());
            }
        }

        Database::run(
            'INSERT INTO nfts
                (token_id, contract_address, chain, inscription_number, sat_ordinal, collection_id,
                 name, description, image_path, preview_path, image_mime, image_width, image_height,
                 attributes, price_minor, status, created_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [
                $data['token_id'],
                'bitcoin-ordinals',
                $data['inscription_number'],
                $data['sat_ordinal'],
                $data['collection_id'],
                $data['name'],
                $data['description'],
                $image['image_path'],
                $image['preview_path'],
                $image['mime'],
                $image['width'],
                $image['height'],
                $data['attributes_json'],
                $data['price_minor'],
                $data['status'],
            ]
        );

        $nftId = Database::lastInsertId();
        Nft::syncAttributes($nftId);

        Logger::audit('admin.nft_created', 'Inscription added to catalogue', (int) $admin['id'], 'nft', $nftId, [
            'token_id'    => $data['token_id'],
            'price_minor' => $data['price_minor'],
        ]);

        $this->back('/admin/inventory/' . $nftId, 'success', 'Added. Recompute rarity when the collection is complete.');
    }

    public function edit(array $params): void
    {
        Auth::requireAdmin();
        $item = Nft::find($this->id($params));

        if ($item === null) {
            $this->notFound('No such item.');
        }

        $this->view('admin/inventory-form', [
            'title'       => 'Edit ' . (string) $item['name'],
            'item'        => $item,
            'collections' => Nft::collections(false),
            'order'       => Database::first(
                'SELECT o.id, o.status, u.email FROM orders o JOIN users u ON u.id = o.user_id WHERE o.nft_id = ?',
                [(int) $item['id']]
            ),
        ], 'layout/admin');
    }

    public function update(array $params): void
    {
        $admin = Auth::requireAdmin();
        $nftId = $this->id($params);

        $existing = Nft::find($nftId);
        if ($existing === null) {
            $this->notFound('No such item.');
        }

        try {
            $data = $this->validateInput();
        } catch (InvalidArgumentException $e) {
            $this->back('/admin/inventory/' . $nftId, 'error', $e->getMessage());
        }

        // Repricing something already sold would rewrite what the buyer was
        // charged on their order screen.
        if ($existing['status'] !== 'listed' && $existing['status'] !== 'reserved'
            && (int) $existing['price_minor'] !== $data['price_minor']) {
            $this->back('/admin/inventory/' . $nftId, 'error', 'This item has sold - its price is part of a completed order and cannot change.');
        }

        $imageClauses = '';
        $imageParams = [];

        if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $image = ImageStore::storeUpload($_FILES['image']);
            } catch (Throwable $e) {
                $this->back('/admin/inventory/' . $nftId, 'error', $e->getMessage());
            }

            $imageClauses = ', image_path = ?, preview_path = ?, image_mime = ?, image_width = ?, image_height = ?';
            $imageParams = [
                $image['image_path'],
                $image['preview_path'],
                $image['mime'],
                $image['width'],
                $image['height'],
            ];

            // Only remove the old files once the new ones are safely
            // written.
            ImageStore::delete(
                is_string($existing['image_path'] ?? null) ? $existing['image_path'] : null,
                is_string($existing['preview_path'] ?? null) ? $existing['preview_path'] : null
            );
        }

        Database::run(
            "UPDATE nfts
                SET token_id = ?, inscription_number = ?, sat_ordinal = ?, collection_id = ?,
                    name = ?, description = ?, attributes = ?, price_minor = ?, status = ?
                    {$imageClauses},
                    updated_at = UTC_TIMESTAMP()
              WHERE id = ?",
            array_merge([
                $data['token_id'],
                $data['inscription_number'],
                $data['sat_ordinal'],
                $data['collection_id'],
                $data['name'],
                $data['description'],
                $data['attributes_json'],
                $data['price_minor'],
                $data['status'],
            ], $imageParams, [$nftId])
        );

        Nft::syncAttributes($nftId);

        Logger::audit('admin.nft_updated', 'Inscription updated', (int) $admin['id'], 'nft', $nftId);

        $this->back('/admin/inventory/' . $nftId, 'success', 'Saved.');
    }

    public function destroy(array $params): void
    {
        $admin = Auth::requireAdmin();
        $nftId = $this->id($params);

        $item = Nft::find($nftId);
        if ($item === null) {
            $this->notFound('No such item.');
        }

        // A sold item is referenced by an order, and orders are financial
        // records. Deleting it would either break the foreign key or
        // orphan a receipt.
        $order = Database::first('SELECT id FROM orders WHERE nft_id = ?', [$nftId]);
        if ($order !== null) {
            $this->back('/admin/inventory/' . $nftId, 'error',
                'This item has an order against it and cannot be deleted. Set its status to reserved to hide it instead.');
        }

        ImageStore::delete(
            is_string($item['image_path'] ?? null) ? $item['image_path'] : null,
            is_string($item['preview_path'] ?? null) ? $item['preview_path'] : null
        );

        Database::run('DELETE FROM nfts WHERE id = ?', [$nftId]);

        Logger::audit('admin.nft_deleted', 'Inscription removed from catalogue', (int) $admin['id'], 'nft', $nftId, [
            'token_id' => (string) $item['token_id'],
        ]);

        $this->back('/admin/inventory', 'success', 'Deleted.');
    }

    public function recomputeRarity(): void
    {
        $admin = Auth::requireAdmin();

        $collectionId = Request::postInt('collection_id', 0);
        $updated = Nft::recomputeRarity($collectionId > 0 ? $collectionId : null);

        Logger::audit('admin.rarity_recomputed', "Rarity recomputed for {$updated} items", (int) $admin['id']);

        $this->back('/admin/inventory', 'success', "Rarity recomputed for {$updated} items.");
    }

    /**
     * @return array{
     *   token_id:string, inscription_number:?int, sat_ordinal:?int, collection_id:?int,
     *   name:string, description:?string, attributes_json:?string, price_minor:int, status:string
     * }
     */
    private function validateInput(): array
    {
        $tokenId = strtolower(Request::post('token_id'));

        if (!Ordinals::isValidInscriptionId($tokenId)) {
            throw new InvalidArgumentException(
                'Inscription id must be the 64-character reveal txid followed by "i" and the index, e.g. 6fb976ab…2799i0.'
            );
        }

        $name = Request::post('name');
        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Name is required and must be 160 characters or fewer.');
        }

        $price = Fmt::parseMoneyToMinor(Request::post('price'));
        if ($price <= 0) {
            throw new InvalidArgumentException('Price must be greater than zero.');
        }

        $status = Request::post('status', 'listed');
        if (!in_array($status, ['listed', 'reserved', 'sold', 'transferred'], true)) {
            throw new InvalidArgumentException('Unknown status.');
        }

        // Attributes are pasted as JSON. Validate before storing so a
        // malformed blob fails here rather than silently producing an item
        // with no traits and no explanation.
        $attributesRaw = trim((string) ($_POST['attributes'] ?? ''));
        $attributesJson = null;

        if ($attributesRaw !== '') {
            $decoded = json_decode($attributesRaw, true);

            if (!is_array($decoded)) {
                throw new InvalidArgumentException(
                    'Attributes must be valid JSON - either [{"trait_type":"Background","value":"Gold"}] or {"Background":"Gold"}.'
                );
            }

            $attributesJson = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $inscriptionNumber = Request::post('inscription_number');
        $satOrdinal = Request::post('sat_ordinal');
        $collectionId = Request::postInt('collection_id', 0);

        return [
            'token_id'           => $tokenId,
            'inscription_number' => $inscriptionNumber === '' ? null : (int) $inscriptionNumber,
            'sat_ordinal'        => $satOrdinal === '' ? null : (int) $satOrdinal,
            'collection_id'      => $collectionId > 0 ? $collectionId : null,
            'name'               => $name,
            'description'        => Request::post('description') === '' ? null : Request::post('description'),
            'attributes_json'    => $attributesJson,
            'price_minor'        => $price,
            'status'             => $status,
        ];
    }
}

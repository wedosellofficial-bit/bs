<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth;
use App\Controllers\Controller;
use App\Database;
use App\Lib\DeliverableStore;
use App\Lib\Fmt;
use App\Lib\ImageStore;
use App\Lib\Logger;
use App\Lib\Request;
use App\Ordinals;
use App\Product;
use InvalidArgumentException;
use Throwable;

final class AdminProductController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $status = Request::query('status', 'all');
        $search = Request::query('q');

        $clauses = ['1 = 1'];
        $params = [];

        if (in_array($status, ['listed', 'hidden'], true)) {
            $clauses[] = 'p.status = ?';
            $params[] = $status;
        }

        if ($search !== '') {
            $clauses[] = 'p.name LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $this->view('admin/products', [
            'title'  => 'Products',
            'items'  => Database::all(
                'SELECT p.*, c.name AS category_name
                   FROM products p
                   LEFT JOIN product_categories c ON c.id = p.category_id
                  WHERE ' . implode(' AND ', $clauses) . '
                  ORDER BY p.id DESC
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

        $this->view('admin/product-form', [
            'title'      => 'Add product',
            'item'       => null,
            'tags'       => [],
            'categories' => Product::categories(false),
        ], 'layout/admin');
    }

    public function store(): void
    {
        $admin = Auth::requireAdmin();

        try {
            $data = $this->validateInput();
        } catch (InvalidArgumentException $e) {
            $this->back('/admin/products/new', 'error', $e->getMessage());
        }

        $preview = ['image_path' => null, 'preview_path' => null, 'mime' => null, 'width' => null, 'height' => null];
        if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $preview = ImageStore::storeUpload($_FILES['image'], 'product');
            } catch (Throwable $e) {
                $this->back('/admin/products/new', 'error', $e->getMessage());
            }
        }

        $deliverable = ['path' => null, 'mime' => null, 'size' => null, 'original_name' => null];
        if (($_FILES['deliverable']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $deliverable = DeliverableStore::storeUpload($_FILES['deliverable']);
            } catch (Throwable $e) {
                $this->back('/admin/products/new', 'error', $e->getMessage());
            }
        }

        $slug = Product::uniqueSlug($data['name']);

        Database::run(
            'INSERT INTO products
                (slug, name, description, category_id, image_path, preview_path, preview_mime, preview_width, preview_height,
                 deliverable_path, deliverable_mime, deliverable_size, deliverable_original_name,
                 inscription_id, price_minor, member_price_minor, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [
                $slug,
                $data['name'],
                $data['description'],
                $data['category_id'],
                $preview['image_path'],
                $preview['preview_path'],
                $preview['mime'],
                $preview['width'],
                $preview['height'],
                $deliverable['path'],
                $deliverable['mime'],
                $deliverable['size'],
                $deliverable['original_name'],
                $data['inscription_id'],
                $data['price_minor'],
                $data['member_price_minor'],
                $data['status'],
            ]
        );

        $productId = Database::lastInsertId();
        Product::syncTags($productId, $data['tags']);

        Logger::audit('admin.product_created', 'Product added', (int) $admin['id'], 'product', $productId, [
            'name' => $data['name'],
        ]);

        $this->back('/admin/products/' . $productId, 'success', 'Added.');
    }

    public function edit(array $params): void
    {
        Auth::requireAdmin();
        $item = Product::find($this->id($params));

        if ($item === null) {
            $this->notFound('No such product.');
        }

        $this->view('admin/product-form', [
            'title'      => 'Edit ' . (string) $item['name'],
            'item'       => $item,
            'tags'       => Product::tagsFor((int) $item['id']),
            'categories' => Product::categories(false),
            'orderCount' => (int) Database::scalar(
                'SELECT COUNT(*) FROM product_orders WHERE product_id = ?',
                [(int) $item['id']],
                0
            ),
        ], 'layout/admin');
    }

    public function update(array $params): void
    {
        $admin = Auth::requireAdmin();
        $productId = $this->id($params);

        $existing = Product::find($productId);
        if ($existing === null) {
            $this->notFound('No such product.');
        }

        try {
            $data = $this->validateInput();
        } catch (InvalidArgumentException $e) {
            $this->back('/admin/products/' . $productId, 'error', $e->getMessage());
        }

        $imageClauses = '';
        $imageParams = [];
        if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $preview = ImageStore::storeUpload($_FILES['image'], 'product');
            } catch (Throwable $e) {
                $this->back('/admin/products/' . $productId, 'error', $e->getMessage());
            }

            $imageClauses = ', image_path = ?, preview_path = ?, preview_mime = ?, preview_width = ?, preview_height = ?';
            $imageParams = [
                $preview['image_path'],
                $preview['preview_path'],
                $preview['mime'],
                $preview['width'],
                $preview['height'],
            ];

            ImageStore::delete(
                is_string($existing['image_path'] ?? null) ? $existing['image_path'] : null,
                is_string($existing['preview_path'] ?? null) ? $existing['preview_path'] : null,
                'product'
            );
        }

        $deliverableClauses = '';
        $deliverableParams = [];
        if (($_FILES['deliverable']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $deliverable = DeliverableStore::storeUpload($_FILES['deliverable']);
            } catch (Throwable $e) {
                $this->back('/admin/products/' . $productId, 'error', $e->getMessage());
            }

            $deliverableClauses = ', deliverable_path = ?, deliverable_mime = ?, deliverable_size = ?, deliverable_original_name = ?';
            $deliverableParams = [
                $deliverable['path'],
                $deliverable['mime'],
                $deliverable['size'],
                $deliverable['original_name'],
            ];

            DeliverableStore::delete(is_string($existing['deliverable_path'] ?? null) ? $existing['deliverable_path'] : null);
        }

        Database::run(
            "UPDATE products
                SET name = ?, description = ?, category_id = ?, inscription_id = ?,
                    price_minor = ?, member_price_minor = ?, status = ?
                    {$imageClauses}{$deliverableClauses},
                    updated_at = UTC_TIMESTAMP()
              WHERE id = ?",
            array_merge([
                $data['name'],
                $data['description'],
                $data['category_id'],
                $data['inscription_id'],
                $data['price_minor'],
                $data['member_price_minor'],
                $data['status'],
            ], $imageParams, $deliverableParams, [$productId])
        );

        Product::syncTags($productId, $data['tags']);

        Logger::audit('admin.product_updated', 'Product updated', (int) $admin['id'], 'product', $productId);

        $this->back('/admin/products/' . $productId, 'success', 'Saved.');
    }

    public function destroy(array $params): void
    {
        $admin = Auth::requireAdmin();
        $productId = $this->id($params);

        $item = Product::find($productId);
        if ($item === null) {
            $this->notFound('No such product.');
        }

        $order = Database::first('SELECT id FROM product_orders WHERE product_id = ?', [$productId]);
        if ($order !== null) {
            $this->back('/admin/products/' . $productId, 'error',
                'This product has an order against it and cannot be deleted. Set its status to hidden instead.');
        }

        ImageStore::delete(
            is_string($item['image_path'] ?? null) ? $item['image_path'] : null,
            is_string($item['preview_path'] ?? null) ? $item['preview_path'] : null,
            'product'
        );
        DeliverableStore::delete(is_string($item['deliverable_path'] ?? null) ? $item['deliverable_path'] : null);

        Database::run('DELETE FROM products WHERE id = ?', [$productId]);

        Logger::audit('admin.product_deleted', 'Product removed', (int) $admin['id'], 'product', $productId);

        $this->back('/admin/products', 'success', 'Deleted.');
    }

    //-----------------------------------------------------------------
    // Categories and tags
    //-----------------------------------------------------------------

    public function categories(): void
    {
        Auth::requireAdmin();

        $this->view('admin/product-categories', [
            'title'      => 'Categories & tags',
            'categories' => Product::categories(false),
            'tags'       => Product::allTags(),
        ], 'layout/admin');
    }

    public function storeCategory(): void
    {
        $admin = Auth::requireAdmin();

        $name = Request::post('name');
        if ($name === '' || mb_strlen($name) > 120) {
            $this->back('/admin/products/categories', 'error', 'Name is required, 120 characters or fewer.');
        }

        $slug = Product::uniqueSlugForCategory($name);

        Database::run(
            'INSERT INTO product_categories (slug, name, is_members_only, is_visible, created_at)
             VALUES (?, ?, ?, 1, UTC_TIMESTAMP())',
            [$slug, $name, Request::postBool('is_members_only') ? 1 : 0]
        );

        Logger::audit('admin.product_category_created', 'Category added: ' . $name, (int) $admin['id']);

        $this->back('/admin/products/categories', 'success', 'Category added.');
    }

    public function destroyCategory(array $params): void
    {
        $admin = Auth::requireAdmin();
        $id = $this->id($params);

        Database::run('UPDATE products SET category_id = NULL WHERE category_id = ?', [$id]);
        Database::run('DELETE FROM product_categories WHERE id = ?', [$id]);

        Logger::audit('admin.product_category_deleted', 'Category removed', (int) $admin['id']);

        $this->back('/admin/products/categories', 'success', 'Category deleted. Its products are now uncategorised.');
    }

    public function destroyTag(array $params): void
    {
        $admin = Auth::requireAdmin();
        $id = $this->id($params);

        Database::run('DELETE FROM product_tags WHERE id = ?', [$id]);

        Logger::audit('admin.product_tag_deleted', 'Tag removed', (int) $admin['id']);

        $this->back('/admin/products/categories', 'success', 'Tag deleted.');
    }

    /**
     * @return array{
     *   name:string, description:?string, category_id:?int, inscription_id:?string,
     *   price_minor:int, member_price_minor:?int, status:string, tags:list<string>
     * }
     */
    private function validateInput(): array
    {
        $name = Request::post('name');
        if ($name === '' || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Name is required and must be 160 characters or fewer.');
        }

        $price = Fmt::parseMoneyToMinor(Request::post('price'));
        if ($price <= 0) {
            throw new InvalidArgumentException('Price must be greater than zero.');
        }

        $memberPriceRaw = Request::post('member_price');
        $memberPrice = null;
        if ($memberPriceRaw !== '') {
            $memberPrice = Fmt::parseMoneyToMinor($memberPriceRaw);
            if ($memberPrice <= 0 || $memberPrice > $price) {
                throw new InvalidArgumentException('The member price must be greater than zero and no more than the standard price.');
            }
        }

        $status = Request::post('status', 'listed');
        if (!in_array($status, ['listed', 'hidden'], true)) {
            throw new InvalidArgumentException('Unknown status.');
        }

        $inscriptionId = strtolower(Request::post('inscription_id'));
        if ($inscriptionId !== '' && !Ordinals::isValidInscriptionId($inscriptionId)) {
            throw new InvalidArgumentException(
                'Inscription id must be the 64-character reveal txid followed by "i" and the index, e.g. 6fb976ab…2799i0. Leave it empty if there is none.'
            );
        }

        $categoryId = Request::postInt('category_id', 0);

        $tagsRaw = Request::post('tags');
        $tags = $tagsRaw === '' ? [] : array_map('trim', explode(',', $tagsRaw));

        return [
            'name'               => $name,
            'description'        => Request::post('description') === '' ? null : Request::post('description'),
            'category_id'        => $categoryId > 0 ? $categoryId : null,
            'inscription_id'     => $inscriptionId === '' ? null : $inscriptionId,
            'price_minor'        => $price,
            'member_price_minor' => $memberPrice,
            'status'             => $status,
            'tags'               => $tags,
        ];
    }
}

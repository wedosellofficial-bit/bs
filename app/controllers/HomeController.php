<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Nft;

final class HomeController extends Controller
{
    public function index(): void
    {
        $this->view('public/home', [
            'title'       => null,
            'featured'    => $this->featured(),
            'collections' => Nft::collections(),
            'stats'       => $this->stats(),
            'announcement' => $this->currentAnnouncement(),
        ]);
    }

    /**
     * Featured items: the rarest things currently listed. Rarity is a
     * stored, indexed column, so this is an index scan rather than a
     * computation on every home page load.
     */
    private function featured(): array
    {
        return Database::all(
            "SELECT n.id, n.name, n.token_id, n.price_minor, n.status, n.preview_path,
                    n.inscription_number, n.rarity_score, c.name AS collection_name
               FROM nfts n
               LEFT JOIN collections c ON c.id = n.collection_id
              WHERE n.status = 'listed'
              ORDER BY n.rarity_score DESC, n.id DESC
              LIMIT 6"
        );
    }

    private function stats(): array
    {
        $row = Database::first(
            "SELECT
                SUM(CASE WHEN status = 'listed' THEN 1 ELSE 0 END) AS listed,
                SUM(CASE WHEN status IN ('sold','transferred') THEN 1 ELSE 0 END) AS sold,
                CAST(COALESCE(MIN(CASE WHEN status = 'listed' THEN price_minor END), 0) AS SIGNED) AS floor_price
               FROM nfts"
        );

        return [
            'listed'      => (int) ($row['listed'] ?? 0),
            'sold'        => (int) ($row['sold'] ?? 0),
            'floor_price' => (int) ($row['floor_price'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    private function currentAnnouncement(): ?array
    {
        return Database::first(
            'SELECT title, body, level FROM announcements
              WHERE published_at IS NOT NULL
                AND published_at <= UTC_TIMESTAMP()
                AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
              ORDER BY published_at DESC LIMIT 1'
        );
    }
}

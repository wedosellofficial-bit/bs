<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Lib\Config;

/** Static informational pages. */
final class PageController extends Controller
{
    public function about(): void
    {
        $this->view('public/about', ['title' => 'About']);
    }

    /**
     * Reuses the existing admin-managed announcements table (see
     * app/controllers/admin/AdminAnnouncementController.php) rather than
     * adding a second content type - an announcement already has a
     * title, body, publish window and admin author, which is everything
     * a news post needs.
     */
    public function news(): void
    {
        $this->view('public/news', [
            'title' => 'News',
            'posts' => Database::all(
                'SELECT title, body, level, published_at FROM announcements
                  WHERE published_at IS NOT NULL AND published_at <= UTC_TIMESTAMP()
                    AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
                  ORDER BY published_at DESC LIMIT 50'
            ),
        ]);
    }

    public function preorder(): void
    {
        $this->view('public/preorder', ['title' => 'Preorder']);
    }

    public function support(): void
    {
        $this->view('public/support', ['title' => 'Support']);
    }

    public function faq(): void
    {
        $this->view('public/faq', [
            'title' => 'FAQ',
            'requiredConfirmations' => Config::int('payments.required_confs', 2),
        ]);
    }

    public function terms(): void
    {
        $this->view('public/terms', ['title' => 'Terms of sale']);
    }

    public function privacy(): void
    {
        $this->view('public/privacy', ['title' => 'Privacy']);
    }
}

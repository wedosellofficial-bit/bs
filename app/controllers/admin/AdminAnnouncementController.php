<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth;
use App\Controllers\Controller;
use App\Database;
use App\Lib\Logger;
use App\Lib\Request;

final class AdminAnnouncementController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $this->view('admin/announcements', [
            'title' => 'Announcements',
            'items' => Database::all(
                'SELECT a.*, u.email AS author_email
                   FROM announcements a
                   LEFT JOIN users u ON u.id = a.created_by
                  ORDER BY a.id DESC LIMIT 100'
            ),
        ], 'layout/admin');
    }

    public function store(): void
    {
        $admin = Auth::requireAdmin();

        $title = Request::post('title');
        $body = Request::post('body');
        $level = Request::post('level', 'info');

        if ($title === '' || mb_strlen($title) > 160) {
            $this->back('/admin/announcements', 'error', 'Title is required, 160 characters or fewer.');
        }

        if ($body === '') {
            $this->back('/admin/announcements', 'error', 'Write something in the body.');
        }

        if (!in_array($level, ['info', 'warning', 'critical'], true)) {
            $level = 'info';
        }

        $publishNow = Request::postBool('publish');

        // Body is stored and rendered as plain text with paragraph breaks -
        // never as HTML. An announcement appears on every logged-in user's
        // dashboard, so if it accepted markup, one compromised admin
        // account would be stored XSS against the whole customer base.
        Database::run(
            'INSERT INTO announcements (title, body, level, published_at, expires_at, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [
                $title,
                $body,
                $level,
                $publishNow ? gmdate('Y-m-d H:i:s') : null,
                Request::post('expires_at') === '' ? null : Request::post('expires_at'),
                (int) $admin['id'],
            ]
        );

        Logger::audit(
            'admin.announcement_created',
            'Announcement ' . ($publishNow ? 'published' : 'saved as draft') . ': ' . mb_substr($title, 0, 100),
            (int) $admin['id'],
            'announcement',
            Database::lastInsertId()
        );

        $this->back('/admin/announcements', 'success', $publishNow ? 'Published.' : 'Saved as a draft.');
    }

    public function destroy(array $params): void
    {
        $admin = Auth::requireAdmin();
        $id = $this->id($params);

        Database::run('DELETE FROM announcements WHERE id = ?', [$id]);

        Logger::audit('admin.announcement_deleted', 'Announcement removed', (int) $admin['id'], 'announcement', $id);

        $this->back('/admin/announcements', 'success', 'Deleted.');
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth;
use App\Controllers\Controller;
use App\Database;
use App\Lib\Fmt;
use App\Lib\Logger;
use App\Lib\Request;
use App\Orders;
use App\Wallet;
use InvalidArgumentException;

final class AdminUserController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $search = Request::query('q');
        $params = [];
        $where = '1 = 1';

        if ($search !== '') {
            $where = 'u.email LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $users = Database::all(
            "SELECT u.id, u.email, u.display_name, u.role, u.status,
                    u.email_verified_at, u.twofa_confirmed_at, u.created_at, u.last_login_at
               FROM users u
              WHERE {$where}
              ORDER BY u.id DESC
              LIMIT 100",
            $params
        );

        // Balances come from the cache in bulk, with any stale row silently
        // recomputed - see Wallet::cachedBalances().
        $balances = Wallet::cachedBalances(array_map(static fn (array $u): int => (int) $u['id'], $users));

        $this->view('admin/users', [
            'title'    => 'Users',
            'users'    => $users,
            'balances' => $balances,
            'search'   => $search,
        ], 'layout/admin');
    }

    public function show(array $params): void
    {
        Auth::requireAdmin();
        $userId = $this->id($params);

        $user = Database::first('SELECT * FROM users WHERE id = ?', [$userId]);

        if ($user === null) {
            $this->notFound('No such user.');
        }

        $this->view('admin/user-detail', [
            'title'     => (string) $user['email'],
            'user'      => $user,
            'balance'   => Wallet::balance($userId),
            'totals'    => Wallet::totals($userId),
            'statement' => Wallet::statement($userId, 50),
            'orders'    => Orders::forUser($userId, 50),
            'deposits'  => Database::all(
                'SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 25',
                [$userId]
            ),
            'audit'     => Database::all(
                'SELECT event, message, created_at FROM audit_log
                  WHERE actor_user_id = ? OR (subject_type = \'user\' AND subject_id = ?)
                  ORDER BY id DESC LIMIT 30',
                [$userId, $userId]
            ),
        ], 'layout/admin');
    }

    public function updateStatus(array $params): void
    {
        $admin = Auth::requireAdmin();
        $userId = $this->id($params);
        $status = Request::post('status');

        if (!in_array($status, ['active', 'suspended', 'closed'], true)) {
            $this->back('/admin/users/' . $userId, 'error', 'Unknown status.');
        }

        // An admin who suspends themselves is locked out of the tool they
        // would need to undo it.
        if ($userId === (int) $admin['id']) {
            $this->back('/admin/users/' . $userId, 'error', 'You cannot change your own account status.');
        }

        Database::run(
            'UPDATE users SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$status, $userId]
        );

        Logger::audit('admin.user_status', "Account status set to {$status}", (int) $admin['id'], 'user', $userId);

        $this->back('/admin/users/' . $userId, 'success', "Account set to {$status}.");
    }

    /**
     * Manual credit or debit.
     *
     * Written as an `adjustment` ledger entry attributed to the admin who
     * made it, never as a direct balance edit - there is no balance column
     * to edit, which is the point of the append-only design.
     */
    public function manualCredit(array $params): void
    {
        $admin = Auth::requireAdmin();
        $userId = $this->id($params);

        $reason = Request::post('reason');
        $direction = Request::post('direction', 'credit');

        if (mb_strlen($reason) < 5) {
            $this->back('/admin/users/' . $userId, 'error', 'Give a reason - it goes on the customer\'s statement and in the audit log.');
        }

        try {
            $amountMinor = Fmt::parseMoneyToMinor(Request::post('amount'));
        } catch (InvalidArgumentException $e) {
            $this->back('/admin/users/' . $userId, 'error', $e->getMessage());
        }

        if ($amountMinor <= 0) {
            $this->back('/admin/users/' . $userId, 'error', 'Enter an amount greater than zero.');
        }

        $user = Database::first('SELECT id FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            $this->notFound('No such user.');
        }

        // The reference id is the admin's audit trail rather than a
        // business object, because an adjustment has no order or deposit
        // behind it. Unique per adjustment, so the ledger's uniqueness
        // constraint still holds.
        $reference = (int) (microtime(true) * 1000);

        Wallet::withUserLock($userId, static function (int $balance) use (
            $userId,
            $amountMinor,
            $direction,
            $reason,
            $admin,
            $reference
        ): void {
            if ($direction === 'debit') {
                if ($balance < $amountMinor) {
                    throw new InvalidArgumentException(sprintf(
                        'That would take the balance negative. Current balance is %s.',
                        Fmt::money($balance)
                    ));
                }

                Wallet::debit(
                    $userId,
                    $amountMinor,
                    Wallet::TYPE_ADJUSTMENT,
                    'manual',
                    $reference,
                    'Adjustment: ' . mb_substr($reason, 0, 150),
                    (int) $admin['id']
                );

                return;
            }

            Wallet::credit(
                $userId,
                $amountMinor,
                Wallet::TYPE_ADJUSTMENT,
                'manual',
                $reference,
                'Adjustment: ' . mb_substr($reason, 0, 150),
                (int) $admin['id']
            );
        });

        Logger::audit(
            'admin.manual_adjustment',
            sprintf('%s %s: %s', $direction === 'debit' ? 'Debited' : 'Credited', Fmt::money($amountMinor), $reason),
            (int) $admin['id'],
            'user',
            $userId,
            ['amount_minor' => $amountMinor, 'direction' => $direction]
        );

        $this->back(
            '/admin/users/' . $userId,
            'success',
            sprintf('%s %s.', $direction === 'debit' ? 'Debited' : 'Credited', Fmt::money($amountMinor))
        );
    }
}

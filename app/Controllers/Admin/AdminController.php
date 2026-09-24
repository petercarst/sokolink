<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Support\MockCatalog;
use App\Support\MockDashboard;

/**
 * Administrator screens.
 *
 * Every privileged action an admin takes is audited, and several require a
 * mandatory reason (USER_ROLES_AND_PERMISSIONS.md section 5): suspending an
 * account, rejecting a seller, overriding an order transition, regenerating a
 * collection code, approving a refund, editing a setting. The forms on these
 * pages already demand that reason, so Phase 3.12 has nothing to retrofit.
 *
 * The audit log has no update or delete path anywhere in the application.
 */
final class AdminController extends Controller
{
    /** @param array<string,mixed> $data */
    private function page(string $view, array $data): Response
    {
        return $this->view($view, array_merge(['role' => 'admin'], $data), 'dashboard');
    }

    public function dashboard(): Response
    {
        $orders = MockDashboard::orders();

        return $this->page('admin/dashboard', [
            'title'        => 'Platform overview',
            'orders'       => $orders,
            'applications' => array_values(array_filter(
                MockDashboard::adminSet('applications'),
                static fn (array $a): bool => $a['status'] === 'pending_approval'
            )),
            'tickets'   => MockDashboard::tickets('open'),
            'escalated' => MockDashboard::tickets('escalated'),
            'payments'  => MockDashboard::adminSet('payments'),
            'series'    => MockDashboard::salesSeries(),
            'failed'    => array_values(array_filter(
                $orders,
                static fn (array $o): bool => $o['status'] === 'delivery_failed'
            )),
            'audit'     => array_slice(MockDashboard::adminSet('audit'), 0, 5),
        ]);
    }

    // ---- People -------------------------------------------------------------

    public function users(): Response
    {
        $role  = (string) $this->request()->query('role', '');
        $users = MockDashboard::adminSet('users');

        if ($role !== '') {
            $users = array_values(array_filter($users, static fn (array $u): bool => $u['role'] === $role));
        }

        return $this->page('admin/users', [
            'title' => 'Users',
            'users' => $users,
            'role'  => $role,
        ]);
    }

    public function user(string $id): Response
    {
        $user = MockDashboard::user((int) $id);

        if ($user === null) {
            throw new HttpException(404, 'We could not find that user.');
        }

        return $this->page('admin/user', [
            'title' => $user['name'],
            'user'  => $user,
            'audit' => array_values(array_filter(
                MockDashboard::adminSet('audit'),
                static fn (array $a): bool => str_contains($a['entity'], 'users#' . $user['id'])
            )),
        ]);
    }

    public function approvals(): Response
    {
        return $this->page('admin/approvals', [
            'title'        => 'Seller approvals',
            'applications' => MockDashboard::adminSet('applications'),
        ]);
    }

    public function approval(string $id): Response
    {
        $application = MockDashboard::application((int) $id);

        if ($application === null) {
            throw new HttpException(404, 'We could not find that application.');
        }

        return $this->page('admin/approval', [
            'title'       => $application['business_name'],
            'application' => $application,
        ]);
    }

    // ---- Marketplace --------------------------------------------------------

    public function stores(): Response
    {
        return $this->page('admin/stores', [
            'title'  => 'Stores',
            'stores' => MockCatalog::stores(),
        ]);
    }

    public function categories(): Response
    {
        return $this->page('admin/categories', [
            'title'      => 'Categories',
            'categories' => MockCatalog::categories(),
        ]);
    }

    public function products(): Response
    {
        return $this->page('admin/products', [
            'title'    => 'Product moderation',
            'products' => MockCatalog::products(),
        ]);
    }

    // ---- Operations ---------------------------------------------------------

    public function orders(): Response
    {
        $status = (string) $this->request()->query('status', '');
        $orders = MockDashboard::orders();

        if ($status !== '') {
            $orders = array_values(array_filter($orders, static fn (array $o): bool => $o['status'] === $status));
        }

        return $this->page('admin/orders', [
            'title'  => 'Order monitor',
            'orders' => $orders,
            'status' => $status,
        ]);
    }

    public function order(string $ref): Response
    {
        $order = MockDashboard::order($ref);

        if ($order === null) {
            throw new HttpException(404, 'We could not find that order.');
        }

        return $this->page('admin/order', [
            'title' => 'Order ' . $order['ref'],
            'order' => $order,
        ]);
    }

    public function deliveries(): Response
    {
        return $this->page('admin/deliveries', [
            'title' => 'Delivery monitor',
            'tasks' => array_values(array_filter(
                MockDashboard::orders(),
                static fn (array $o): bool => $o['fulfilment'] === 'delivery'
            )),
            'agents' => array_values(array_filter(
                MockDashboard::adminSet('users'),
                static fn (array $u): bool => $u['role'] === 'delivery_agent'
            )),
        ]);
    }

    public function zones(): Response
    {
        return $this->page('admin/zones', [
            'title' => 'Delivery zones',
            'zones' => MockDashboard::adminSet('zones'),
        ]);
    }

    public function payments(): Response
    {
        return $this->page('admin/payments', [
            'title'    => 'Payments and refunds',
            'payments' => MockDashboard::adminSet('payments'),
            'refunds'  => MockDashboard::adminSet('refunds'),
        ]);
    }

    // ---- Care ---------------------------------------------------------------

    public function disputes(): Response
    {
        return $this->page('admin/disputes', [
            'title'    => 'Disputes',
            'disputes' => MockDashboard::tickets('escalated'),
        ]);
    }

    public function notifications(): Response
    {
        return $this->page('admin/notifications', [
            'title'     => 'Notification settings',
            'reminders' => MockDashboard::adminSet('reminders'),
            'log'       => MockDashboard::notificationLog(),
        ]);
    }

    // ---- Platform -----------------------------------------------------------

    public function reports(): Response
    {
        $orders = MockDashboard::orders();

        return $this->page('admin/reports', [
            'title'   => 'Reports',
            'series'  => MockDashboard::salesSeries(),
            'orders'  => $orders,
            'sellers' => [
                ['seller' => 'Mama Lishe Provisions', 'orders' => 412, 'revenue' => '11840000.00', 'rejection_rate' => '1.2%', 'avg_ready_hours' => '4.1'],
                ['seller' => 'Duka Kuu Wholesalers',  'orders' => 298, 'revenue' => '14210000.00', 'rejection_rate' => '4.7%', 'avg_ready_hours' => '9.8'],
                ['seller' => 'Bustani Fresh',         'orders' => 87,  'revenue' => '1090000.00',  'rejection_rate' => '2.3%', 'avg_ready_hours' => '2.2'],
            ],
            'reminders' => MockDashboard::adminSet('reminders'),
        ]);
    }

    public function audit(): Response
    {
        $actor = (string) $this->request()->query('actor', '');
        $rows  = MockDashboard::adminSet('audit');

        if ($actor !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $a): bool => $a['actor_role'] === $actor
            ));
        }

        return $this->page('admin/audit', [
            'title' => 'Audit log',
            'rows'  => $rows,
            'actor' => $actor,
        ]);
    }

    public function settings(): Response
    {
        $settings = MockDashboard::adminSet('settings');
        $grouped  = [];

        foreach ($settings as $setting) {
            $grouped[$setting['group']][] = $setting;
        }

        return $this->page('admin/settings', [
            'title'   => 'System settings',
            'grouped' => $grouped,
        ]);
    }
}

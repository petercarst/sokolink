<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sidebar navigation for each role dashboard.
 *
 * Data-driven so every sidebar is built by one partial: the alternative is five
 * hand-written navigation blocks that drift apart as pages are added.
 *
 * In Phase 3 the items a user actually sees are filtered by PermissionService
 * against the permission on each item. That filtering is a usability courtesy -
 * every route behind these links is independently guarded server-side, because
 * hiding a link is never the access control (NFR-SEC-05).
 */
final class DashboardNav
{
    /** @return array{label:string,badge:string,colour:string} */
    public static function roleMeta(string $role): array
    {
        return match ($role) {
            'customer' => ['label' => 'My account',       'badge' => 'Customer',       'colour' => 'neutral'],
            'seller'   => ['label' => 'Seller dashboard', 'badge' => 'Seller',         'colour' => 'info'],
            'delivery' => ['label' => 'Deliveries',       'badge' => 'Delivery agent', 'colour' => 'info'],
            'support'  => ['label' => 'Support desk',     'badge' => 'Support',        'colour' => 'warn'],
            'admin'    => ['label' => 'Administration',   'badge' => 'Administrator',  'colour' => 'danger'],
            default    => ['label' => 'Dashboard',        'badge' => 'User',           'colour' => 'neutral'],
        };
    }

    /**
     * @return list<array{heading:string,items:list<array{route:string,label:string,icon:string,permission:string,count?:int}>}>
     */
    public static function for(string $role): array
    {
        return match ($role) {
            'customer' => self::customer(),
            'seller'   => self::seller(),
            'delivery' => self::delivery(),
            'support'  => self::support(),
            'admin'    => self::admin(),
            default    => [],
        };
    }

    /** @return list<array<string,mixed>> */
    private static function customer(): array
    {
        return [
            ['heading' => 'Orders', 'items' => [
                ['route' => 'customer.dashboard', 'label' => 'Overview',      'icon' => 'home',    'permission' => 'account.profile.view'],
                ['route' => 'customer.orders',    'label' => 'Active orders', 'icon' => 'package', 'permission' => 'order.view', 'count' => 2],
                ['route' => 'customer.history',   'label' => 'Order history', 'icon' => 'clock',   'permission' => 'order.history.view'],
                ['route' => 'customer.reorder',   'label' => 'Reorder',       'icon' => 'repeat',  'permission' => 'reorder.create'],
                ['route' => 'customer.payments',  'label' => 'Payments',      'icon' => 'shield',  'permission' => 'payment.transaction.view'],
            ]],
            ['heading' => 'Account', 'items' => [
                ['route' => 'customer.profile',   'label' => 'Profile',   'icon' => 'user',   'permission' => 'account.profile.edit'],
                ['route' => 'customer.addresses', 'label' => 'Addresses', 'icon' => 'map-pin', 'permission' => 'account.profile.edit'],
            ]],
            ['heading' => 'Messages', 'items' => [
                ['route' => 'customer.notifications', 'label' => 'Notifications', 'icon' => 'bell', 'permission' => 'notification.inbox.view', 'count' => 3],
                ['route' => 'customer.preferences',   'label' => 'Email preferences', 'icon' => 'mail', 'permission' => 'notification.preferences.manage'],
            ]],
            ['heading' => 'Help', 'items' => [
                ['route' => 'customer.reviews', 'label' => 'My reviews',    'icon' => 'star',  'permission' => 'review.create'],
                ['route' => 'customer.tickets', 'label' => 'Support',       'icon' => 'info',  'permission' => 'ticket.view', 'count' => 1],
            ]],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function seller(): array
    {
        return [
            ['heading' => 'Overview', 'items' => [
                ['route' => 'seller.dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'permission' => 'report.sales.view'],
            ]],
            ['heading' => 'Orders', 'items' => [
                ['route' => 'seller.orders',   'label' => 'Incoming orders', 'icon' => 'package', 'permission' => 'order.view', 'count' => 4],
                ['route' => 'seller.pickup',   'label' => 'Ready to collect', 'icon' => 'qr',     'permission' => 'pickup.code.verify', 'count' => 3],
                ['route' => 'seller.dispatch', 'label' => 'Ready to dispatch', 'icon' => 'truck', 'permission' => 'order.transition.ready_dispatch', 'count' => 2],
            ]],
            ['heading' => 'Catalogue', 'items' => [
                ['route' => 'seller.products',  'label' => 'Products',  'icon' => 'basket', 'permission' => 'product.create'],
                ['route' => 'seller.inventory', 'label' => 'Inventory', 'icon' => 'grain',  'permission' => 'inventory.view'],
            ]],
            ['heading' => 'Store', 'items' => [
                ['route' => 'seller.stores',    'label' => 'Stores',    'icon' => 'store',  'permission' => 'store.edit'],
                ['route' => 'seller.reminders', 'label' => 'Reorder reminders', 'icon' => 'repeat', 'permission' => 'reminder.settings.product'],
                ['route' => 'seller.settings',  'label' => 'Settings',  'icon' => 'shield', 'permission' => 'store.edit'],
            ]],
            ['heading' => 'Performance', 'items' => [
                ['route' => 'seller.reports', 'label' => 'Sales reports', 'icon' => 'leaf', 'permission' => 'report.sales.view'],
                ['route' => 'seller.reviews', 'label' => 'Reviews',       'icon' => 'star', 'permission' => 'review.reply'],
            ]],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function delivery(): array
    {
        return [
            ['heading' => 'Today', 'items' => [
                ['route' => 'delivery.dashboard', 'label' => 'Overview',       'icon' => 'home',   'permission' => 'delivery.task.view'],
                ['route' => 'delivery.tasks',     'label' => 'My deliveries',  'icon' => 'truck',  'permission' => 'delivery.task.view', 'count' => 3],
                ['route' => 'delivery.offers',    'label' => 'Available jobs', 'icon' => 'bell',   'permission' => 'delivery.task.accept', 'count' => 2],
            ]],
            ['heading' => 'Record', 'items' => [
                ['route' => 'delivery.history', 'label' => 'History', 'icon' => 'clock', 'permission' => 'order.history.view'],
            ]],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function support(): array
    {
        return [
            ['heading' => 'Desk', 'items' => [
                ['route' => 'support.dashboard', 'label' => 'Overview',      'icon' => 'home', 'permission' => 'ticket.view'],
                ['route' => 'support.tickets',   'label' => 'Ticket queue',  'icon' => 'mail', 'permission' => 'ticket.view', 'count' => 5],
            ]],
            ['heading' => 'Lookups', 'items' => [
                ['route' => 'support.orders',        'label' => 'Order lookup',        'icon' => 'package', 'permission' => 'order.view'],
                ['route' => 'support.notifications', 'label' => 'Notification monitor', 'icon' => 'bell',   'permission' => 'notification.delivery_log.view'],
            ]],
            ['heading' => 'Escalation', 'items' => [
                ['route' => 'support.escalations', 'label' => 'Escalated', 'icon' => 'alert', 'permission' => 'ticket.escalate', 'count' => 2],
            ]],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function admin(): array
    {
        return [
            ['heading' => 'Overview', 'items' => [
                ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'permission' => 'report.platform.view'],
            ]],
            ['heading' => 'People', 'items' => [
                ['route' => 'admin.users',     'label' => 'Users',            'icon' => 'user',  'permission' => 'user.list'],
                ['route' => 'admin.approvals', 'label' => 'Seller approvals', 'icon' => 'check-circle', 'permission' => 'seller.approve', 'count' => 2],
            ]],
            ['heading' => 'Marketplace', 'items' => [
                ['route' => 'admin.stores',     'label' => 'Stores',     'icon' => 'store',  'permission' => 'store.edit'],
                ['route' => 'admin.categories', 'label' => 'Categories', 'icon' => 'basket', 'permission' => 'category.manage'],
                ['route' => 'admin.products',   'label' => 'Moderation', 'icon' => 'shield', 'permission' => 'product.moderate', 'count' => 1],
            ]],
            ['heading' => 'Operations', 'items' => [
                ['route' => 'admin.orders',     'label' => 'Orders',       'icon' => 'package', 'permission' => 'order.monitor.all'],
                ['route' => 'admin.deliveries', 'label' => 'Deliveries',   'icon' => 'truck',   'permission' => 'delivery.monitor.all'],
                ['route' => 'admin.zones',      'label' => 'Delivery zones', 'icon' => 'map-pin', 'permission' => 'delivery.zone.manage'],
                ['route' => 'admin.payments',   'label' => 'Payments',     'icon' => 'shield',  'permission' => 'payment.transaction.view'],
            ]],
            ['heading' => 'Care', 'items' => [
                ['route' => 'admin.disputes',      'label' => 'Disputes',      'icon' => 'alert', 'permission' => 'dispute.resolve', 'count' => 2],
                ['route' => 'admin.notifications', 'label' => 'Notifications', 'icon' => 'bell',  'permission' => 'notification.settings.platform'],
            ]],
            ['heading' => 'Platform', 'items' => [
                ['route' => 'admin.reports',  'label' => 'Reports',   'icon' => 'leaf',   'permission' => 'report.platform.view'],
                ['route' => 'admin.audit',    'label' => 'Audit log', 'icon' => 'lock',   'permission' => 'audit.view'],
                ['route' => 'admin.settings', 'label' => 'Settings',  'icon' => 'spray',  'permission' => 'settings.edit'],
            ]],
        ];
    }

    /** Every role key, used by the Phase 1 preview switcher. @return list<string> */
    public static function roles(): array
    {
        return ['customer', 'seller', 'delivery', 'support', 'admin'];
    }
}

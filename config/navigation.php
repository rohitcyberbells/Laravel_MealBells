<?php

/*
 * Sidebar menus, one entry per role.
 *
 * Single source of truth: the layout renders whatever the server shares for the
 * signed-in role, so a new page is linked by adding a line here rather than by
 * editing a template. It is shared as a prop so tests can assert, per role, that
 * no other role's links are reachable.
 */
return [
    'items' => [
        'super_admin' => [
            ['label' => 'Companies & Vendors', 'href' => '/super-admin/dashboard', 'icon' => '🏢'],
            ['label' => 'System Health', 'href' => '/super-admin/health', 'icon' => '🩺'],
        ],

        'tiffin_admin' => [
            ['label' => 'Weekly Menu', 'href' => '/tiffin-admin/dashboard', 'icon' => '📋'],
            ['label' => 'Preparation', 'href' => '/tiffin-admin/preparation', 'icon' => '🍳'],
        ],

        'company_admin' => [
            ['label' => 'Dashboard', 'href' => '/company-admin/dashboard', 'icon' => '📊'],
            ['label' => 'Daily Count', 'href' => '/company-admin/daily', 'icon' => '🍱'],
            ['label' => 'Employees', 'href' => '/company-admin/employees', 'icon' => '👥'],
            ['label' => 'Calendar', 'href' => '/company-admin/calendar', 'icon' => '📅'],
            ['label' => 'Adoption Report', 'href' => '/company-admin/reports/adoption', 'icon' => '📈'],
            ['label' => 'Attendance (shadow)', 'href' => '/company-admin/reports/attendance', 'icon' => '🕒'],
            ['label' => 'HRMS', 'href' => '/company-admin/hrms', 'icon' => '🔗'],
            ['label' => 'Settings', 'href' => '/company-admin/settings', 'icon' => '⚙️'],
        ],

        'employee' => [
            ['label' => 'My Meals', 'href' => '/employee/dashboard', 'icon' => '🍽️'],
        ],
    ],
];

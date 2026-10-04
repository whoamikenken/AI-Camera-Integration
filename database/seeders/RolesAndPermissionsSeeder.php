<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure Default Organization Exists
        $org = Organization::firstOrCreate(
            ['code' => 'PINNACLE-HQ'],
            [
                'name' => 'Pinnacle Technologies Inc.',
                'timezone' => 'Asia/Manila',
                'address' => 'Corporate Tower, Bonifacio Global City, Taguig',
                'is_active' => true,
            ]
        );

        // 2. The 7 System Roles
        $rolesData = [
            ['slug' => 'super-admin',  'name' => 'Super Administrator', 'description' => 'Complete unconstrained access to entire system and configuration', 'is_system' => true],
            ['slug' => 'admin',        'name' => 'Administrator',       'description' => 'Full business management across organization, sites, settings, and users', 'is_system' => true],
            ['slug' => 'hr-manager',   'name' => 'HR Manager',          'description' => 'Employee management, shifts, attendance, leave approval, and payroll reports', 'is_system' => true],
            ['slug' => 'security',     'name' => 'Security Officer',    'description' => 'Live camera streams, devices, face audit, stranger snaps, and visitor watchlist', 'is_system' => true],
            ['slug' => 'receptionist', 'name' => 'Receptionist',        'description' => 'Visitor pre-registration, check-in, badges, and checkout', 'is_system' => true],
            ['slug' => 'manager',      'name' => 'Department Manager',  'description' => 'Departmental attendance oversight, leave approval, and regularization approvals', 'is_system' => true],
            ['slug' => 'employee',     'name' => 'Employee',            'description' => 'Self-service attendance records, leave requests, and visitor invitations', 'is_system' => true],
        ];

        $roles = [];
        foreach ($rolesData as $r) {
            $roles[$r['slug']] = Role::updateOrCreate(['slug' => $r['slug']], $r);
        }

        // 3. Permission Catalog
        $permissions = [
            // Attendance Domain
            ['slug' => 'attendance.view',     'name' => 'View Attendance',        'group' => 'attendance', 'description' => 'View attendance roster, dashboard, and calendars'],
            ['slug' => 'attendance.manage',   'name' => 'Manage Attendance',      'group' => 'attendance', 'description' => 'Manual clock-in/out and attendance status override'],
            ['slug' => 'attendance.approve',  'name' => 'Approve Regularization', 'group' => 'attendance', 'description' => 'Approve or reject attendance regularization requests'],
            ['slug' => 'attendance.export',   'name' => 'Export Attendance',      'group' => 'attendance', 'description' => 'Export attendance rosters to CSV/PDF/Excel'],

            // Employee Domain
            ['slug' => 'employees.view',      'name' => 'View Employees',         'group' => 'employees',  'description' => 'View employee directory and profiles'],
            ['slug' => 'employees.create',    'name' => 'Create Employees',       'group' => 'employees',  'description' => 'Add new employee records'],
            ['slug' => 'employees.edit',      'name' => 'Edit Employees',         'group' => 'employees',  'description' => 'Update employee details and assignments'],
            ['slug' => 'employees.delete',    'name' => 'Delete Employees',       'group' => 'employees',  'description' => 'Terminate or soft-delete employees'],
            ['slug' => 'employees.import',    'name' => 'Import Employees',       'group' => 'employees',  'description' => 'Bulk import employees via CSV/Excel'],
            ['slug' => 'employees.export',    'name' => 'Export Employees',       'group' => 'employees',  'description' => 'Export employee list'],
            ['slug' => 'employees.manage',    'name' => 'Manage Employees',       'group' => 'employees',  'description' => 'Full administrative management of employee records and assignments'],

            // Visitor Management Domain
            ['slug' => 'visitors.view',       'name' => 'View Visitors',          'group' => 'visitors',   'description' => 'View active visitors and visit logs'],
            ['slug' => 'visitors.checkin',    'name' => 'Check In Visitors',      'group' => 'visitors',   'description' => 'Perform visitor check-in, take photo, and issue badges'],
            ['slug' => 'visitors.checkout',   'name' => 'Check Out Visitors',     'group' => 'visitors',   'description' => 'Check out visitors and revoke camera biometric access'],
            ['slug' => 'visitors.preregister', 'name' => 'Pre-register Visitors',  'group' => 'visitors',   'description' => 'Invite and pre-register expected visitors'],
            ['slug' => 'visitors.manage',     'name' => 'Manage Visitors',        'group' => 'visitors',   'description' => 'Manage visitor profiles and watchlist'],

            // Devices & Hardware Domain
            ['slug' => 'devices.view',        'name' => 'View Devices',           'group' => 'devices',    'description' => 'View camera list, statuses, and live telemetry'],
            ['slug' => 'devices.manage',      'name' => 'Manage Devices',         'group' => 'devices',    'description' => 'Configure camera settings, reboot, probe, and sync'],
            ['slug' => 'devices.delete',      'name' => 'Delete Devices',         'group' => 'devices',    'description' => 'Remove devices from camera fleet'],
            ['slug' => 'devices.audit',       'name' => 'Audit Devices',          'group' => 'devices',    'description' => 'Perform camera face library audit and backfill'],

            // Biometric Face Library (Personnel)
            ['slug' => 'personnel.view',      'name' => 'View Personnel',         'group' => 'personnel',  'description' => 'View face library records'],
            ['slug' => 'personnel.create',    'name' => 'Create Personnel',       'group' => 'personnel',  'description' => 'Enroll face records and credentials'],
            ['slug' => 'personnel.edit',      'name' => 'Edit Personnel',         'group' => 'personnel',  'description' => 'Update face photos and schedules'],
            ['slug' => 'personnel.delete',    'name' => 'Delete Personnel',       'group' => 'personnel',  'description' => 'Delete personnel and push deletion to cameras'],
            ['slug' => 'personnel.sync',      'name' => 'Sync Personnel',         'group' => 'personnel',  'description' => 'Trigger immediate sync job to devices'],

            // Shifts & Schedules
            ['slug' => 'shifts.view',         'name' => 'View Shifts',            'group' => 'shifts',     'description' => 'View shifts and scheduling calendars'],
            ['slug' => 'shifts.manage',       'name' => 'Manage Shifts',          'group' => 'shifts',     'description' => 'Create, edit shifts, assignments, and holidays'],
            ['slug' => 'schedules.view',      'name' => 'View Schedules',         'group' => 'shifts',     'description' => 'View shift assignments, calendars, and holidays'],
            ['slug' => 'schedules.manage',    'name' => 'Manage Schedules',       'group' => 'shifts',     'description' => 'Assign shifts to employees and departments'],

            // Leaves Management
            ['slug' => 'leaves.view',         'name' => 'View Leaves',            'group' => 'leaves',     'description' => 'View leave requests and quotas'],
            ['slug' => 'leaves.apply',        'name' => 'Apply Leaves',           'group' => 'leaves',     'description' => 'Submit leave requests for self'],
            ['slug' => 'leaves.manage',       'name' => 'Manage Leaves',          'group' => 'leaves',     'description' => 'Configure leave types and policy balances'],
            ['slug' => 'leaves.approve',      'name' => 'Approve Leaves',         'group' => 'leaves',     'description' => 'Approve or reject leave applications'],

            // Reports & Analytics
            ['slug' => 'reports.view',        'name' => 'View Reports',           'group' => 'reports',    'description' => 'Access analytics dashboard and summaries'],
            ['slug' => 'reports.export',      'name' => 'Export Reports',         'group' => 'reports',    'description' => 'Export CSV, XLSX, and PDF reports'],
            ['slug' => 'reports.payroll',     'name' => 'Payroll Export',         'group' => 'reports',    'description' => 'Generate and export payroll-ready data'],

            // Organization Hierarchy
            ['slug' => 'organizations.view',  'name' => 'View Organizations',     'group' => 'organizations', 'description' => 'View departments, locations, and designations'],
            ['slug' => 'organizations.manage', 'name' => 'Manage Organizations',   'group' => 'organizations', 'description' => 'Create and modify organizational hierarchy'],

            // Global Settings & Audit
            ['slug' => 'settings.view',       'name' => 'View Settings',          'group' => 'settings',   'description' => 'View system configuration and audit logs'],
            ['slug' => 'settings.manage',     'name' => 'Manage Settings',        'group' => 'settings',   'description' => 'Update system settings'],

            // User & Role Administration
            ['slug' => 'users.view',          'name' => 'View Users',             'group' => 'users',      'description' => 'View system user accounts'],
            ['slug' => 'users.manage',        'name' => 'Manage Users',           'group' => 'users',      'description' => 'Create, edit, and deactivate user accounts'],
            ['slug' => 'roles.manage',        'name' => 'Manage Roles',           'group' => 'roles',      'description' => 'Manage RBAC roles and permissions'],

            // Self-Service Portal
            ['slug' => 'selfservice.view',    'name' => 'View Self-Service',      'group' => 'selfservice', 'description' => 'Access personal attendance, leaves, and punches'],
        ];

        $allPermModels = [];
        foreach ($permissions as $p) {
            $allPermModels[$p['slug']] = Permission::updateOrCreate(['slug' => $p['slug']], $p);
        }

        // 4. Assign Permissions to Roles
        // super-admin has everything
        $roles['super-admin']->permissions()->sync(array_values(array_map(fn ($p) => $p->id, $allPermModels)));

        // admin has all non-super-admin permissions
        $roles['admin']->permissions()->sync(array_values(array_map(fn ($p) => $p->id, $allPermModels)));

        // hr-manager
        $hrPerms = [
            'attendance.view', 'attendance.manage', 'attendance.approve', 'attendance.export',
            'employees.view', 'employees.create', 'employees.edit', 'employees.delete', 'employees.import', 'employees.export', 'employees.manage',
            'shifts.view', 'shifts.manage', 'schedules.view', 'schedules.manage',
            'leaves.view', 'leaves.manage', 'leaves.approve', 'leaves.apply',
            'reports.view', 'reports.export', 'reports.payroll',
            'organizations.view', 'organizations.manage',
            'personnel.view', 'personnel.sync',
            'selfservice.view',
        ];
        $roles['hr-manager']->syncPermissions($hrPerms);

        // security
        $secPerms = [
            'devices.view', 'devices.manage', 'devices.audit',
            'personnel.view',
            'visitors.view', 'visitors.manage',
            'attendance.view',
        ];
        $roles['security']->syncPermissions($secPerms);

        // receptionist
        $recPerms = [
            'visitors.view', 'visitors.checkin', 'visitors.checkout', 'visitors.preregister', 'visitors.manage',
            'employees.view',
            'selfservice.view',
        ];
        $roles['receptionist']->syncPermissions($recPerms);

        // manager
        $mgrPerms = [
            'employees.view',
            'attendance.view', 'attendance.approve',
            'schedules.view',
            'leaves.view', 'leaves.approve', 'leaves.apply',
            'visitors.preregister',
            'reports.view',
            'selfservice.view',
        ];
        $roles['manager']->syncPermissions($mgrPerms);

        // employee
        $empPerms = [
            'selfservice.view',
            'leaves.apply',
            'visitors.preregister',
        ];
        $roles['employee']->syncPermissions($empPerms);

        // 5. Default Users
        $defaultUsers = [
            [
                'name' => 'System Administrator',
                'email' => 'admin@camera.hub',
                'password' => Hash::make('password'),
                'role' => 'super-admin',
            ],
            [
                'name' => 'HR Manager',
                'email' => 'hr@camera.hub',
                'password' => Hash::make('password'),
                'role' => 'hr-manager',
            ],
            [
                'name' => 'Chief Security Officer',
                'email' => 'security@camera.hub',
                'password' => Hash::make('password'),
                'role' => 'security',
            ],
            [
                'name' => 'Front Desk Receptionist',
                'email' => 'reception@camera.hub',
                'password' => Hash::make('password'),
                'role' => 'receptionist',
            ],
            [
                'name' => 'Operations Manager',
                'email' => 'manager@camera.hub',
                'password' => Hash::make('password'),
                'role' => 'manager',
            ],
            [
                'name' => 'John Employee',
                'email' => 'employee@camera.hub',
                'password' => Hash::make('password'),
                'role' => 'employee',
            ],
            // Frontend quick-fill accounts (for convenience)
            [
                'name' => 'Super Administrator (Pinnacle)',
                'email' => 'admin@pinnacle.test',
                'password' => Hash::make('password'),
                'role' => 'super-admin',
            ],
            [
                'name' => 'HR Manager (Pinnacle)',
                'email' => 'hr@pinnacle.test',
                'password' => Hash::make('password'),
                'role' => 'hr-manager',
            ],
            [
                'name' => 'Security (Pinnacle)',
                'email' => 'security@pinnacle.test',
                'password' => Hash::make('password'),
                'role' => 'security',
            ],
            [
                'name' => 'Receptionist (Pinnacle)',
                'email' => 'receptionist@pinnacle.test',
                'password' => Hash::make('password'),
                'role' => 'receptionist',
            ],
        ];

        foreach ($defaultUsers as $u) {
            $user = User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => $u['password'],
                    'organization_id' => $org->id,
                    'is_active' => true,
                ]
            );

            if (isset($roles[$u['role']])) {
                $user->roles()->syncWithoutDetaching([$roles[$u['role']]->id]);
            }
        }
    }
}

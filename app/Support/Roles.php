<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Role and permission names used with spatie/laravel-permission.
 * The super admin also passes every Gate check (AppServiceProvider).
 */
final class Roles
{
    public const SUPER_ADMIN = 'super-admin';

    public const USER = 'user';

    /** Create, edit, disable and delete user accounts. */
    public const MANAGE_USERS = 'users.manage';

    /** Open another user's workspace to inspect or act on it. */
    public const ACCESS_ALL_WORKSPACES = 'workspaces.access-all';

    public const PERMISSIONS = [self::MANAGE_USERS, self::ACCESS_ALL_WORKSPACES];
}

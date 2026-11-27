<?php

namespace Liberu\Foundation\Organizations\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Record-level access resolver for owner/territory-restricted models.
 */
class AccessContext
{
    protected static ?int $ownerId = null;

    protected static bool $overridden = false;

    public static function restrictTo(?int $userId): void
    {
        static::$ownerId = $userId;
        static::$overridden = true;
    }

    public static function clear(): void
    {
        static::$ownerId = null;
        static::$overridden = false;
    }

    public static function restrictToOwnerId(): ?int
    {
        if (static::$overridden) {
            return static::$ownerId;
        }

        $user = self::resolveUser();
        if (! $user instanceof Model || ! method_exists($user, 'hasRole')) {
            return null;
        }

        foreach (self::restrictedRoles() as $role) {
            if ($user->hasRole($role)) {
                return (int) $user->getKey();
            }
        }

        return null;
    }

    /**
     * @return array<int, int>|null
     */
    public static function restrictedTerritoryIds(): ?array
    {
        $user = self::resolveUser();
        if (! $user instanceof Model || ! method_exists($user, 'hasRole') || ! method_exists($user, 'territories')) {
            return null;
        }

        foreach (self::restrictedRoles() as $role) {
            if ($user->hasRole($role)) {
                return $user->territories()->pluck('territories.id')->all();
            }
        }

        return null;
    }

    public static function shouldMaskFields(): bool
    {
        $user = self::resolveUser();
        if (! $user instanceof Model || ! method_exists($user, 'hasRole')) {
            return false;
        }

        foreach (self::maskedRoles() as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    private static function resolveUser(): ?Model
    {
        $user = null;

        if (config('auth.guards.sanctum') !== null) {
            $user = Auth::guard('sanctum')->user();
        }

        $user ??= Auth::user();

        return $user instanceof Model ? $user : null;
    }

    /** @return list<string> */
    private static function restrictedRoles(): array
    {
        return array_values((array) config('organizations-teams.restricted_roles', ['sales_rep', 'free']));
    }

    /** @return list<string> */
    private static function maskedRoles(): array
    {
        return array_values((array) config('organizations-teams.masked_roles', ['free']));
    }
}

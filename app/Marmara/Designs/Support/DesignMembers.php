<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * The people a design can be credited to: the members of the designs
 * workspace (its members and the account owner, like Workspace::hasMember).
 */
class DesignMembers
{
    /**
     * @return Builder<User>
     */
    public static function query(Workspace $workspace): Builder
    {
        $ownerId = $workspace->account?->owner_id;

        return User::query()->where(fn (Builder $inner) => $inner
            ->whereIn('id', fn ($pivot) => $pivot->select('user_id')->from('user_workspace')->where('workspace_id', $workspace->id))
            ->when($ownerId !== null, fn (Builder $owner) => $owner->orWhere('id', $ownerId)));
    }

    public static function find(Workspace $workspace, ?string $userId): ?User
    {
        return blank($userId) ? null : self::query($workspace)->whereKey($userId)->first();
    }

    /**
     * Member ids by lowercased email, for matching Drive owners.
     *
     * @return array<string, string>
     */
    public static function idsByEmail(Workspace $workspace): array
    {
        $ids = [];

        foreach (self::query($workspace)->get(['id', 'email']) as $user) {
            if (filled($user->email)) {
                $ids[mb_strtolower(trim((string) $user->email))] = (string) $user->id;
            }
        }

        return $ids;
    }
}

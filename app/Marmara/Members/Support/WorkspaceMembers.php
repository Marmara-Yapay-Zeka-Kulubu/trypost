<?php

declare(strict_types=1);

namespace App\Marmara\Members\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The people of a workspace a post can be credited to: its members and the
 * account owner, like Workspace::hasMember().
 */
class WorkspaceMembers
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
        return blank($userId) || ! Str::isUuid($userId) ? null : self::query($workspace)->whereKey($userId)->first();
    }

    /**
     * The members as filter options, by name.
     *
     * @return list<array{id: string, name: string, photo_url: string|null}>
     */
    public static function options(Workspace $workspace): array
    {
        return self::query($workspace)
            ->with('avatarMedia')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'photo_url' => $user->photo_url,
            ])
            ->values()
            ->all();
    }
}

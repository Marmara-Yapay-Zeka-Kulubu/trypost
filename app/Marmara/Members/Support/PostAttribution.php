<?php

declare(strict_types=1);

namespace App\Marmara\Members\Support;

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Hermes / Echo create posts with one TryPost token (the connected user's).
 * When the agent passes on_behalf_of_user_id (the WhatsApp sender, a member
 * of the workspace), the posts are credited to that member: posts.user_id is
 * the member and posts.agent_user_id the connected user. Everything else
 * (the createPost check, whether the post needs approval, who asked for the
 * approval) stays with the connected user: the member only gets the credit.
 */
class PostAttribution
{
    public const string PARAMETER = 'on_behalf_of_user_id';

    public const string DESCRIPTION = 'TryPost user id (UUID) of the person who asked for the post, when known (Echo\'s "[TryPost kullanıcı: <uuid>]" note). Must be a member of this workspace, otherwise the call fails. The post is credited to that member (shown as its creator, "via WhatsApp"); permissions and approval still follow the connected user. Never guess it.';

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [self::PARAMETER => ['sometimes', 'nullable', 'uuid']];
    }

    /**
     * The member named by on_behalf_of_user_id, null when it is not given.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when it is not a member of the workspace
     */
    public static function member(Workspace $workspace, array $input): ?User
    {
        $userId = data_get($input, self::PARAMETER);

        if (blank($userId)) {
            return null;
        }

        $member = WorkspaceMembers::find($workspace, (string) $userId);

        if ($member === null) {
            throw ValidationException::withMessages([self::PARAMETER => 'on_behalf_of_user_id is not a member of this workspace. Leave it out to create the post as the connected user.']);
        }

        return $member;
    }

    /**
     * For CreatePosts::execute()'s $afterCreate: credits the new posts to the
     * member, in the same transaction. Null when there is no member.
     *
     * @return (Closure(Collection<int, Post>): void)|null
     */
    public static function crediting(?User $member, User $agent): ?Closure
    {
        if ($member === null) {
            return null;
        }

        return function (Collection $posts) use ($member, $agent): void {
            Post::query()->whereKey($posts->modelKeys())->update([
                'user_id' => $member->id,
                'agent_user_id' => $agent->id,
            ]);

            $posts->each(function (Post $post) use ($member, $agent): void {
                $post->forceFill(['user_id' => $member->id, 'agent_user_id' => $agent->id])->syncOriginal();
                $post->setRelation('user', $member);
            });
        };
    }
}

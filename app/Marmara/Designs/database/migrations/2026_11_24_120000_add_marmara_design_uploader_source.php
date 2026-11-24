<?php

declare(strict_types=1);

use App\Marmara\Designs\Support\DesignSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md, Designs): who added a design, as a
 * person (`uploader_user_id`, a member of the designs workspace) and how
 * (`source`: site, whatsapp, drive, agent), so the "Ekleyen" filter groups
 * one person's files whatever their source.
 *
 * Existing rows are backfilled with plain queries (never app code):
 * - "Name (WhatsApp)": whatsapp; the person is the member with that exact
 *   name, or the only member whose first name it is (the activity's user is
 *   the API token's owner, not the sender).
 * - another uploader_name (the page's "Yükle"): site; the person is the user
 *   of the "uploaded" activity, else the member with that exact name.
 * - no uploader_name (dropped into Drive): drive; the person is the member
 *   whose email is the Drive owner's (case-insensitive), else nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marmara_designs', function (Blueprint $table): void {
            $table->foreignUuid('uploader_user_id')->nullable()->after('uploader_name')->constrained('users')->nullOnDelete();
            $table->string('source', 16)->default(DesignSource::DEFAULT->value)->after('uploader_user_id');

            $table->index(['workspace_id', 'uploader_user_id'], 'marmara_designs_uploader');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('marmara_designs', function (Blueprint $table): void {
            $table->dropIndex('marmara_designs_uploader');
            $table->dropConstrainedForeignId('uploader_user_id');
            $table->dropColumn('source');
        });
    }

    public function backfill(): void
    {
        $workspaceIds = DB::table('marmara_designs')->distinct()->pluck('workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            $members = $this->members((string) $workspaceId);

            DB::table('marmara_designs')
                ->where('workspace_id', $workspaceId)
                ->select(['id', 'uploader_name', 'owner_email'])
                ->chunkById(200, function ($designs) use ($members): void {
                    $uploadedBy = DB::table('marmara_design_activities')
                        ->whereIn('design_id', $designs->pluck('id'))
                        ->where('type', 'uploaded')
                        ->whereNotNull('user_id')
                        ->orderBy('id')
                        ->get(['design_id', 'user_id'])
                        ->unique('design_id')
                        ->pluck('user_id', 'design_id');

                    foreach ($designs as $design) {
                        [$source, $userId] = $this->resolve($design, $uploadedBy[$design->id] ?? null, $members);

                        DB::table('marmara_designs')->where('id', $design->id)->update(['source' => $source, 'uploader_user_id' => $userId]);
                    }
                });
        }
    }

    /**
     * @param  array{emails: array<string, string>, names: array<string, string|null>, first_names: array<string, string|null>}  $members
     * @return array{0: string, 1: string|null}
     */
    private function resolve(object $design, ?string $activityUserId, array $members): array
    {
        $name = trim((string) ($design->uploader_name ?? ''));

        if ($name === '') {
            $email = mb_strtolower(trim((string) ($design->owner_email ?? '')));

            return [DesignSource::Drive->value, $email === '' ? null : ($members['emails'][$email] ?? null)];
        }

        if (preg_match('/^(.*?)\s*\(WhatsApp\)$/u', $name, $match) === 1) {
            $person = mb_strtolower(trim($match[1]));
            $userId = $members['names'][$person] ?? null;

            if ($userId === null && $person !== '' && ! str_contains($person, ' ')) {
                $userId = $members['first_names'][$person] ?? null;
            }

            return [DesignSource::WhatsApp->value, $userId];
        }

        return [DesignSource::Site->value, $activityUserId ?? ($members['names'][mb_strtolower($name)] ?? null)];
    }

    /**
     * The designs workspace's members (pivot and account owner); a name or
     * first name shared by two members maps to nobody.
     *
     * @return array{emails: array<string, string>, names: array<string, string|null>, first_names: array<string, string|null>}
     */
    private function members(string $workspaceId): array
    {
        $ownerId = DB::table('workspaces')->join('accounts', 'accounts.id', '=', 'workspaces.account_id')
            ->where('workspaces.id', $workspaceId)->value('accounts.owner_id');
        $ids = DB::table('user_workspace')->where('workspace_id', $workspaceId)->pluck('user_id')
            ->push($ownerId)->filter()->unique()->values();

        $members = ['emails' => [], 'names' => [], 'first_names' => []];

        foreach (DB::table('users')->whereIn('id', $ids)->orderBy('id')->get(['id', 'name', 'email']) as $user) {
            $email = mb_strtolower(trim((string) $user->email));
            $name = mb_strtolower(trim((string) $user->name));
            $first = (string) strtok($name, ' ');

            if ($email !== '') {
                $members['emails'][$email] = (string) $user->id;
            }

            if ($name !== '') {
                $members['names'][$name] = array_key_exists($name, $members['names']) ? null : (string) $user->id;
            }

            if ($first !== '') {
                $members['first_names'][$first] = array_key_exists($first, $members['first_names']) ? null : (string) $user->id;
            }
        }

        return $members;
    }
};

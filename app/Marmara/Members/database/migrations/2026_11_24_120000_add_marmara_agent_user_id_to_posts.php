<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md, Members): a post the agent (Hermes,
 * behind Echo's WhatsApp) created on behalf of a member. posts.user_id is the
 * member who asked; agent_user_id is the connected TryPost user whose token
 * made the call. Null for every other post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->foreignUuid('agent_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_user_id');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md): the social inbox. Items (comments
 * and DMs) read from the networks, the replies sent from TryPost, and one
 * resumable sync state per channel, provider and kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marmara_inbox_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('platform', 32);
            $table->string('kind', 16);
            $table->string('external_id', 191);
            $table->string('thread_id', 191)->nullable();
            $table->string('parent_external_id', 191)->nullable();
            $table->string('post_external_id', 191)->nullable();
            $table->foreignUuid('post_id')->nullable()->constrained()->nullOnDelete();
            $table->text('post_excerpt')->nullable();
            $table->string('author_external_id', 191)->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_handle')->nullable();
            $table->text('author_avatar_url')->nullable();
            $table->text('text')->nullable();
            $table->text('permalink')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->boolean('can_reply')->default(false);
            $table->timestamp('reply_window_ends_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'provider', 'external_id'], 'marmara_inbox_items_external_unique');
            $table->index(['workspace_id', 'archived_at', 'read_at', 'received_at'], 'marmara_inbox_items_listing');
            $table->index(['social_account_id', 'thread_id']);
        });

        Schema::create('marmara_inbox_replies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('inbox_item_id')->constrained('marmara_inbox_items')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_id', 100);
            $table->text('text');
            $table->string('status', 16);
            $table->string('external_id', 191)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['inbox_item_id', 'request_id']);
            $table->index(['social_account_id', 'external_id']);
        });

        Schema::create('marmara_inbox_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('kind', 16);
            $table->json('cursor')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('status_detail')->nullable();
            $table->timestamp('blocked_until')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'provider', 'kind'], 'marmara_inbox_sync_states_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marmara_inbox_sync_states');
        Schema::dropIfExists('marmara_inbox_replies');
        Schema::dropIfExists('marmara_inbox_items');
    }
};

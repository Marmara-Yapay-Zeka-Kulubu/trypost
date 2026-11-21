<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md, Designs): the mirror of the shared
 * Drive folder. One row per supported file (a design) and per folder (its
 * path is the collection), the files' Drive revisions, the sync state (the
 * changes.list page token) and the index of the bounded thumbnail cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marmara_design_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('drive_id', 200);
            $table->string('parent_drive_id', 200)->nullable();
            $table->string('name');
            $table->string('path', 1000)->default('');
            $table->boolean('is_root')->default(false);
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'drive_id']);
            $table->index(['workspace_id', 'parent_drive_id']);
        });

        Schema::create('marmara_designs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('drive_file_id', 200);
            $table->string('name');
            $table->string('title');
            $table->string('mime_type', 128);
            $table->string('kind', 16);
            $table->unsignedBigInteger('size')->nullable();
            $table->string('md5', 64)->nullable();
            $table->string('folder_drive_id', 200)->nullable();
            $table->string('folder_path', 1000)->default('');
            $table->timestamp('drive_created_at')->nullable();
            $table->timestamp('drive_modified_at')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_email')->nullable();
            $table->string('modifier_name')->nullable();
            $table->string('modifier_email')->nullable();
            $table->string('uploader_name')->nullable();
            $table->text('web_view_link')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('head_revision_id', 200)->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('status_changed_at')->nullable();
            $table->uuid('family_id')->nullable();
            $table->boolean('family_latest')->default(true);
            $table->unsignedInteger('votes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamp('last_commented_at')->nullable();
            $table->timestamp('revisions_synced_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->string('removed_reason', 32)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'drive_file_id']);
            $table->index(['workspace_id', 'removed_at', 'family_latest', 'drive_created_at'], 'marmara_designs_gallery');
            $table->index(['workspace_id', 'folder_drive_id']);
            $table->index('family_id');
        });

        Schema::create('marmara_design_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('design_id')->constrained('marmara_designs')->cascadeOnDelete();
            $table->string('revision_id', 200);
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('md5', 64)->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->string('modifier_name')->nullable();
            $table->string('modifier_email')->nullable();
            $table->string('original_filename')->nullable();
            $table->boolean('keep_forever')->default(false);
            $table->string('keep_error')->nullable();
            $table->timestamps();

            $table->unique(['design_id', 'revision_id']);
        });

        Schema::create('marmara_design_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('folder_id', 200);
            $table->text('start_page_token')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('status_detail')->nullable();
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'folder_id']);
        });

        Schema::create('marmara_design_thumbnails', function (Blueprint $table): void {
            $table->id();
            $table->string('cache_key', 191)->unique();
            $table->uuid('design_id')->nullable()->index();
            $table->string('path', 500);
            $table->string('mime_type', 64);
            $table->unsignedInteger('bytes');
            $table->timestamp('last_accessed_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marmara_design_thumbnails');
        Schema::dropIfExists('marmara_design_sync_states');
        Schema::dropIfExists('marmara_design_revisions');
        Schema::dropIfExists('marmara_designs');
        Schema::dropIfExists('marmara_design_folders');
    }
};

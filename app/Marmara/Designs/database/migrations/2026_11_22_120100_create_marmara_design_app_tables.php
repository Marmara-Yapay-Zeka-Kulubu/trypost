<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md, Designs): the club's own data on
 * the designs (free tags, comments, one vote per member, the activity log)
 * and the optional Drive upload connection (the lead's drive.file token,
 * stored encrypted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marmara_design_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('design_id')->constrained('marmara_designs')->cascadeOnDelete();
            $table->string('tag', 50);
            $table->timestamps();

            $table->unique(['design_id', 'tag']);
            $table->index('tag');
        });

        Schema::create('marmara_design_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('design_id')->constrained('marmara_designs')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['design_id', 'created_at']);
        });

        Schema::create('marmara_design_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('design_id')->constrained('marmara_designs')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['design_id', 'user_id']);
        });

        Schema::create('marmara_design_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('design_id')->constrained('marmara_designs')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['design_id', 'created_at']);
        });

        Schema::create('marmara_design_drive_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('google_email')->nullable();
            $table->string('google_name')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('folder_granted_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marmara_design_drive_connections');
        Schema::dropIfExists('marmara_design_activities');
        Schema::dropIfExists('marmara_design_votes');
        Schema::dropIfExists('marmara_design_comments');
        Schema::dropIfExists('marmara_design_tags');
    }
};

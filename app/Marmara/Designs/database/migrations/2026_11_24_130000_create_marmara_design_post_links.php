<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md, Designs): which posts a design
 * went out with, which TryPost media files came from a design, and series
 * (several designs of one carousel post shown as one card).
 *
 * - `marmara_design_posts`: one row per design and post (a post per
 *   channel, so a design shared to Instagram and LinkedIn has two rows).
 *   `position` is the design's place in the post's media (carousel order);
 *   `published_at` is set once the post went out and the publication was
 *   recorded on the design (status Kullanıldı, activity line), so retries
 *   and re-publishes never record it twice.
 * - `marmara_design_media`: TryPost media rows made from a design (or that
 *   became one). `media_id` has no foreign key on purpose: a post's copy of
 *   a file points at its source through `meta.copied_from`, and that source
 *   may be gone by the time the copy is looked up.
 * - `marmara_designs.design_set_id` / `set_position`: the series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marmara_design_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('design_id')->constrained('marmara_designs')->cascadeOnDelete();
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->foreignUuid('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['design_id', 'post_id']);
            $table->index(['post_id', 'position']);
            $table->index(['workspace_id', 'published_at']);
        });

        Schema::create('marmara_design_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('design_id')->constrained('marmara_designs')->cascadeOnDelete();
            $table->uuid('media_id')->unique();
            $table->timestamps();
        });

        Schema::table('marmara_designs', function (Blueprint $table): void {
            $table->uuid('design_set_id')->nullable()->after('family_latest');
            $table->unsignedInteger('set_position')->nullable()->after('design_set_id');

            $table->index(['workspace_id', 'design_set_id'], 'marmara_designs_set');
        });
    }

    public function down(): void
    {
        Schema::table('marmara_designs', function (Blueprint $table): void {
            $table->dropIndex('marmara_designs_set');
            $table->dropColumn(['design_set_id', 'set_position']);
        });

        Schema::dropIfExists('marmara_design_media');
        Schema::dropIfExists('marmara_design_posts');
    }
};

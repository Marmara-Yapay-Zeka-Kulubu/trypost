<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md, Designs): which version of the
 * design a TryPost media row holds (`Design::approvalVersion()` when the file
 * was taken: the md5, or the head revision of a Google file), so a post that
 * goes out records the version it carried, not the one Drive has by then.
 * Null for rows made before this column (the current version is used).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marmara_design_media', function (Blueprint $table): void {
            $table->string('version', 200)->nullable()->after('media_id');
        });
    }

    public function down(): void
    {
        Schema::table('marmara_design_media', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};

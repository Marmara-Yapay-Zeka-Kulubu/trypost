<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md, Designs): a title set in TryPost
 * ("Başlığı düzenle", the API, rename-design-tool). `title` stays the one
 * derived from the Drive file name and the sync keeps rewriting it; the
 * shown title is `custom_title ?? title` (Design::displayTitle()). Null
 * means no title of its own: the Drive name shows. The Drive file is never
 * renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marmara_designs', function (Blueprint $table): void {
            $table->string('custom_title', 255)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('marmara_designs', function (Blueprint $table): void {
            $table->dropColumn('custom_title');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md, Designs): an approval belongs to
 * the content that was approved. `approved_version` is the content (md5, or
 * the head revision of a Google file) when Onaylandı / Kullanıldı was set;
 * `status_reason` says why the status last changed by itself
 * (content_changed); `changed_after_use_at` marks a used design whose file
 * changed afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marmara_designs', function (Blueprint $table): void {
            $table->string('approved_version', 200)->nullable()->after('status_changed_at');
            $table->string('status_reason', 32)->nullable()->after('approved_version');
            $table->timestamp('changed_after_use_at')->nullable()->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('marmara_designs', function (Blueprint $table): void {
            $table->dropColumn(['approved_version', 'status_reason', 'changed_after_use_at']);
        });
    }
};

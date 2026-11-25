<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md): the exact JSON body of an
 * attempt's create request, so a retry under the same Idempotency-Key sends
 * the same bytes. Cleared when the key can no longer be sent again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marmara_zernio_publications', function (Blueprint $table): void {
            $table->longText('request_body')->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('marmara_zernio_publications', function (Blueprint $table): void {
            $table->dropColumn('request_body');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md): the Zernio attempt of each TryPost
 * TikTok post, kept outside posts.error_context so posts:retry cannot erase
 * it and a retry never posts twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marmara_zernio_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('post_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('zernio_account_id', 64);
            $table->string('zernio_post_id', 64)->nullable();
            $table->string('idempotency_key', 64);
            $table->timestamp('first_attempt_at');
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_status', 32);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marmara_zernio_publications');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_approval_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained()->cascadeOnDelete();
            $table->string('request_id', 100);
            $table->string('action');
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('content_version', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['post_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_approval_actions');
    }
};

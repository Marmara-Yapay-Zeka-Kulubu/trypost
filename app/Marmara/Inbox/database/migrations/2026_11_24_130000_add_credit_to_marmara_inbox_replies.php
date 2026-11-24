<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marmara AI fork (app/Marmara/README.md, Inbox): who a reply is credited to
 * and how it was made. `user_id` stays the member credited with the reply;
 * `agent_user_id` is the connected TryPost user whose token sent it when an
 * agent (Hermes) replied on behalf of that member, null otherwise. `via` is
 * echo, agent, panel or api (null for replies stored before this column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marmara_inbox_replies', function (Blueprint $table): void {
            $table->foreignUuid('agent_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('via', 16)->nullable()->after('agent_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('marmara_inbox_replies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_user_id');
            $table->dropColumn('via');
        });
    }
};

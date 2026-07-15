<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('authentication_sessions', function (Blueprint $table) {
            $table->string('actor_context')->default('staff')->after('session_id_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('authentication_sessions', function (Blueprint $table) {
            $table->dropColumn('actor_context');
        });
    }
};

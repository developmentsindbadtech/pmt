<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing settings to use microsoft_graph
        DB::table('email_settings')->update([
            'mailer' => 'microsoft_graph',
            'host' => null,
            'port' => null,
            'username' => null,
            'password' => null,
            'encryption' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to log mailer
        DB::table('email_settings')->update([
            'mailer' => 'log',
            'updated_at' => now(),
        ]);
    }
};

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
        // Guard: the create_email_settings_table migration already defines this
        // column, so on a fresh database it would already exist. Only add it for
        // older databases created before that column was part of the table.
        if (! Schema::hasColumn('email_settings', 'send_from_email')) {
            Schema::table('email_settings', function (Blueprint $table) {
                $table->string('send_from_email')->nullable()->after('from_name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_settings', function (Blueprint $table) {
            //
        });
    }
};

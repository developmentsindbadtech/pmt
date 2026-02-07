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
        // Check if column already exists before adding it
        // This migration is redundant if the table was created with send_from_email column
        // but kept for backward compatibility with existing databases
        if (!Schema::hasColumn('email_settings', 'send_from_email')) {
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

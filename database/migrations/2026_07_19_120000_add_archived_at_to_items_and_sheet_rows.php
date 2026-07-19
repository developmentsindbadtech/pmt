<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('dev_tag');
            $table->index(['board_id', 'archived_at']);
        });

        Schema::table('sheet_rows', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('description');
            $table->index(['sheet_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['board_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });

        Schema::table('sheet_rows', function (Blueprint $table) {
            $table->dropIndex(['sheet_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};

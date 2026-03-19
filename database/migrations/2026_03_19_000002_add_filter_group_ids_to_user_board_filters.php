<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_board_filters', function (Blueprint $table) {
            $table->json('filter_group_ids')->nullable()->after('item_type');
        });
    }

    public function down(): void
    {
        Schema::table('user_board_filters', function (Blueprint $table) {
            $table->dropColumn('filter_group_ids');
        });
    }
};

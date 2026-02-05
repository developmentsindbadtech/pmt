<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_board_filters', function (Blueprint $table) {
            $table->boolean('filter_unassigned')->default(false)->after('assignee_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_board_filters', function (Blueprint $table) {
            $table->dropColumn('filter_unassigned');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedInteger('number')->default(1)->after('board_id');
        });

        // Backfill: assign 1, 2, 3... per board (by id order)
        $boards = DB::table('items')->distinct()->pluck('board_id');
        foreach ($boards as $boardId) {
            $ids = DB::table('items')->where('board_id', $boardId)->orderBy('id')->pluck('id');
            foreach ($ids as $index => $id) {
                DB::table('items')->where('id', $id)->update(['number' => $index + 1]);
            }
        }

        Schema::table('items', function (Blueprint $table) {
            $table->unique(['board_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique(['board_id', 'number']);
            $table->dropColumn('number');
        });
    }
};

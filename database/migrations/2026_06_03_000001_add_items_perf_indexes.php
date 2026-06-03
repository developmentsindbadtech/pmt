<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // children_count (withCount('children')) and parent lookups filter by parent_id.
            // On SQLite the foreign key does not create an index automatically.
            $table->index('parent_id');
            // Kanban/List order items by position within a board.
            $table->index(['board_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['board_id', 'position']);
        });
    }
};

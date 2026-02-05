<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Index for board_user pivot table lookups
        Schema::table('board_user', function (Blueprint $table) {
            $table->index('board_id');
            $table->index('user_id');
        });

        // Index for items queries
        Schema::table('items', function (Blueprint $table) {
            $table->index('board_id');
            $table->index('group_id');
            $table->index('assignee_id');
            $table->index(['board_id', 'group_id']);
        });

        // Index for users queries
        Schema::table('users', function (Blueprint $table) {
            $table->index('is_admin');
            $table->index('name'); // For alphabetical sorting
        });

        // Index for groups queries
        Schema::table('groups', function (Blueprint $table) {
            $table->index(['board_id', 'position']);
        });

        // Index for columns queries
        Schema::table('columns', function (Blueprint $table) {
            $table->index(['board_id', 'position']);
        });

        // Index for item_column_values
        Schema::table('item_column_values', function (Blueprint $table) {
            $table->index('item_id');
            $table->index('column_id');
        });
    }

    public function down(): void
    {
        Schema::table('board_user', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
            $table->dropIndex(['group_id']);
            $table->dropIndex(['assignee_id']);
            $table->dropIndex(['board_id', 'group_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_admin']);
            $table->dropIndex(['name']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['board_id', 'position']);
        });

        Schema::table('columns', function (Blueprint $table) {
            $table->dropIndex(['board_id', 'position']);
        });

        Schema::table('item_column_values', function (Blueprint $table) {
            $table->dropIndex(['item_id']);
            $table->dropIndex(['column_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_rows', function (Blueprint $table) {
            // A row can be a subtask of another row in the same sheet.
            // Deleting a parent row removes its subtasks.
            $table->foreignId('parent_id')->nullable()->after('sheet_id')->constrained('sheet_rows')->cascadeOnDelete();
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('sheet_rows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_rows', function (Blueprint $table) {
            $table->text('description')->nullable()->after('values');
        });

        Schema::create('sheet_row_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_row_id')->constrained('sheet_rows')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['sheet_row_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_row_comments');

        Schema::table('sheet_rows', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};

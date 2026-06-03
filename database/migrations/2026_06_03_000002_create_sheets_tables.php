<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('sheet_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained('sheets')->cascadeOnDelete();
            $table->string('name');
            // text, number, date, status, person, checkbox, link
            $table->string('type', 20)->default('text');
            // For status columns: list of option labels. Reserved for future per-type config.
            $table->json('options')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['sheet_id', 'position']);
        });

        Schema::create('sheet_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained('sheets')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // Map of { sheet_column_id: value }.
            $table->json('values')->nullable();
            $table->timestamps();

            $table->index(['sheet_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_rows');
        Schema::dropIfExists('sheet_columns');
        Schema::dropIfExists('sheets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_nav_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            $table->boolean('pinned')->default(false);
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_nav_preferences');
    }
};

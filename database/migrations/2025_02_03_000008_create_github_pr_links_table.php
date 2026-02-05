<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_pr_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pr_number');
            $table->string('repository'); // owner/name
            $table->string('pr_url')->nullable();
            $table->string('status', 30)->nullable(); // open, closed, merged
            $table->timestamps();

            $table->unique(['item_id', 'repository', 'pr_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_pr_links');
    }
};

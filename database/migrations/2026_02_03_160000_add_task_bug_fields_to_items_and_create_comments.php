<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('item_type', 20)->default('task')->after('name'); // task | bug
            $table->text('description')->nullable()->after('item_type');
            $table->text('repro_steps')->nullable()->after('description');
            $table->foreignId('assignee_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        Schema::create('item_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_comments');
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['assignee_id']);
            $table->dropColumn(['item_type', 'description', 'repro_steps', 'assignee_id']);
        });
    }
};

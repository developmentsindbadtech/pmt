<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('priority', 20)->default('medium');
            $table->string('severity', 20)->nullable();
            $table->date('due_at')->nullable();
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedInteger('wip_limit')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['priority', 'severity', 'due_at']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('wip_limit');
        });
    }
};

<?php

use App\Models\Group;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('finished')->default(false)->after('wip_limit');
        });

        Schema::table('sheet_columns', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('options');
        });

        Group::query()->orderBy('id')->each(function (Group $group) {
            if (Group::isDoneName($group->name)) {
                $group->forceFill(['finished' => true])->save();
            }
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('finished');
        });

        Schema::table('sheet_columns', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};

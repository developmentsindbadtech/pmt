<?php

use App\Models\Group;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Group::query()->orderBy('id')->each(function (Group $group) {
            $group->forceFill([
                'finished' => Group::isClosedColumn($group->name),
            ])->save();
        });
    }

    public function down(): void
    {
        Group::query()->orderBy('id')->each(function (Group $group) {
            if (Group::isDoneName($group->name)) {
                $group->forceFill(['finished' => true])->save();
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('github_webhooks');   // FK to github_integrations
        Schema::dropIfExists('github_pr_links');
        Schema::dropIfExists('github_integrations');
    }

    public function down(): void
    {
        // Tables were removed by user request; down() would require re-creating them.
        // Run the original GitHub migrations again if you need to restore.
    }
};

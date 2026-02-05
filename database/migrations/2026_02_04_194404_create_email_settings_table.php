<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mailer')->default('microsoft_graph'); // Only microsoft_graph supported
            $table->string('host')->nullable(); // Keep for backward compatibility but unused
            $table->integer('port')->nullable(); // Keep for backward compatibility but unused
            $table->string('username')->nullable(); // Keep for backward compatibility but unused
            $table->string('password')->nullable(); // Keep for backward compatibility but unused
            $table->string('encryption')->nullable(); // Keep for backward compatibility but unused
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('send_from_email')->nullable(); // Email address to send from via Microsoft Graph
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
        
        // Insert default disabled settings with Microsoft Graph
        DB::table('email_settings')->insert([
            'mailer' => 'microsoft_graph',
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // ssh host alias == Forge server name
            $table->string('forge_id')->nullable();
            $table->string('slug')->default(''); // Forge URL slug (may differ from name)
            $table->string('ip_address')->default('');
            $table->string('region')->default('');
            $table->string('size')->default('');
            $table->boolean('compromised')->default(false);
            $table->boolean('destination_only')->default(false);

            // ssh-probed facts
            $table->boolean('reachable')->default(true);
            $table->string('os')->default('');
            $table->string('os_version')->default('');
            $table->json('php_versions')->nullable();
            $table->string('php_default')->default('');
            $table->string('db_version')->default('');
            $table->string('redis_version')->default('');
            $table->boolean('has_db')->default(false);
            $table->boolean('has_redis')->default(false);
            $table->unsignedSmallInteger('cpus')->default(0);
            $table->string('ram_gb')->default('');
            $table->string('disk_used')->default('');
            $table->string('disk_total')->default('');
            $table->unsignedTinyInteger('disk_pct')->default(0);
            $table->unsignedBigInteger('uptime_s')->default(0);
            $table->timestamp('facts_fetched_at')->nullable();
            $table->timestamp('sites_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};

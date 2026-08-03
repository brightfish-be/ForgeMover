<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // domain, e.g. data.example.com
            $table->string('forge_site_id')->default('');
            $table->string('app_type')->default(''); // probed: laravel 11|wordpress|php|static|empty
            $table->string('php_version')->default('');
            $table->string('repo_url')->default('');
            $table->string('repo_branch')->default('');
            $table->string('repo_provider')->default('');
            $table->string('site_user')->default('');
            $table->boolean('isolated')->default(false);
            $table->boolean('zero_downtime')->default(false);
            $table->string('badge_uuid')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'name']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('target_server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('site_name');
            $table->string('unix_user')->default('forge');
            $table->string('temp_domain')->nullable();
            $table->timestamp('finished_at')->nullable(); // set when cutover confirmed
            $table->timestamps();

            $table->unique(['source_server_id', 'site_name', 'target_server_id']);
        });

        Schema::create('relocation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relocation_id')->constrained()->cascadeOnDelete();
            $table->string('step'); // collect|transfer|restore|verify
            $table->string('state')->default('running'); // running|success|failed
            $table->string('log_file');
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['relocation_id', 'step', 'id']);
        });

        Schema::create('relocation_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relocation_id')->constrained()->cascadeOnDelete();
            $table->string('step_key'); // preflight, forge_site, ..., or "<step>:<check>"
            $table->timestamp('done_at')->nullable();
            $table->string('note')->nullable();

            $table->unique(['relocation_id', 'step_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relocation_checks');
        Schema::dropIfExists('relocation_runs');
        Schema::dropIfExists('relocations');
    }
};

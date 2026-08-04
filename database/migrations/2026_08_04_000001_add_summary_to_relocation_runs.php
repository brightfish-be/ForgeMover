<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relocation_runs', function (Blueprint $table) {
            // one-line result metrics, e.g. "db 124 MB · storage/app 3.4 MB · code 772 kB · 34s"
            $table->string('summary')->nullable()->after('exit_code');
        });
    }

    public function down(): void
    {
        Schema::table('relocation_runs', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};

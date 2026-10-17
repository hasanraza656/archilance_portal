<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved organization charts. Guarded with hasTable() so it is safe to run after
 * database/sql/2026_10_12_create_org_charts.sql was applied by hand.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('org_charts')) {
            return;
        }

        Schema::create('org_charts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->integer('node_count')->default(0);
            $table->integer('change_count')->default(0);
            $table->longText('snapshot');
            $table->longText('changes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_charts');
    }
};

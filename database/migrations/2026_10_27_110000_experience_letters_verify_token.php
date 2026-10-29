<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * experience_letters.verify_token: the unguessable code in the QR on each letter. Scanning it opens a public page that
 * shows the letter, so anyone can check it is genuine. Existing letters are given a code too.
 * Additive and guarded: safe to run twice.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('experience_letters')) {
            return;
        }
        if (!Schema::hasColumn('experience_letters', 'verify_token')) {
            Schema::table('experience_letters', function (Blueprint $t) {
                $t->string('verify_token', 64)->nullable()->unique();
            });
        }
        foreach (DB::table('experience_letters')->whereNull('verify_token')->pluck('id') as $id) {
            DB::table('experience_letters')->where('id', $id)->update(['verify_token' => Str::random(40)]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: additive only.
    }
};

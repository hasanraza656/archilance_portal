<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offboarding: the day the notice was given (the date a resignation was submitted or the termination email was issued).
 * The notice period runs from here to the last working day; leave taken inside it is unpaid.
 *
 * Additive and guarded: safe to run twice, touches no existing data.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('offboardings') && !Schema::hasColumn('offboardings', 'notice_date')) {
            Schema::table('offboardings', function (Blueprint $t) {
                $t->date('notice_date')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: additive only.
    }
};

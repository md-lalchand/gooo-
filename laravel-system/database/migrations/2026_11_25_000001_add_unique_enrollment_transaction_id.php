<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration {
    public function up(): void
    {
        // Never silently choose a winner when historical records already reuse
        // a provider transaction ID. The operator must reconcile those records.
        $duplicates = DB::table('enrollments')
            ->select('trx_id')
            ->whereNotNull('trx_id')
            ->where('trx_id', '<>', '')
            ->groupBy('trx_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->pluck('trx_id')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot add unique enrollment transaction IDs; reconcile duplicate trx_id values first: '
                . implode(', ', $duplicates)
            );
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->unique('trx_id', 'enrollments_trx_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique('enrollments_trx_id_unique');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split the account holder's name: `name` keeps acting as the first
 * (given) name; `last_name` is the new surname column. Nullable because
 * pre-existing accounts signed up with a single name field and there is
 * nothing reliable to backfill from — new sign-ups require it at the
 * validation layer instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('last_name', 160)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('last_name');
        });
    }
};

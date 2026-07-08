<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text accent / dialect hint shown next to a creator's spoken
 * language (e.g. "British", "Brazilian", "Bavarian"). Display-only
 * matching signal — deliberately NOT a structured enum: regional
 * variants explode combinatorially (Scottish English, Egyptian Arabic,
 * ...) and a self-described string carries more nuance for brands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->string('accent', 80)->nullable()->after('primary_language');
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropColumn('accent');
        });
    }
};

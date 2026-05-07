<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| password_reset_tokens — Laravel default, split into its own file
|--------------------------------------------------------------------------
|
| Used by Laravel's password broker (Auth::passwordResetService()).
| Token TTL is enforced by the broker (config/auth.php → passwords.expire),
| not by this schema; the table just stores the latest token per email.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};

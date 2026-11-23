<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // Same unsigned INT key type as the existing SSHhh tables, so they can reference it
            $table->increments('id');
            $table->string('name', 150);
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            // Set when an admin creates the account or resets the password
            $table->boolean('must_change_password')->default(false);
            // Google Authenticator (TOTP), encrypted with the SSHhh vault key
            $table->text('two_factor_secret')->nullable();
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_ip', 60)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('oauth_identities', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32)->comment('OAuth 提供商');
            $table->string('provider_user_id')->comment('OAuth 提供商用户 ID');
            $table->string('email')->nullable()->comment('OAuth 邮箱');
            $table->string('name')->nullable()->comment('OAuth 昵称');
            $table->string('avatar', 1024)->nullable()->comment('OAuth 头像');
            $table->json('raw')->nullable()->comment('OAuth 原始用户信息');
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('oauth_identities');
    }
};

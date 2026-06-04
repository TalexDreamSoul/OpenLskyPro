<?php

use App\Enums\ConfigKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (DB::table('configs')->exists()) {
            DB::table('configs')->updateOrInsert(
                ['name' => ConfigKey::Casdoor],
                [
                    'value' => json_encode(config('convention.app.'.ConfigKey::Casdoor), JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('configs')->where('name', ConfigKey::Casdoor)->delete();
    }
};

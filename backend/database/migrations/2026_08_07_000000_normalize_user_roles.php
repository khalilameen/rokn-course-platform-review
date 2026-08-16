<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The original enum omitted moderator/teacher even though application
        // code used moderator. A varchar prevents production schema drift when
        // a new least-privilege role is introduced.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role VARCHAR(30) NOT NULL DEFAULT 'client'");
        }
    }

    public function down(): void
    {
        // Do not restore the lossy enum: deployed moderator/teacher rows would
        // be truncated. Leaving varchar is the safe rollback behavior.
    }
};

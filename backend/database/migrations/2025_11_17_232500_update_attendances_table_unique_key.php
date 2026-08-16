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
        Schema::table('attendances', function (Blueprint $table) {
           // $table->dropForeign('attendances_tenant_id_foreign');
          //  $table->dropForeign('attendances_appointment_id_foreign');
          //  $table->dropForeign('attendances_group_id_foreign');

         //   $table->dropUnique('unique_daily_attendance');

            // Add the new unique constraint
            $table->unique(
                ['user_id', 'group_id', 'appointment_id', 'attendance_date'],
                'unique_user_daily_attendance'
            );
        });
        }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
};

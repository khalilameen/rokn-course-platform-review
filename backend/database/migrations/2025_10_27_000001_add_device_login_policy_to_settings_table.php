<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDeviceLoginPolicyToSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->enum('device_login_policy', ['multiple_devices', 'single_device', 'single_device_permanent'])
                ->default('multiple_devices')
                ->after('english_translation')
                ->comment('Device login policy: multiple_devices = login from any device, single_device = login from one device at a time, single_device_permanent = lock to first device permanently');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('device_login_policy');
        });
    }
}

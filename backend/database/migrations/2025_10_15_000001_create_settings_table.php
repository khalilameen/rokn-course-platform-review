<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Never erase live integration and business settings during deploy.
        // Existing installations own their table; later additive migrations
        // evolve it. Clean installations create the baseline below.
        if (Schema::hasTable('settings')) {
            return;
        }

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('site_name_ar')->nullable();
            $table->string('site_name_en')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('currency_code')->nullable();
            $table->string('seo_meta_title_ar')->nullable();
            $table->text('seo_meta_description_ar')->nullable();
            $table->string('seo_meta_title_en')->nullable();
            $table->text('seo_meta_description_en')->nullable();
            $table->string('google_maps_key')->nullable();
            $table->text('contact')->nullable();
            $table->boolean('english_translation')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('settings');
    }
}

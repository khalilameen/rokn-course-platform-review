<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('design_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            // Basic Settings
            $table->string('logo_url')->nullable();
            $table->string('icon_url')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();

            // Slogans
            $table->string('slogan_1_ar')->nullable();
            $table->string('slogan_1_en')->nullable();
            $table->string('slogan_2_ar')->nullable();
            $table->string('slogan_2_en')->nullable();
            $table->string('slogan_3_ar')->nullable();
            $table->string('slogan_3_en')->nullable();

            // Colors
            $table->string('color_1')->default('#2563eb');
            $table->string('color_2')->default('#16a34a');
            $table->string('color_3')->default('#f5f7fa');
            $table->string('color_4')->default('#f97316');

            // Background Images
            $table->text('header_background')->nullable();

            // Social Media
            $table->string('facebook_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('tiktok_url')->nullable();
            $table->string('whatsapp_url')->nullable();
            $table->string('telegram_url')->nullable();

            // Support Contacts
            $table->string('technical_contact')->nullable();
            $table->json('center_contacts')->nullable();

            // Policy Content
            $table->longText('policy_content_ar')->nullable();
            $table->longText('policy_content_en')->nullable();

            // Powered By
            $table->json('powered_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('design_settings');
    }
};

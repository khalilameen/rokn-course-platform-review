<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->decimal('price');
            $table->integer('period'); // in monthes
            $table->boolean('is_default')->default(false);
            $table->integer('projects_limit');
            $table->integer('emails_limit');
            $table->text('sms_limit');
            $table->timestamps();
        });

        $now = now();
        DB::table('plans')->insert([
            [
                'name_ar' => 'التجريبية',
                'name_en' => 'trial',
                'price' => 0,
                'period' => 12,
                'is_default' => true,
                'projects_limit' => 3,
                'emails_limit' => 10,
                'sms_limit' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name_ar' => 'النمو',
                'name_en' => 'Growth',
                'price' => 2000,
                'period' => 12,
                'is_default' => false,
                'projects_limit' => 50,
                'emails_limit' => 2000,
                'sms_limit' => 2000,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('plans');
    }
}

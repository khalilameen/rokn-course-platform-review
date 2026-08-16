<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServicesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->float('price')->nullable();;
            $table->string('image')->nullable();;            
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
/*
        Service::create([
            'name_ar' => 'غرفه',
            'name_en' => 'Room'
        ]);
        Service::create([
            'name_ar' => 'شقه',
            'name_en' => 'Apartment'
        ]);
        Service::create([
            'name_ar' => 'دور',
            'name_en' => 'Floor'
        ]);
        Service::create([
            'name_ar' => 'فيلا',
            'name_en' => 'Vila'
        ]);
        Service::create([
            'name_ar' => 'محل تجاري',
            'name_en' => 'Market'
        ]);
        Service::create([
            'name_ar' => 'مقر عمل',
            'name_en' => 'Workplace'
        ]);*/
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('services');
    }
}

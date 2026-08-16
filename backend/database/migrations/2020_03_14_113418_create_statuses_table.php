<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateStatusesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('slug');
            $table->timestamps();
        });
        // Migrations must not depend on application models that can move or be
        // removed later. Seed the immutable lookup rows through the query layer.
        DB::table('statuses')->insert([
            ['name_ar' => 'تم الإرسال', 'name_en' => 'Sent', 'slug' => 'send'],
            ['name_ar' => 'تمت الموافقة', 'name_en' => 'Accepted', 'slug' => 'accepted'],
            ['name_ar' => 'طلب ملغى', 'name_en' => 'Canceled', 'slug' => 'canceled'],
            ['name_ar' => 'تم تجاهل الطلب', 'name_en' => 'Ignored', 'slug' => 'ignored'],
            ['name_ar' => 'تم قبول عرض المندوب', 'name_en' => 'Provider Accepted', 'slug' => 'provider_accepted'],
            ['name_ar' => 'قام المندوب بالاستلام', 'name_en' => 'Provider Received', 'slug' => 'provider_recieved'],
            ['name_ar' => 'قيد الانتظار', 'name_en' => 'Pending', 'slug' => 'pending'],
            ['name_ar' => 'تم الاستلام', 'name_en' => 'Received', 'slug' => 'received'],
            ['name_ar' => 'في الطريق', 'name_en' => 'On Way', 'slug' => 'on_way'],
            ['name_ar' => 'منتهي', 'name_en' => 'Finished', 'slug' => 'finished'],
            ['name_ar' => 'تم الوصول', 'name_en' => 'Delivered', 'slug' => 'delivered'],
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('statuses');
    }
}

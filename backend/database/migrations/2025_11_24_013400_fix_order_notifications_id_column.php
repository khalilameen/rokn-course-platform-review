<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class FixOrderNotificationsIdColumn extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // First, get all records and reassign unique IDs
        $notifications = DB::table('order_notifications')->get();
        
        // Temporarily drop the table and recreate it with proper structure
        Schema::dropIfExists('order_notifications');
        
        Schema::create('order_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->text('message_ar');
            $table->text('message_en');
            $table->timestamps();
        });
        
        // Re-insert the data (Laravel will auto-assign new IDs)
        foreach ($notifications as $notification) {
            DB::table('order_notifications')->insert([
                'user_id' => $notification->user_id,
                'order_id' => $notification->order_id,
                'message_ar' => $notification->message_ar,
                'message_en' => $notification->message_en,
                'created_at' => $notification->created_at,
                'updated_at' => $notification->updated_at,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // This is not reversible, but we can document it
        // The original state was incorrect, so we don't want to revert
    }
}

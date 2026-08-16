<?php

namespace App\Jobs;

use App\Models\User;
use App\Traits\NotifyUsers;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $order;
    public $message;

    public function __construct($order, $message)
    {
        $this->order = $order;
        $this->message = $message;
    }

    /**
     * Execute the job — notify relevant users via push notification.
     */
    public function handle()
    {
        $info = [
            'message' => $this->message
        ];

        // Notify admins or relevant users about the order
        $users = User::where('role', 'Admin')->active()->get();

        foreach ($users as $user) {
            if ($user->device_os == 'ios') {
                NotifyUsers::sendIos([$user->access_token], $info);
            } elseif ($user->device_os == 'android') {
                NotifyUsers::sendAndroid([$user->access_token], $info);
            }
        }
    }
}

<?php

namespace App\Console;

use App\Jobs\PurgeExpiredApiTokens;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\AuditOrderState::class,
        Commands\CleanupBunnyVideos::class,
        Commands\FinalizePendingProjectSubmissions::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('ops:checkpoint-recovery')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();
        if ((bool) config('operations.disaster_recovery_mode', false)) {
            return;
        }
        // Dispatch in a background command so a queue connection outage cannot
        // hold the scheduler loop. The command sends a heartbeat job to each
        // required queue; only a worker on that queue can write its timestamp.
        $schedule->command('ops:dispatch-queue-heartbeats')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer()
            ->runInBackground();
        $schedule->command('ops:monitor-runtime')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer();
        $schedule->command('orders:audit --limit=5000')
            ->hourly()
            ->withoutOverlapping(15)
            ->onOneServer();
        $schedule->command('payments:reconcile-kashier --limit=100')
            ->everyFifteenMinutes()
            ->withoutOverlapping(20)
            ->onOneServer();
        $schedule->command('projects:finalize-pending')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer();
        $schedule->command('ai:release-expired-reservations --limit=500')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer();
        $schedule->command('ai:recover-stalled-feedback --limit=200')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();
        $schedule->command('outbox:maintain --dispatch=500 --prune=0')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer();
        $schedule->command('outbox:maintain --dispatch=0 --prune=5000')
            ->dailyAt('02:20')
            ->withoutOverlapping(30)
            ->onOneServer();
        $schedule->command('data:prune-operational --limit=5000')
            ->dailyAt('02:40')
            ->withoutOverlapping(30)
            ->onOneServer();
        $schedule->command('playback:maintain --limit=5000')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();
        // Recheck active references immediately before retiring any remote
        // object. Abandoned direct uploads are approved automatically.
        $schedule->command('bunny:cleanup-videos --limit=100')
            ->everyFifteenMinutes()
            ->withoutOverlapping(30)
            ->onOneServer();
        $schedule->command('bunny:cleanup-storage --limit=100')
            ->everyFifteenMinutes()
            ->withoutOverlapping(30)
            ->onOneServer();
        // Read-only provider checks plus operational state updates. Findings
        // are quarantined for review; this command never deletes or unpublishes.
        $schedule->command('media:reconcile --limit=1000')
            ->everyThirtyMinutes()
            ->withoutOverlapping(180)
            ->onOneServer();
        $schedule->job(new PurgeExpiredApiTokens())
            ->dailyAt('01:00')
            ->onOneServer();
        $schedule->command('learning:send-nudges')
            ->dailyAt('20:00')
            ->timezone('Africa/Cairo')
            ->withoutOverlapping(60)
            ->onOneServer();
        $schedule->command('notifications:retry-stalled --limit=500')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();
        $schedule->command('notifications:retry-campaigns --limit=50')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();
        $schedule->command('certificates:recover-pending --limit=100')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();
        $schedule->command('operations:prune-rate-limits --days=30')
            ->dailyAt('03:40')
            ->withoutOverlapping(30)
            ->onOneServer();
        $schedule->command('privacy:cleanup-portfolio-media')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();
        $schedule->command('privacy:cleanup-account-files')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

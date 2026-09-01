<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('admin_audit_logs')) {
            return;
        }

        $key = trim((string) config('app.key'));
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is required to pseudonymize audit identifiers.');
        }

        Schema::table('admin_audit_logs', function (Blueprint $table): void {
            // Expand the legacy IPv6-sized field for a SHA-256 fingerprint.
            // Keep user_agent wide during the mixed-release window: an old
            // worker can still write the raw value until workers restart.
            $table->string('ip_address', 64)->nullable()->change();
        });

        DB::table('admin_audit_logs')
            ->select(['id', 'ip_address', 'user_agent'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($key): void {
                foreach ($rows as $row) {
                    DB::table('admin_audit_logs')->where('id', $row->id)->update([
                        'ip_address' => $this->fingerprint($row->ip_address, $key),
                        'user_agent' => $this->fingerprint($row->user_agent, $key),
                    ]);
                }
            });

        if (Schema::hasTable('course_code_usages')) {
            DB::table('course_code_usages')
                ->select(['id', 'ip_address', 'user_agent'])
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($key): void {
                    foreach ($rows as $row) {
                        DB::table('course_code_usages')->where('id', $row->id)->update([
                            'ip_address' => $this->fingerprint($row->ip_address, $key),
                            'user_agent' => $this->fingerprint($row->user_agent, $key),
                        ]);
                    }
                });
        }

        if (Schema::hasTable('feedback_reports')) {
            DB::table('feedback_reports')
                ->whereNotNull('user_agent')
                ->select(['id', 'user_agent'])
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($key): void {
                    foreach ($rows as $row) {
                        DB::table('feedback_reports')->where('id', $row->id)->update([
                            'user_agent' => $this->fingerprint($row->user_agent, $key),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Pseudonymous identifiers cannot and must not be reversed.
    }

    private function fingerprint(mixed $value, string $key): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
            return $value;
        }

        return hash_hmac('sha256', $value, $key);
    }
};

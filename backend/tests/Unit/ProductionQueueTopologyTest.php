<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\DeliverOutboxEvent;
use Dotenv\Dotenv;
use Tests\TestCase;

final class ProductionQueueTopologyTest extends TestCase
{
    public function test_outbox_queue_has_a_dedicated_worker_contract(): void
    {
        self::assertSame('webhooks', config('webhooks.queue'));
        self::assertGreaterThan(
            (new DeliverOutboxEvent(1))->timeout,
            (int) config('queue.connections.redis.retry_after')
        );

        $runbook = file_get_contents(base_path('PRODUCTION_RUNBOOK.md'));
        $environment = file_get_contents(base_path('.env.production.example'));

        self::assertIsString($runbook);
        self::assertStringContainsString('--queue=webhooks', $runbook);
        self::assertStringContainsString('when `webhooks` exceeds two minutes', $runbook);
        self::assertIsString($environment);
        self::assertStringContainsString('WEBHOOK_QUEUE=webhooks', $environment);
        self::assertStringContainsString('REDIS_QUEUE_RETRY_AFTER=120', $environment);
    }

    public function test_production_template_selects_private_shared_course_pdf_storage(): void
    {
        $environment = file_get_contents(base_path('.env.production.example'));
        self::assertIsString($environment);

        $values = Dotenv::parse($environment);

        self::assertSame('s3', $values['COURSE_PDF_DISK'] ?? null);
        self::assertSame('', $values['COURSE_PDF_STORAGE_PATH'] ?? null);
        self::assertSame('false', $values['COURSE_PDF_SHARED_STORAGE'] ?? null);
        self::assertArrayHasKey((string) $values['COURSE_PDF_DISK'], config('filesystems.disks'));
        self::assertNotSame(
            'local',
            config('filesystems.disks.'.(string) $values['COURSE_PDF_DISK'].'.driver')
        );
    }
}

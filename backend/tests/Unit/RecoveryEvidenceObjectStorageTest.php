<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RecoveryEvidenceService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class RecoveryEvidenceObjectStorageTest extends TestCase
{
    public function test_signed_evidence_round_trips_through_a_private_shared_disk(): void
    {
        Storage::fake('s3');
        config([
            'operations.recovery_evidence_disk' => 's3',
            'operations.recovery_evidence_signing_key' => str_repeat('k', 48),
        ]);

        $path = 'recovery/latest-backup.json';
        $payload = [
            'snapshot_at' => now()->utc()->toIso8601String(),
            'marker_generation' => 'generation-1',
            'rpo_seconds' => 30,
        ];

        $evidence = app(RecoveryEvidenceService::class);
        $evidence->write($path, $payload);

        Storage::disk('s3')->assertExists($path);
        self::assertSame($payload, $evidence->readSigned($path));

        $tampered = json_decode((string) Storage::disk('s3')->get($path), true, 64, JSON_THROW_ON_ERROR);
        $tampered['rpo_seconds'] = 999;
        Storage::disk('s3')->put($path, json_encode($tampered, JSON_THROW_ON_ERROR));

        self::assertNull($evidence->readSigned($path));
    }
}

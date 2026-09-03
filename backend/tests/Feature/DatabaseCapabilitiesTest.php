<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\DatabaseCapabilities;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DatabaseCapabilitiesTest extends TestCase
{
    public function test_capabilities_follow_schema_changes_inside_one_test_process(): void
    {
        Schema::dropIfExists('capability_probe');
        self::assertFalse(DatabaseCapabilities::hasTable('capability_probe'));

        Schema::create('capability_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('state');
        });

        self::assertTrue(DatabaseCapabilities::hasTable('capability_probe'));
        self::assertTrue(DatabaseCapabilities::hasColumn('capability_probe', 'state'));

        Schema::drop('capability_probe');
        self::assertFalse(DatabaseCapabilities::hasTable('capability_probe'));
        self::assertFalse(DatabaseCapabilities::hasColumn('capability_probe', 'state'));
    }
}

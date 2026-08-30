<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiEnvelopeConsistencyTest extends TestCase
{
    public function test_api_controllers_never_reintroduce_boolean_http_statuses(): void
    {
        foreach (glob(app_path('Http/Controllers/API/*.php')) ?: [] as $controller) {
            $source = file_get_contents($controller);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                "/['\"]status['\"]\s*=>\s*(?:true|false)\b/",
                $source,
                basename($controller).' must use the numeric HTTP status in the API envelope.'
            );
        }
    }

    public function test_inline_validation_uses_the_standard_api_error_envelope(): void
    {
        Route::post('/api/v1/_contract/validation', function (Request $request): void {
            $request->validate(['name' => ['required', 'string']]);
        });

        $response = $this->postJson('/api/v1/_contract/validation')
            ->assertUnprocessable()
            ->assertJsonPath('status', 422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['status', 'success', 'data', 'message', 'code', 'errors']);

        self::assertIsString($response->json('errors.name.0'));
        self::assertNotSame('', trim((string) $response->json('errors.name.0')));
    }

    public function test_missing_api_route_uses_the_same_error_envelope(): void
    {
        $this->getJson('/api/v1/_contract/does-not-exist')
            ->assertNotFound()
            ->assertExactJson([
                'status' => 404,
                'success' => false,
                'data' => null,
                'message' => 'The requested resource was not found.',
                'code' => 'not_found',
            ]);
    }

    public function test_unexpected_api_failure_is_normalized_without_leaking_exception_details(): void
    {
        Route::get('/api/v1/_contract/server-error', static function (): void {
            throw new \RuntimeException('private implementation detail');
        });

        $this->getJson('/api/v1/_contract/server-error')
            ->assertInternalServerError()
            ->assertExactJson([
                'status' => 500,
                'success' => false,
                'data' => null,
                'message' => 'The service could not complete the request.',
                'code' => 'server_error',
            ]);
    }
}

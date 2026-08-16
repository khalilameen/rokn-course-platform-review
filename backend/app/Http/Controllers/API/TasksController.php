<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\ContactRequest;
use App\Http\Resources\SettingResource;
use App\Models\Contact;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * Legacy public utility endpoints.
 *
 * Google Drive and Sheets experiments previously lived in this controller
 * with a refresh token and credential filename committed to source control.
 * They had no registered route or production consumer, so retaining them
 * would create credential exposure without providing a product capability.
 * Social sign-in remains implemented by the dedicated, server-side services.
 */
class TasksController extends Controller
{
    public function contact(ContactRequest $request): JsonResponse
    {
        Contact::create($request->validated());

        return response()->json([
            'message' => 'تم إرسال رسالتك بنجاح',
        ]);
    }

    public function settings(): SettingResource
    {
        return new SettingResource(Setting::query()->firstOrNew());
    }
}

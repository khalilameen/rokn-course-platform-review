<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PortfolioShareIdentityService
{
    public function ensure(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $slug = trim((string) $locked->portfolio_slug);
            if ($slug === '' || $this->isPredictableLegacySlug($slug, (int) $locked->id)) {
                $slug = $this->freshSlug();
                $locked->forceFill(['portfolio_slug' => $slug])->save();
            }
            $user->setRawAttributes($locked->getAttributes(), true);

            return $slug;
        }, 3);
    }

    public function isPredictableLegacySlug(string $slug, int $userId): bool
    {
        return hash_equals('student-'.$userId, strtolower(trim($slug)));
    }

    private function freshSlug(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $slug = 'rokn-'.Str::lower(Str::random(24));
            if (!User::withTrashed()->where('portfolio_slug', $slug)->exists()) {
                return $slug;
            }
        }

        return 'rokn-'.str_replace('-', '', Str::uuid()->toString());
    }
}

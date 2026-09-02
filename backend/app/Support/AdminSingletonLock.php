<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/** Serializes first-create and update paths for dashboard-owned singleton rows. */
final class AdminSingletonLock
{
    public static function acquire(string ...$keys): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Singleton locks must be acquired inside a database transaction.');
        }

        $keys = array_values(array_unique(array_filter(array_map('trim', $keys))));
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            DB::table('admin_singleton_locks')->insertOrIgnore([
                'lock_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('admin_singleton_locks')
                ->where('lock_key', $key)
                ->lockForUpdate()
                ->first();
        }
    }
}

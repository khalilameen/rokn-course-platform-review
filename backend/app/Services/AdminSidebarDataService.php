<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class AdminSidebarDataService
{
    /** @return array{is_administrator: bool, unread_contacts: int} */
    public function forUser(?User $user): array
    {
        $isAdministrator = strtolower(trim((string) $user?->role)) === 'admin';
        $unreadContacts = 0;

        if ($isAdministrator) {
            try {
                if (Schema::hasTable('contacts')) {
                    $unreadContacts = Contact::query()->where('read', false)->count();
                }
            } catch (\Throwable $exception) {
                // Navigation must remain usable while the contact inbox is
                // temporarily unavailable. The inbox page will expose the
                // actual failure when the administrator opens it.
                report($exception);
            }
        }

        return [
            'is_administrator' => $isAdministrator,
            'unread_contacts' => $unreadContacts,
        ];
    }
}

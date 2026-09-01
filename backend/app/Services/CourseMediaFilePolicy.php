<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class CourseMediaFilePolicy
{
    /** @return array{extension:string,mime:string,sha256:string} */
    public function attachment(UploadedFile $file): array
    {
        $detected = strtolower(trim((string) ($file->getMimeType() ?: '')));
        $clientExtension = strtolower((string) $file->getClientOriginalExtension());
        $types = (array) config('course_attachments.allowed_types', []);
        $extension = null;

        foreach ($types as $candidate => $mimes) {
            if (in_array($detected, (array) $mimes, true)
                && ($clientExtension === '' || $clientExtension === $candidate)) {
                $extension = (string) $candidate;
                break;
            }
        }
        if ($extension === null || preg_match('/^[a-z0-9]{1,8}$/', $extension) !== 1) {
            throw ValidationException::withMessages([
                'file' => 'صيغة المرفق غير مدعومة',
            ]);
        }

        return [
            'extension' => $extension,
            'mime' => $this->canonicalMime($extension),
            'sha256' => $this->hash($file, 'file'),
        ];
    }

    /** @return array{extension:string,mime:string,sha256:string} */
    public function pdf(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $signature = is_resource($handle) ? (string) fread($handle, 5) : '';
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($signature !== '%PDF-' || strtolower((string) $file->getMimeType()) !== 'application/pdf') {
            throw ValidationException::withMessages(['pdf_file' => 'اختر ملف PDF صالحًا']);
        }

        return [
            'extension' => 'pdf',
            'mime' => 'application/pdf',
            'sha256' => $this->hash($file, 'pdf_file'),
        ];
    }

    private function canonicalMime(string $extension): string
    {
        return match ($extension) {
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function hash(UploadedFile $file, string $field): string
    {
        $hash = hash_file('sha256', $file->getRealPath());
        if (!is_string($hash) || strlen($hash) !== 64) {
            throw ValidationException::withMessages([$field => 'تعذر التحقق من الملف']);
        }

        return $hash;
    }
}

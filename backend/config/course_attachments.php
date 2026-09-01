<?php

return [
    'disk' => env('COURSE_ATTACHMENT_DISK', 'module-attachments'),
    // System download managers refresh an expired capability through the API.
    // Keep copied links short-lived and re-check entitlement on every request.
    'signed_url_minutes' => (int) env('COURSE_ATTACHMENT_SIGNED_URL_MINUTES', 30),
    'max_upload_kilobytes' => (int) env('COURSE_ATTACHMENT_MAX_UPLOAD_KILOBYTES', 51200),
    'allowed_types' => [
        'pdf' => ['application/pdf'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'doc' => ['application/msword', 'application/cdfv2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/cdfv2', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/cdfv2', 'application/x-ole-storage'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ],
];

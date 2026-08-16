<?php

return [
    'disk' => env('COURSE_ATTACHMENT_DISK', 'module-attachments'),
    // Long enough to copy the link from a phone to a computer, still temporary.
    'signed_url_minutes' => (int) env('COURSE_ATTACHMENT_SIGNED_URL_MINUTES', 30),
    'max_upload_kilobytes' => (int) env('COURSE_ATTACHMENT_MAX_UPLOAD_KILOBYTES', 51200),
];

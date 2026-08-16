<?php

return [
    // Keep paid course documents on a private disk shared by every API node.
    // S3-compatible storage is shared by design. A mounted filesystem must be
    // explicitly attested as shared so production cannot silently use one
    // server's local storage.
    'disk' => env('COURSE_PDF_DISK', 'course-pdfs'),
    'shared_storage' => (bool) env('COURSE_PDF_SHARED_STORAGE', false),
];

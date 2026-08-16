# Course PDF shared-storage rollout

Course PDFs are paid-content entitlements. They must not live on a public disk
or on one API node's private filesystem.

## Deployment order

1. Configure a shared private disk. For S3-compatible storage, set
   `COURSE_PDF_DISK=s3`. For a filesystem mounted identically on every API
   node, set `COURSE_PDF_DISK=course-pdfs`, set `COURSE_PDF_STORAGE_PATH` to
   that mount, and set `COURSE_PDF_SHARED_STORAGE=true`.
2. Deploy and run database migrations. The nullable `storage_disk` column is
   backward compatible: a null value still reads the legacy `local` disk.
3. Audit without changing data:

   `php artisan course-pdfs:migrate-storage`

4. Back up the legacy storage, then copy, verify, and update records:

   `php artisan course-pdfs:migrate-storage --execute`

5. Re-run the audit and test an entitled PDF from every API node. Only then
   remove sources whose final database reference has moved:

   `php artisan course-pdfs:migrate-storage --execute --delete-source`

6. Run `php artisan rokn:preflight --connectivity`. Production remains blocked
   while a PDF row points to another disk, the configured disk is public or
   local-only, or the shared disk cannot round-trip a probe object.

The migration writes every row to a new UUID object key and compares source
and target sizes before changing metadata. Duplicate legacy paths therefore
become distinct objects, and a shared source is not deleted until its final
database reference has moved.

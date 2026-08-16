<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentController extends Controller
{
    /**
     * Store a new attachment via AJAX.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:' . max(1, (int) config('course_attachments.max_upload_kilobytes', 51200)),
            'attachable_type' => 'required|string|in:course_module,course_section',
            'attachable_id' => 'required|integer',
            'name' => 'nullable|string|max:255',
        ]);

        try {
            // Determine the model class
            $modelClass = $request->attachable_type === 'course_module' 
                ? CourseModule::class 
                : CourseSection::class;
            
            $model = $modelClass::findOrFail($request->attachable_id);

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $fileName = $request->input('name') ?: pathinfo($originalName, PATHINFO_FILENAME);
            $extension = $file->getClientOriginalExtension();
            
            // Generate a unique path
            $storagePath = 'attachments/' . $request->attachable_type . '/' . $model->id;
            $disk = (string) config('course_attachments.disk', 'module-attachments');
            $savedPath = $file->store($storagePath, $disk);

            // Create attachment record
            $attachment = new Attachment([
                'title' => $fileName,
                'file_path' => $savedPath,
                'storage_disk' => $disk,
                'file_type' => $extension,
                'file_size' => $file->getSize(),
            ]);

            $model->attachments()->save($attachment);

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully',
                'attachment' => [
                    'id' => (int) $attachment->id,
                    'title' => (string) $attachment->title,
                    'file_type' => (string) $attachment->file_type,
                    'file_size' => (int) $attachment->file_size,
                ],
                'delete_url' => route('admin.attachments.destroy', $attachment->id)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an attachment via AJAX.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $attachment = Attachment::findOrFail($id);
            
            // Delete file from storage
            if (Storage::disk($attachment->storage_disk)->exists($attachment->file_path)) {
                Storage::disk($attachment->storage_disk)->delete($attachment->file_path);
            }

            // Delete record
            $attachment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Attachment deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Deletion failed: ' . $e->getMessage()
            ], 500);
        }
    }
}

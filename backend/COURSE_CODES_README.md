# Course Code Management System

## Overview
This system allows administrators to create and manage secret codes that provide students with access to paid and closed courses or lessons. The system supports three types of codes:

1. **Course Codes** - Access to entire courses
2. **Lesson Codes** - Access to specific lessons
3. **Multiple Lessons Codes** - Access to selected lessons within a course

## Features

### Admin Features
- ✅ Create multiple codes at once (1-100 codes)
- ✅ Set start and expiry dates
- ✅ Limit number of uses per code
- ✅ Bulk actions (activate, deactivate, delete)
- ✅ Advanced filtering and search
- ✅ Export codes to CSV
- ✅ Track usage history
- ✅ View detailed code information

### Student Features
- ✅ Redeem codes via API
- ✅ Check code validity before using
- ✅ View redeemed codes history
- ✅ Access granted content

## Database Structure

### Tables Created
1. **course_codes** - Stores the generated codes
2. **course_code_usages** - Tracks when and by whom codes are used

### Key Fields
- `code` - Unique 8-character code (auto-generated)
- `type` - course, lesson, or multiple_lessons
- `start_date` - When code becomes active
- `expiry_date` - When code expires
- `max_uses` - Maximum number of times code can be used
- `used_count` - Current usage count
- `is_active` - Whether code is enabled

## Admin Interface

### Access
Navigate to: `Admin Panel > إدارة الأكواد`

### Creating Codes
1. Click "إنشاء كود جديد"
2. Choose code type (course, lesson, multiple lessons)
3. Select target content
4. Set dates and usage limits
5. Choose number of codes to generate
6. Submit

### Managing Codes
- **List View**: View all codes with filtering options
- **Bulk Actions**: Select multiple codes and apply actions
- **Export**: Download codes as CSV file
- **Details**: View usage history and code information

## API Endpoints

### For Students

#### Redeem Code
```
POST /api/course-codes/redeem
{
    "code": "COURSE123"
}
```

#### Check Code Validity
```
POST /api/course-codes/check
{
    "code": "COURSE123"
}
```

#### Get My Redeemed Codes
```
GET /api/course-codes/my-codes
```

### Response Format
```json
{
    "status": 200,
    "success": true,
    "message": "تم تفعيل الكود بنجاح",
    "data": {
        "code": "COURSE123",
        "type": "course",
        "target_content_name": "دورة التأسيس",
        "remaining_uses": 9,
        "course": {
            "id": 1,
            "name": "دورة التأسيس",
            "description": "وصف الدورة"
        }
    }
}
```

## Code Types

### 1. Course Codes
- Provides access to entire courses
- Students can access all lessons in the course
- Requires selecting a course

### 2. Lesson Codes
- Provides access to specific lessons
- Students can access only the selected lesson
- Requires selecting a lesson

### 3. Multiple Lessons Codes
- Provides access to selected lessons within a course
- Students can access only the specified lessons
- Requires selecting a course and specific lessons

## Code Validation Rules

### Validity Checks
- Code exists and is active
- Not expired (expiry_date > now)
- Not started yet (start_date <= now)
- Usage limit not exceeded (used_count < max_uses)
- User hasn't used this code before

### Error Messages
- "الكود غير صحيح" - Code doesn't exist
- "الكود منتهي الصلاحية" - Code has expired
- "الكود لم يبدأ بعد" - Code hasn't started yet
- "الكود معطل" - Code is disabled
- "تم استنفاذ جميع مرات الاستخدام للكود" - Usage limit exceeded
- "لقد استخدمت هذا الكود من قبل" - User already used this code

## Usage Tracking

### What's Tracked
- User ID
- Usage timestamp
- IP address
- User agent (browser info)

### Usage History
- View in admin panel for each code
- Shows who used the code and when
- Includes technical details for security

## Security Features

### Code Generation
- 8-character random codes
- Uppercase letters and numbers
- Unique validation
- No sequential patterns

### Access Control
- One-time use per user per code
- IP tracking for security
- Usage limits enforced
- Expiry dates respected

### Admin Security
- Admin authentication required
- Bulk action confirmation
- Audit trail for all actions

## Testing

### Sample Codes Created
- `COURSE123` - Course access code (valid)
- `LESSON456` - Lesson access code (valid)

### Test Scenarios
1. **Valid Code**: Should redeem successfully
2. **Expired Code**: Should return expiry error
3. **Future Code**: Should return not started error
4. **Used Code**: Should return already used error
5. **Invalid Code**: Should return not found error

## File Structure

```
app/
├── Models/
│   ├── CourseCode.php
│   └── CourseCodeUsage.php
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   └── CourseCodeController.php
│   │   └── API/
│   │       └── CourseCodeController.php
│   └── Requests/
│       └── Admin/
│           └── CourseCodeRequest.php
├── resources/
│   └── views/
│       └── admin/
│           └── course-codes/
│               ├── index.blade.php
│               ├── create.blade.php
│               ├── show.blade.php
│               └── edit.blade.php
└── database/
    ├── migrations/
    │   ├── 2024_01_01_000000_create_course_codes_table.php
    │   └── 2024_01_01_000001_create_course_code_usages_table.php
    └── seeders/
        └── CourseCodeSeeder.php
```

## Routes

### Admin Routes
```
GET    /dashboard/course-codes              # List all codes
GET    /dashboard/course-codes/create       # Create form
POST   /dashboard/course-codes              # Store new codes
GET    /dashboard/course-codes/{id}         # Show code details
GET    /dashboard/course-codes/{id}/edit    # Edit form
PUT    /dashboard/course-codes/{id}         # Update code
DELETE /dashboard/course-codes/{id}         # Delete code
POST   /dashboard/course-codes/bulk-action  # Bulk actions
GET    /dashboard/course-codes/export       # Export to CSV
GET    /dashboard/course-codes/get-lessons  # AJAX lessons
```

### API Routes
```
POST   /api/course-codes/redeem             # Redeem code
POST   /api/course-codes/check              # Check code validity
GET    /api/course-codes/my-codes           # Get user's codes
```

## Future Enhancements

### Potential Features
- Email notifications when codes are used
- QR code generation for codes
- Advanced analytics and reporting
- Code templates for quick creation
- Integration with payment systems
- Mobile app support

### Technical Improvements
- Redis caching for better performance
- Queue jobs for bulk operations
- WebSocket notifications
- Advanced search with Elasticsearch
- API rate limiting
- Enhanced security features

## Support

For technical support or questions about the course code management system, please refer to the Laravel documentation or contact the development team.

---

**Note**: This system is designed to work with the existing multi-tenant architecture and follows Laravel best practices for security, performance, and maintainability.


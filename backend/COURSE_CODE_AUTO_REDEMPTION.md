# Course Code Auto-Redemption During Registration

## Overview

The registration process has been enhanced to automatically redeem course codes when a student enters a password that matches an active course code.

## How It Works

### 1. **Registration Process**
When a student registers with a password that matches an active course code:
- The system automatically detects the match
- Validates the course code (active, not expired, has remaining uses)
- Automatically redeems the course code for the student
- Enrolls the student in the associated course(s)

### 2. **Password Matching**
- The password is compared against course codes in uppercase
- Only active course codes for the same tenant are considered
- The comparison is case-insensitive

### 3. **Automatic Redemption**
When a match is found:
- Creates a `CourseCodeUsage` record
- Increments the course code's `used_count`
- Enrolls the user in the associated course
- Creates appropriate orders and bills
- Sets up course access

## API Response

The registration response now includes course code redemption information:

```json
{
    "status": 200,
    "success": true,
    "message": "تم التسجيل بنجاح! يمكنك الآن تسجيل الدخول والبدء في الدراسة. تم تفعيل الكود بنجاح! يمكنك الآن الوصول للمحتوى المخصص لك.",
    "data": {
        "user": { /* user data */ },
        "is_online": true,
        "requires_approval": false,
        "course_code_redemption": {
            "success": true,
            "message": "تم تفعيل الكود بنجاح! يمكنك الآن الوصول للمحتوى المخصص لك.",
            "code": "BLJB1ESN",
            "type": "course",
            "target_content_name": "Math Course"
        }
    }
}
```

## Course Code Redemption Response

### Success Response
```json
{
    "success": true,
    "message": "تم تفعيل الكود بنجاح! يمكنك الآن الوصول للمحتوى المخصص لك.",
    "code": "BLJB1ESN",
    "type": "course",
    "target_content_name": "Math Course"
}
```

### Failure Response
```json
{
    "success": false,
    "message": "الكود منتهي الصلاحية أو تم استنفاذ مرات الاستخدام",
    "code": "BLJB1ESN"
}
```

### No Match Response
```json
{
    "success": false,
    "message": "",
    "code": null
}
```

## Validation Rules

The system validates course codes before redemption:
- ✅ Code exists and is active
- ✅ Code belongs to the same tenant
- ✅ Code is not expired
- ✅ Code has remaining uses
- ✅ User hasn't already used this code

## Error Handling

- **Invalid/Expired Code**: Returns error message but doesn't prevent registration
- **Already Used**: Returns error message but doesn't prevent registration
- **System Error**: Logs error and continues with normal registration

## Security Considerations

1. **Password Security**: The original password is still hashed and stored securely
2. **Code Validation**: Full validation is performed before redemption
3. **Tenant Isolation**: Only course codes from the same tenant are considered
4. **Error Logging**: All errors are logged for debugging

## Usage Examples

### Student Registration with Course Code Password
```bash
POST /api/register
{
    "first_name": "أحمد",
    "second_name": "محمد",
    "last_name": "علي",
    "phone": "01234567890",
    "parent_phone": "01234567891",
    "type": "student",
    "governorate": "القاهرة",
    "password": "BLJB1ESN",
    "password_confirmation": "BLJB1ESN",
    "is_online": true
}
```

### Response with Auto-Redemption
```json
{
    "status": 200,
    "success": true,
    "message": "تم التسجيل بنجاح! يمكنك الآن تسجيل الدخول والبدء في الدراسة. تم تفعيل الكود بنجاح! يمكنك الآن الوصول للمحتوى المخصص لك.",
    "data": {
        "user": { /* user data */ },
        "course_code_redemption": {
            "success": true,
            "message": "تم تفعيل الكود بنجاح! يمكنك الآن الوصول للمحتوى المخصص لك.",
            "code": "BLJB1ESN",
            "type": "course",
            "target_content_name": "Math Course"
        }
    }
}
```

## Benefits

1. **Seamless Experience**: Students don't need to manually redeem codes after registration
2. **Automatic Enrollment**: Immediate access to course content
3. **Error Prevention**: Reduces manual errors in code redemption
4. **Better UX**: Single-step process for registration and code redemption
5. **Audit Trail**: Full tracking of code usage during registration

## Implementation Details

### Modified Files
- `app/Http/Controllers/API/SignController.php` - Added auto-redemption logic
- `app/Http/Requests/API/RegisterRequest.php` - Added documentation comment

### Key Methods
- `checkAndRedeemCourseCode()` - Main redemption logic
- `register()` - Enhanced to include auto-redemption

### Dependencies
- Uses existing `CourseCode` model methods
- Leverages existing course enrollment system
- Integrates with existing order/billing system


# Dual Login Implementation Test

## Overview
The signin endpoint has been updated to support dual authentication:
1. **Primary**: Phone + Password + Access Token
2. **Fallback**: Phone + Latest Course Code

## Implementation Details

### Updated Logic Flow:
1. User provides phone number and password
2. System first tries to authenticate with password + access token
3. If that fails, system checks if the password matches the user's latest used course code
4. If either method succeeds, user is logged in
5. Login method is tracked and logged for security

### Code Changes Made:

#### 1. Updated SignController.php
- Added `CourseCodeUsage` model import
- Modified login method to check course codes as fallback
- Added logging for course code logins
- Added `login_method` to response data

#### 2. Key Features:
- **Security**: Course code logins are logged with IP and user agent
- **Fallback**: Only checks the latest course code usage
- **Tracking**: Response includes which method was used for login
- **Validation**: Still requires access token for password-based login

### Test Scenarios:

#### Scenario 1: Normal Password Login
```json
POST /api/login
{
    "phone": "01234567890",
    "password": "user_password",
    "access_token": "user_access_token"
}
```
**Expected**: Login with `login_method: "password"`

#### Scenario 2: Course Code Login
```json
POST /api/login
{
    "phone": "01234567890", 
    "password": "ABC12345",
    "access_token": "user_access_token"
}
```
**Expected**: Login with `login_method: "course_code"` (if ABC12345 is the latest course code)

#### Scenario 3: Invalid Credentials
```json
POST /api/login
{
    "phone": "01234567890",
    "password": "wrong_password",
    "access_token": "user_access_token"
}
```
**Expected**: 422 error with message "لا يوجد مستخدم بهذه البيانات"

### Database Requirements:
- `course_code_usages` table must exist
- `course_codes` table must exist
- User must have at least one course code usage record

### Security Considerations:
- Course code logins are logged for audit purposes
- Only the latest course code is checked (not all historical codes)
- Access token is still required for password-based login
- All login attempts are tracked with IP and user agent

### Response Format:
```json
{
    "status": 200,
    "success": true,
    "message": "تم تسجيل الدخول بنجاح",
    "data": {
        "user": { /* UserResource data */ },
        "api_token": "generated_token",
        "login_method": "password" // or "course_code"
    }
}
```

# Student Registration API Documentation

## Overview
This document describes the updated student registration system that supports both online and center-based learning. Students can choose to study online or join a specific center/group.

## New Registration Flow

### 1. Get All Centers
**Endpoint:** `GET /api/centers`

**Description:** Get all available centers with their groups for student registration.

**Response:**
```json
{
  "status": 200,
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "مركز الرياض",
      "address": "شارع الملك فهد، الرياض",
      "groups_count": 5,
      "groups": [
        {
          "id": 1,
          "name": "المجموعة الأولى",
          "grade": {
            "id": 1,
            "name": "الصف الأول الثانوي"
          },
          "amount_due": 500.00,
          "students_count": 15
        }
      ]
    }
  ]
}
```

### 2. Get Groups by Center
**Endpoint:** `GET /api/centers/{center_id}/groups`

**Description:** Get all groups for a specific center.

**Response:**
```json
{
  "status": 200,
  "success": true,
  "data": {
    "center": {
      "id": 1,
      "name": "مركز الرياض",
      "address": "شارع الملك فهد، الرياض"
    },
    "groups": [
      {
        "id": 1,
        "name": "المجموعة الأولى",
        "grade": {
          "id": 1,
          "name": "الصف الأول الثانوي"
        },
        "amount_due": 500.00,
        "students_count": 15,
        "max_students": 30,
        "available_slots": 15
      }
    ]
  }
}
```

### 3. Student Registration
**Endpoint:** `POST /api/register`

**Description:** Register a new student with online/center selection.

**Request Body:**
```json
{
  "first_name": "أحمد",
  "second_name": "محمد",
  "last_name": "علي",
  "phone": "0501234567",
  "parent_phone": "0501234568",
  "parent_job": "مهندس",
  "type": "student",
  "governorate": "الرياض",
  "password": "password123",
  "password_confirmation": "password123",
  "is_online": true,
  "group_id": null
}
```

**Field Descriptions:**
- `is_online` (required, boolean): 
  - `true`: Student will study online (no approval needed)
  - `false`: Student will join a center (requires admin approval)
- `group_id` (required if `is_online` is false): The ID of the selected group

**Response for Online Student:**
```json
{
  "status": 200,
  "success": true,
  "message": "تم التسجيل بنجاح! يمكنك الآن تسجيل الدخول والبدء في الدراسة.",
  "data": {
    "user": {
      "id": 1,
      "name": "أحمد محمد علي",
      "phone": "0501234567",
      "role": "client",
      "is_online": true,
      "active": true
    },
    "is_online": true,
    "requires_approval": false
  }
}
```

**Response for Center Student:**
```json
{
  "status": 200,
  "success": true,
  "message": "تم التسجيل بنجاح! سيتم مراجعة طلبك من قبل الإدارة والموافقة عليه قريباً.",
  "data": {
    "user": {
      "id": 1,
      "name": "أحمد محمد علي",
      "phone": "0501234567",
      "role": "client",
      "is_online": false,
      "active": false,
      "group_id": 1
    },
    "is_online": false,
    "requires_approval": true
  }
}
```

## Frontend Implementation Guide

### Registration Form Flow

1. **Initial Form Fields:**
   - First Name (الاسم الأول)
   - Second Name (اسم الأب)
   - Last Name (اسم العائلة)
   - Phone (رقم الهاتف)
   - Parent Phone (هاتف ولي الأمر)
   - Parent Job (مهنة ولي الأمر)
   - Type (النوع)
   - Governorate (المحافظة)
   - Password (كلمة المرور)
   - Password Confirmation (تأكيد كلمة المرور)

2. **Study Type Selection:**
   - Add a radio button or toggle for "نوع الدراسة"
   - Options: "أونلاين" (Online) or "في المركز" (In Center)

3. **Conditional Fields:**
   - If "في المركز" is selected:
     - Show center selection dropdown
     - Load centers from `/api/centers`
     - When center is selected, load groups from `/api/centers/{center_id}/groups`
     - Show group selection dropdown with available slots

4. **Form Validation:**
   - All basic fields are required
   - `is_online` is required
   - `group_id` is required only if `is_online` is false
   - Show appropriate error messages in Arabic

### UI/UX Recommendations

1. **Study Type Selection:**
   ```
   نوع الدراسة:
   ○ أونلاين - الدراسة عبر الإنترنت
   ● في المركز - الدراسة في أحد مراكزنا
   ```

2. **Center Selection:**
   ```
   اختر المركز:
   [مركز الرياض ▼]
   
   اختر المجموعة:
   [المجموعة الأولى - الصف الأول الثانوي (15/30 متاح) ▼]
   ```

3. **Success Messages:**
   - Online: "تم التسجيل بنجاح! يمكنك الآن تسجيل الدخول والبدء في الدراسة."
   - Center: "تم التسجيل بنجاح! سيتم مراجعة طلبك من قبل الإدارة والموافقة عليه قريباً."

4. **Error Messages:**
   - "يجب تحديد نوع الدراسة (أونلاين أو في المركز)"
   - "يجب اختيار المجموعة عند الدراسة في المركز"
   - "المجموعة المختارة غير موجودة"

### JavaScript Implementation Example

```javascript
// Load centers on page load
async function loadCenters() {
  try {
    const response = await fetch('/api/centers');
    const data = await response.json();
    if (data.success) {
      populateCenterDropdown(data.data);
    }
  } catch (error) {
    console.error('Error loading centers:', error);
  }
}

// Load groups when center is selected
async function loadGroups(centerId) {
  try {
    const response = await fetch(`/api/centers/${centerId}/groups`);
    const data = await response.json();
    if (data.success) {
      populateGroupDropdown(data.data.groups);
    }
  } catch (error) {
    console.error('Error loading groups:', error);
  }
}

// Handle study type change
function onStudyTypeChange(isOnline) {
  const centerGroupSection = document.getElementById('center-group-section');
  centerGroupSection.style.display = isOnline ? 'none' : 'block';
  
  if (isOnline) {
    document.getElementById('group_id').value = '';
  }
}

// Form submission
async function submitRegistration(formData) {
  try {
    const response = await fetch('/api/register', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(formData)
    });
    
    const data = await response.json();
    
    if (data.success) {
      showSuccessMessage(data.message);
      if (data.data.requires_approval) {
        showApprovalPendingMessage();
      } else {
        redirectToLogin();
      }
    } else {
      showErrorMessage(data.message);
    }
  } catch (error) {
    console.error('Registration error:', error);
  }
}
```

## Admin Panel Updates

### User Management
- Add filter for `is_online` status
- Show group information for center students
- Add approval workflow for center students
- Display registration type in user list

### Group Management
- Show available slots vs max students
- Prevent over-enrollment
- Track student capacity

## Database Changes

### Users Table
- Added `is_online` (boolean, nullable) field
- `group_id` already exists for center students

### Groups Table
- Added `max_students` (integer, default 30) field

## Security Considerations

1. **Validation:**
   - Server-side validation for all fields
   - Group existence validation
   - Center existence validation

2. **Access Control:**
   - Center students require admin approval
   - Online students can login immediately
   - Group capacity limits enforced

3. **Data Integrity:**
   - Foreign key constraints
   - Proper error handling
   - Transaction safety

## Testing Checklist

- [ ] Online registration works correctly
- [ ] Center registration requires group selection
- [ ] Center students are inactive until approved
- [ ] Group capacity limits are enforced
- [ ] All validation messages display correctly
- [ ] API endpoints return proper responses
- [ ] Error handling works as expected




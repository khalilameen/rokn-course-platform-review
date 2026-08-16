# 📚 Course API Endpoints Documentation for React Frontend

This document provides a comprehensive guide to all course-related API endpoints for your React application.

## Table of Contents
- [Authentication](#authentication)
- [Course Listing](#1-list-all-courses)
- [Course Details](#2-get-course-details)
- [Student Progress](#3-get-student-progress-in-course)
- [Course Purchase](#4-course-purchase--enrollment)
- [Student Enrollments](#5-student-enrollments)
- [Student Orders](#6-student-course-orders)
- [Student Billing](#7-student-billing-history)
- [Access Check](#8-check-course-access)
- [React Usage Examples](#react-frontend-usage-examples)
- [Error Handling](#error-handling)

---

## Authentication

Most endpoints require authentication using Bearer token:

```javascript
headers: {
  'Authorization': `Bearer ${token}`,
  'Content-Type': 'application/json'
}
```

---

## 1. List All Courses

### **Endpoint:** `GET /api/courses/list`
**Authentication:** Not required  
**Purpose:** Get paginated list of all courses with filtering options

### Query Parameters:
```javascript
{
  grade_id: 1,           // Optional - Filter by grade ID
  course_type: "online", // Optional - Filter by course type
  search: "math",        // Optional - Search in course titles
  per_page: 15,          // Optional - Items per page (default: 15)
  page: 1                // Optional - Page number
}
```

### Response:
```json
{
  "status": 200,
  "success": true,
  "message": "Courses retrieved successfully",
  "data": {
    "courses": [
      {
        "id": 1,
        "name_ar": "الرياضيات المتقدمة",
        "name_en": "Advanced Mathematics",
        "description_ar": "وصف الدورة",
        "description_en": "Course description",
        "image": "course-image.jpg",
        "price": 100.00,
        "currency": "SAR",
        "type": "online",
        "course_type": "online",
        "grade": {
          "id": 1,
          "name_ar": "الصف الأول",
          "name_en": "Grade 1"
        },
        "sections": [
          {
            "id": 1,
            "title": "الدرس الأول",
            "sectionable_type": "App\\Models\\Lesson",
            "order": 1
          }
        ]
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 3,
      "per_page": 15,
      "total": 45,
      "from": 1,
      "to": 15
    }
  }
}
```

---

## 2. Get Course Details

### **Endpoint:** `GET /api/courses/{courseId}/details`
**Authentication:** Required (`auth:api`)  
**Purpose:** Get detailed course information with full content (if enrolled) or basic info (if not enrolled)

### Response (Enrolled User):
```json
{
  "status": 200,
  "success": true,
  "message": "Course details retrieved successfully",
  "data": {
    "id": 1,
    "name_ar": "الرياضيات المتقدمة",
    "name_en": "Advanced Mathematics",
    "description_ar": "وصف الدورة",
    "description_en": "Course description",
    "image": "course-image.jpg",
    "price": 100.00,
    "currency": "SAR",
    "type": "online",
    "enrollment": {
      "id": 123,
      "enrolled_at": "2024-01-15T10:30:00Z",
      "expires_at": "2024-12-31T23:59:59Z",
      "is_active": true,
      "access_granted_at": "2024-01-15T10:30:00Z"
    },
    "sections": [
      {
        "id": 1,
        "title": "الدرس الأول",
        "type": "lesson",
        "order": 1,
        "is_locked": false,
        "content": {
          "id": 1,
          "title": "مقدمة في الرياضيات",
          "description": "وصف الدرس",
          "video_link": "https://example.com/video.mp4",
          "file_link1": "https://example.com/file1.pdf",
          "file_link2": "https://example.com/file2.pdf",
          "priority": 1,
          "is_opened": true
        }
      }
    ]
  }
}
```

### Response (Non-Enrolled User):
```json
{
  "status": 200,
  "success": true,
  "message": "Course details retrieved successfully",
  "data": {
    "id": 1,
    "name_ar": "الرياضيات المتقدمة",
    "name_en": "Advanced Mathematics",
    "description_ar": "وصف الدورة",
    "description_en": "Course description",
    "image": "course-image.jpg",
    "price": 100.00,
    "currency": "SAR",
    "type": "online",
    "enrollment": null,
    "sections": [
      {
        "id": 1,
        "title": "الدرس الأول",
        "type": "lesson",
        "order": 1,
        "is_locked": true,
        "content": null
      }
    ]
  }
}
```

---

## 3. Get Student Progress in Course

### **Endpoint:** `GET /api/courses/{courseId}/progress`
**Authentication:** Required (`auth:api`)  
**Purpose:** Get detailed progress information for a specific course

### Response:
```json
{
  "status": 200,
  "success": true,
  "message": "Course progress retrieved successfully",
  "data": {
    "course": {
      "id": 1,
      "title": "الرياضيات المتقدمة",
      "title_en": "Advanced Mathematics",
      "image": "course-image.jpg"
    },
    "enrollment": {
      "id": 123,
      "enrolled_at": "2024-01-15T10:30:00Z",
      "expires_at": "2024-12-31T23:59:59Z",
      "is_active": true
    },
    "progress": {
      "total_sections": 10,
      "completed_sections": 3,
      "progress_percentage": 30.0,
      "is_completed": false
    },
    "sections": [
      {
        "section_id": 1,
        "title": "الدرس الأول",
        "type": "lesson",
        "order": 1,
        "is_completed": true,
        "is_locked": false,
        "can_access": true
      },
      {
        "section_id": 2,
        "title": "الدرس الثاني",
        "type": "quiz",
        "order": 2,
        "is_completed": true,
        "is_locked": false,
        "can_access": true
      },
      {
        "section_id": 3,
        "title": "الدرس الثالث",
        "type": "lesson",
        "order": 3,
        "is_completed": false,
        "is_locked": false,
        "can_access": true
      },
      {
        "section_id": 4,
        "title": "الدرس الرابع",
        "type": "question",
        "order": 4,
        "is_completed": false,
        "is_locked": true,
        "can_access": false
      }
    ]
  }
}
```

---

## 4. Course Purchase & Enrollment

### **Endpoint:** `POST /api/courses/authorize`
**Authentication:** Required (`auth:api`)  
**Purpose:** Purchase/enroll in a course using different payment methods

### Request Body:
```json
{
  "course_id": 1,
  "payment_method": "online", // "online", "course_code", or "wallet"
  "course_code": "ABC123",    // Required if payment_method is "course_code"
  "coupon_code": "DISCOUNT20", // Optional
  "payment_screenshot": "file", // Required for "online" and "wallet"
  "notes": "Additional notes"   // Optional
}
```

### Response:
```json
{
  "status": 200,
  "success": true,
  "message": "Course authorization request submitted successfully",
  "data": {
    "order": {
      "id": 123,
      "course_id": 1,
      "payment_method": "online",
      "amount": 100.00,
      "final_amount": 80.00,
      "status": "pending"
    },
    "bill": {
      "id": 789,
      "bill_number": "BILL-2024-001",
      "total_amount": 80.00,
      "payment_status": "pending"
    }
  }
}
```

---

## 5. Student Enrollments

### **Endpoint:** `GET /api/courses/my-enrollments`
**Authentication:** Required (`auth:api`)  
**Purpose:** Get list of courses the student is enrolled in

### Response:
```json
{
  "status": 200,
  "success": true,
  "message": "Enrollments retrieved successfully",
  "data": [
    {
      "id": 123,
      "course": {
        "id": 1,
        "title": "الرياضيات المتقدمة",
        "title_en": "Advanced Mathematics",
        "image": "course-image.jpg"
      },
      "enrolled_at": "2024-01-15T10:30:00Z",
      "expires_at": "2024-12-31T23:59:59Z",
      "is_active": true,
      "access_granted_at": "2024-01-15T10:30:00Z"
    }
  ]
}
```

---

## 6. Student Course Orders

### **Endpoint:** `GET /api/courses/my-orders`
**Authentication:** Required (`auth:api`)  
**Purpose:** Get list of course purchase orders

### Query Parameters:
```javascript
{
  per_page: 15, // Optional - Items per page (default: 15)
  page: 1       // Optional - Page number
}
```

### Response:
```json
{
  "status": 200,
  "success": true,
  "message": "Course orders retrieved successfully",
  "data": {
    "orders": [
      {
        "id": 123,
        "course": {
          "id": 1,
          "title": "الرياضيات المتقدمة",
          "title_en": "Advanced Mathematics",
          "image": "course-image.jpg",
          "price": 100.00
        },
        "payment_method": "online",
        "payment_method_display": "Online Payment",
        "amount": 100.00,
        "discount_amount": 20.00,
        "final_amount": 80.00,
        "status": "approved",
        "status_display": "Approved",
        "course_code": null,
        "coupon": {
          "code": "DISCOUNT20",
          "discount_type": "percentage",
          "discount_value": 20
        },
        "payment_screenshot": "screenshots/payment.jpg",
        "notes": "Payment completed",
        "approved_at": "2024-01-15T10:30:00Z",
        "approved_by": {
          "id": 1,
          "name": "Admin User"
        },
        "created_at": "2024-01-15T09:00:00Z",
        "updated_at": "2024-01-15T10:30:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 3,
      "per_page": 15,
      "total": 45,
      "from": 1,
      "to": 15
    }
  }
}
```

---

## 7. Student Billing History

### **Endpoint:** `GET /api/courses/my-bills`
**Authentication:** Required (`auth:api`)  
**Purpose:** Get billing history for course purchases

### Query Parameters:
```javascript
{
  per_page: 15, // Optional - Items per page (default: 15)
  page: 1       // Optional - Page number
}
```

### Response:
```json
{
  "status": 200,
  "success": true,
  "message": "Billing history retrieved successfully",
  "data": {
    "bills": [
      {
        "id": 789,
        "bill_number": "BILL-2024-001",
        "order": {
          "id": 123,
          "course": {
            "id": 1,
            "title": "الرياضيات المتقدمة",
            "title_en": "Advanced Mathematics",
            "image": "course-image.jpg"
          },
          "payment_method": "online",
          "course_code": null,
          "coupon": {
            "code": "DISCOUNT20"
          },
          "status": "approved"
        },
        "amount": 100.00,
        "tax_amount": 0.00,
        "total_amount": 80.00,
        "payment_status": "paid",
        "payment_status_display": "Paid",
        "payment_method": "online",
        "due_date": "2024-01-22T00:00:00Z",
        "paid_at": "2024-01-15T10:30:00Z",
        "notes": "Payment completed successfully",
        "created_at": "2024-01-15T09:00:00Z",
        "updated_at": "2024-01-15T10:30:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 2,
      "per_page": 15,
      "total": 25,
      "from": 1,
      "to": 15
    }
  }
}
```

---

## 8. Check Course Access

### **Endpoint:** `POST /api/courses/check-access`
**Authentication:** Required (`auth:api`)  
**Purpose:** Check if user has access to a specific course

### Request Body:
```json
{
  "course_id": 1
}
```

### Response:
```json
{
  "status": 200,
  "success": true,
  "message": "Access check completed",
  "data": {
    "has_access": true,
    "enrollment": {
      "id": 123,
      "enrolled_at": "2024-01-15T10:30:00Z",
      "expires_at": "2024-12-31T23:59:59Z",
      "access_granted_at": "2024-01-15T10:30:00Z"
    }
  }
}
```

---

## React Frontend Usage Examples

### 1. Fetch All Courses:
```javascript
const fetchCourses = async (filters = {}) => {
  const params = new URLSearchParams(filters);
  const response = await fetch(`/api/courses/list?${params}`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  return response.json();
};

// Usage
const courses = await fetchCourses({
  grade_id: 1,
  course_type: 'online',
  search: 'math',
  per_page: 20
});
```

### 2. Get Course Details:
```javascript
const getCourseDetails = async (courseId) => {
  const response = await fetch(`/api/courses/${courseId}/details`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  return response.json();
};

// Usage
const courseDetails = await getCourseDetails(1);
```

### 3. Get Student Progress:
```javascript
const getCourseProgress = async (courseId) => {
  const response = await fetch(`/api/courses/${courseId}/progress`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  return response.json();
};

// Usage
const progress = await getCourseProgress(1);
console.log(`Progress: ${progress.data.progress.progress_percentage}%`);
```

### 4. Purchase Course:
```javascript
const purchaseCourse = async (courseData) => {
  const formData = new FormData();
  formData.append('course_id', courseData.courseId);
  formData.append('payment_method', courseData.paymentMethod);
  
  if (courseData.paymentScreenshot) {
    formData.append('payment_screenshot', courseData.paymentScreenshot);
  }
  
  if (courseData.courseCode) {
    formData.append('course_code', courseData.courseCode);
  }
  
  if (courseData.couponCode) {
    formData.append('coupon_code', courseData.couponCode);
  }
  
  const response = await fetch('/api/courses/authorize', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`
    },
    body: formData
  });
  return response.json();
};

// Usage
const result = await purchaseCourse({
  courseId: 1,
  paymentMethod: 'online',
  paymentScreenshot: fileInput.files[0],
  couponCode: 'DISCOUNT20'
});
```

### 5. Get Student Enrollments:
```javascript
const getMyEnrollments = async () => {
  const response = await fetch('/api/courses/my-enrollments', {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  return response.json();
};

// Usage
const enrollments = await getMyEnrollments();
```

### 6. Get Student Orders:
```javascript
const getMyOrders = async (page = 1, perPage = 15) => {
  const response = await fetch(`/api/courses/my-orders?page=${page}&per_page=${perPage}`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  return response.json();
};

// Usage
const orders = await getMyOrders(1, 20);
```

### 7. Get Student Bills:
```javascript
const getMyBills = async (page = 1, perPage = 15) => {
  const response = await fetch(`/api/courses/my-bills?page=${page}&per_page=${perPage}`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  return response.json();
};

// Usage
const bills = await getMyBills(1, 20);
```

### 8. Check Course Access:
```javascript
const checkCourseAccess = async (courseId) => {
  const response = await fetch('/api/courses/check-access', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ course_id: courseId })
  });
  return response.json();
};

// Usage
const access = await checkCourseAccess(1);
if (access.data.has_access) {
  console.log('User has access to this course');
}
```

---

## Error Handling

### Common Error Responses:

#### 401 Unauthorized:
```json
{
  "status": 401,
  "success": false,
  "message": "Unauthenticated"
}
```

#### 403 Forbidden:
```json
{
  "status": 403,
  "success": false,
  "message": "You are not enrolled in this course",
  "data": null
}
```

#### 404 Not Found:
```json
{
  "status": 404,
  "success": false,
  "message": "Course not found",
  "data": null
}
```

#### 422 Validation Error:
```json
{
  "status": 422,
  "success": false,
  "message": "The given data was invalid",
  "errors": {
    "course_id": ["The course id field is required"],
    "payment_method": ["The payment method field is required"]
  }
}
```

#### 500 Server Error:
```json
{
  "status": 500,
  "success": false,
  "message": "Failed to fetch courses",
  "error": "Database connection error"
}
```

### Error Handling in React:
```javascript
const handleApiCall = async (apiFunction) => {
  try {
    const response = await apiFunction();
    
    if (response.status === 200 && response.success) {
      return response.data;
    } else {
      throw new Error(response.message || 'An error occurred');
    }
  } catch (error) {
    console.error('API Error:', error);
    
    if (error.status === 401) {
      // Handle unauthorized - redirect to login
      window.location.href = '/login';
    } else if (error.status === 403) {
      // Handle forbidden - show access denied message
      alert('You do not have access to this resource');
    } else if (error.status === 404) {
      // Handle not found - show not found message
      alert('Resource not found');
    } else {
      // Handle other errors
      alert('An error occurred. Please try again.');
    }
    
    throw error;
  }
};
```

---

## Key Features

✅ **Complete Course Management** - List, view, purchase, and track progress  
✅ **Multiple Payment Methods** - Online, course codes, wallet transfers  
✅ **Progress Tracking** - Detailed section-by-section progress  
✅ **Access Control** - Section locking based on completion  
✅ **Billing System** - Complete order and billing history  
✅ **Optimized Performance** - Single-query progress checking  
✅ **Pagination Support** - Efficient data loading  
✅ **Error Handling** - Comprehensive error responses  
✅ **Multi-language Support** - Arabic and English content  
✅ **File Upload Support** - Payment screenshots and documents  

---

## Section Types

The course sections can be of different types:

- **`lesson`** - Video lessons with files and materials
- **`quiz`** - Interactive quizzes and assessments  
- **`question`** - Individual questions for practice
- **`link`** - External links and resources
- **`course`** - Nested course content

---

## Payment Methods

- **`online`** - Online payment (requires screenshot)
- **`course_code`** - Free access with course code
- **`wallet`** - Wallet transfer (requires screenshot)

---

## Order Statuses

- **`pending`** - Waiting for admin approval
- **`approved`** - Order approved, access granted
- **`rejected`** - Order rejected
- **`cancelled`** - Order cancelled

---

## Payment Statuses

- **`pending`** - Payment pending
- **`paid`** - Payment completed
- **`failed`** - Payment failed
- **`refunded`** - Payment refunded

---

*This documentation is up-to-date and ready for your React frontend implementation! 🚀*

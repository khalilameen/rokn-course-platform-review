# Section Completion API Documentation

## Mark Course Section as Completed

### Endpoint
```
POST /api/courses/{courseId}/sections/{sectionId}/complete
```

### Description
Marks a specific course section as completed for the authenticated user and returns updated course progress.

### Authentication
- **Required**: Yes
- **Type**: Bearer Token
- **Header**: `Authorization: Bearer {api_token}`

### Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| courseId | integer | Yes | The ID of the course |
| sectionId | integer | Yes | The ID of the section to mark as completed |

### Request Example
```bash
POST /api/courses/1/sections/5/complete
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Content-Type: application/json
```

### Success Response (200)
```json
{
  "status": 200,
  "success": true,
  "message": "Section marked as completed successfully",
  "data": {
    "section": {
      "id": 5,
      "title": "الدرس الخامس",
      "type": "lesson",
      "order": 5,
      "is_completed": true,
      "completed_at": "2024-01-15T10:30:00Z"
    }
  }
}
```

### Error Responses

#### 401 Unauthorized
```json
{
  "status": 401,
  "success": false,
  "message": "Unauthenticated"
}
```

#### 403 Forbidden
```json
{
  "status": 403,
  "success": false,
  "message": "You are not authorized to access this course"
}
```

#### 404 Not Found
```json
{
  "status": 404,
  "success": false,
  "message": "Section not found in this course"
}
```

#### 500 Internal Server Error
```json
{
  "status": 500,
  "success": false,
  "message": "Failed to mark section as completed",
  "error": "Error details here"
}
```

### Frontend Implementation Example

#### JavaScript/TypeScript
```javascript
const markSectionComplete = async (courseId, sectionId, apiToken) => {
  try {
    const response = await fetch(`/api/courses/${courseId}/sections/${sectionId}/complete`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${apiToken}`,
        'Content-Type': 'application/json'
      }
    });
    
    const result = await response.json();
    
    if (result.success) {
      // Update UI with new progress
      updateProgressBar(result.data.course_progress.progress_percentage);
      showSuccessMessage('Section completed successfully!');
      return result.data;
    } else {
      showErrorMessage(result.message);
      throw new Error(result.message);
    }
  } catch (error) {
    console.error('Failed to mark section complete:', error);
    showErrorMessage('Failed to mark section as completed');
  }
};

// Usage
markSectionComplete(1, 5, userApiToken);
```

#### React Hook Example
```javascript
import { useState } from 'react';

const useSectionCompletion = () => {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const markSectionComplete = async (courseId, sectionId, apiToken) => {
    setLoading(true);
    setError(null);
    
    try {
      const response = await fetch(`/api/courses/${courseId}/sections/${sectionId}/complete`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${apiToken}`,
          'Content-Type': 'application/json'
        }
      });
      
      const result = await response.json();
      
      if (result.success) {
        return result.data;
      } else {
        setError(result.message);
        throw new Error(result.message);
      }
    } catch (err) {
      setError(err.message);
      throw err;
    } finally {
      setLoading(false);
    }
  };

  return { markSectionComplete, loading, error };
};
```

### Notes
- The endpoint is **idempotent** - calling it multiple times won't create duplicates
- If a section is already completed, it returns a success response with the existing completion data
- The endpoint automatically calculates and returns updated course progress
- Access control includes both direct course enrollment and parent course access
- All timestamps are in ISO 8601 format

### Response Data Fields

#### Section Object
- `id`: Section ID
- `title`: Section title (Arabic)
- `type`: Section type (lesson, quiz, etc.)
- `order`: Section order in the course
- `is_completed`: Boolean indicating completion status
- `completed_at`: Timestamp when section was completed

#### Course Progress Object
- `total_sections`: Total number of sections in the course
- `completed_sections`: Number of completed sections
- `progress_percentage`: Progress percentage (0-100)
- `is_completed`: Boolean indicating if entire course is completed

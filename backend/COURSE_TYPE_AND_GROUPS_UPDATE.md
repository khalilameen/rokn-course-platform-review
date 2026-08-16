# Course Type and Groups Update

## Overview
Successfully added course type selection and group management functionality to the courses system. The implementation includes a course type field and a many-to-many relationship between courses and groups.

## New Features Added

### Course Type Field
- **Field**: `course_type` (enum)
- **Values**: 
  - `online` - Online courses only
  - `center` - Center-based courses only  
  - `both` - Both online and center-based
- **Default**: `online`

### Groups Relationship
- **Table**: `course_group` (pivot table)
- **Relationship**: Many-to-many between courses and groups
- **Fields**: `course_id`, `group_id`, `tenant_id`, timestamps
- **Constraints**: Unique combination of course_id and group_id

## Database Changes

### Migration 1: Course Type Field
- **File**: `2024_01_01_000003_add_course_type_and_groups_to_courses_table.php`
- **Action**: Adds `course_type` enum field to courses table
- **Default**: `online`

### Migration 2: Course-Group Pivot Table
- **File**: `2024_01_01_000004_create_course_group_table.php`
- **Action**: Creates pivot table for course-group relationships
- **Features**: Foreign key constraints, unique constraints, tenant support

## Model Updates

### Course Model
- **Added**: `course_type` to fillable array
- **Added**: `groups()` relationship method
- **Added**: `getCourseTypeNameAttribute()` accessor for Arabic names
- **Removed**: `group_ids` field and array casting

### Group Model
- **Existing**: Already had proper structure for the relationship
- **Features**: BelongsToTenant trait, center relationship

## Admin Interface Updates

### Form Updates (`_form.blade.php`)
- **Added**: Course type dropdown with three options
- **Added**: Conditional groups selection (shows only for 'center' or 'both')
- **Added**: JavaScript to toggle groups section visibility
- **Features**: Multiple selection for groups with helpful instructions

### Show View Updates (`show.blade.php`)
- **Added**: Course type display in course information
- **Added**: Groups display in additional information section
- **Features**: Conditional display (only shows groups for center/both types)
- **UI**: Badge-style display for groups with center names

### Index View Updates (`index.blade.php`)
- **Added**: Course type column in the courses list
- **Features**: Shows Arabic name for course type
- **Layout**: Adjusted column widths to accommodate new field

## Controller Updates

### CourseController
- **Store Method**: Added groups attachment logic
- **Update Method**: Added groups sync logic (replaces existing relationships)
- **Edit Method**: Added groups relationship loading
- **Features**: Proper handling of many-to-many relationships

## API Updates

### CourseResource
- **Added**: `course_type` field with proper type casting
- **Added**: `course_type_name` field for Arabic display
- **Added**: `group_ids` field (array of group IDs from relationship)
- **Features**: Backward compatibility maintained

## JavaScript Functionality

### Form Interaction
```javascript
// Toggles groups section based on course type selection
function toggleGroupsSection() {
    const selectedValue = courseTypeSelect.value;
    if (selectedValue === 'center' || selectedValue === 'both') {
        groupsSection.style.display = 'block';
    } else {
        groupsSection.style.display = 'none';
    }
}
```

## Usage Examples

### Creating a Center Course with Groups
1. Select "مركز" (Center) as course type
2. Groups section becomes visible
3. Select multiple groups using Ctrl/Cmd
4. Save the course

### Creating an Online Course
1. Select "أونلاين" (Online) as course type
2. Groups section remains hidden
3. Save the course

### Creating a Mixed Course
1. Select "مركز وأونلاين" (Both) as course type
2. Groups section becomes visible
3. Select groups as needed
4. Save the course

## API Response Example
```json
{
  "id": 1,
  "title": "دورة التأسيس",
  "course_type": "both",
  "course_type_name": "مركز وأونلاين",
  "group_ids": [1, 2, 3],
  "price": 150.00,
  "currency": "جنيه",
  "students_count": 85
}
```

## Testing Results

### ✅ Database
- Course type field added successfully
- Pivot table created with proper constraints
- Foreign key relationships working

### ✅ Model Relationships
- Course-Group many-to-many relationship working
- Accessor methods returning correct Arabic names
- Array casting removed as expected

### ✅ Admin Interface
- Course type dropdown working
- Conditional groups display working
- Form submission handling groups correctly
- Show view displaying all information properly

### ✅ API
- CourseResource returning correct data
- Group IDs array populated from relationship
- Type casting working properly

## Migration Status
- ✅ Course type migration executed successfully
- ✅ Course-group pivot table migration executed successfully
- ✅ All relationships working correctly
- ✅ Admin interface fully functional
- ✅ API updated and working

## Next Steps
The course type and groups system is now fully functional. Administrators can:
1. Set course types (online, center, or both)
2. Assign multiple groups to center/both courses
3. View course type and group information in admin interface
4. Access course type and group data via API
5. Filter and manage courses based on type and group assignments

All changes maintain backward compatibility and follow Laravel best practices for many-to-many relationships.

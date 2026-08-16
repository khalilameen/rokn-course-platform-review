# AI Frontend Development Prompt: Course Code Redemption & Course Purchasing

## 🎯 **Task**
Build React components for course code redemption and course purchasing functionality for an educational platform. The design system already exists - maintain consistency with existing components.

## 🏗️ **Technical Requirements**
- Use your existing frontend framework and architecture
- Match existing styling approach and design system
- Use existing HTTP client and API patterns
- Use existing form handling and validation patterns
- Use existing state management patterns
- Use existing routing system

## 📱 **Required Pages & Components**

### **1. Course Code Redemption Page** (`/redeem-code`)

**Features:**
- Code input field with real-time validation
- Success/error feedback with modals
- Course preview after successful redemption
- Navigation to course after redemption

**API Endpoints:**
```typescript
// Check code validity
POST /api/course-codes/check
Body: { code: string }

// Redeem code  
POST /api/course-codes/redeem
Body: { code: string }
```

**Response Format:**
```typescript
{
  status: 200,
  success: true,
  message: "تم تفعيل الكود بنجاح",
  data: {
    code: string,
    type: "course" | "lesson" | "multiple_lessons",
    target_content_name: string,
    remaining_uses: number,
    course?: {
      id: number,
      name: string,
      description: string
    }
  }
}
```

### **2. Course Purchasing Page** (`/courses/:courseId/purchase`)

**Features:**
- Course details display
- Payment method selection (online, course_code, wallet)
- Conditional form fields based on payment method
- File upload for payment screenshots
- Order summary and confirmation

**API Endpoints:**
```typescript
// Authorize course access
POST /api/courses/authorize
Body: {
  course_id: number,
  payment_method: "online" | "course_code" | "wallet",
  course_code?: string,
  coupon_code?: string,
  payment_screenshot?: File,
  notes?: string
}
```

## 🎨 **Design Guidelines**

### **Consistency Requirements**
- Use existing color palette and typography
- Match existing button styles and form inputs
- Follow established spacing and layout patterns
- Use consistent modal and toast notification patterns
- Maintain responsive design (mobile-first)

### **UI Patterns**
- Use existing button styles and patterns
- Use existing form input patterns
- Use existing modal/dialog patterns
- Use existing loading state patterns
- Use existing error display patterns
- Use existing success confirmation patterns

## 🔧 **Implementation Details**

### **Form Validation**
- Follow existing validation patterns and libraries
- Validate course code input (required, min/max length)
- Validate purchase form (course_id, payment_method, conditional fields)
- Use existing error message patterns and localization

### **Error Handling**
- Follow existing error handling patterns
- Handle API errors gracefully
- Use existing notification/toast patterns
- Display user-friendly error messages

## 🚀 **User Flows**

### **Course Code Redemption Flow**
1. User enters code → Real-time validation
2. User submits → API call to redeem
3. Success → Show course preview → Navigate to course
4. Error → Show error message with retry

### **Course Purchase Flow**  
1. User selects payment method
2. User fills required fields (conditional)
3. User submits → API call to authorize
4. Success → Redirect to course content
5. Error → Show error message with retry

## 📋 **Required Functionality**

### **Pages/Views**
- Course code redemption page/view
- Course purchase page/view

### **UI Elements**
- Code input field with validation
- Payment method selection interface
- File upload for payment screenshots
- Order summary display
- Success/error feedback
- Course preview after redemption

### **Services/API Integration**
- Course code API operations (check, redeem, get user codes)
- Course purchase API operations (authorize, check access, get enrollments)

## 🔒 **Security & Authentication**
- All API calls require Bearer token in Authorization header
- Handle token expiration gracefully
- Validate file uploads (type, size)
- Sanitize user inputs

## 📱 **Mobile Considerations**
- Mobile-first responsive design
- Touch-friendly interfaces
- Optimized form inputs for mobile
- Proper keyboard handling

## 🎯 **Success Criteria**
- ✅ Users can redeem codes successfully
- ✅ Users can purchase courses with different payment methods
- ✅ Proper error handling and user feedback
- ✅ Consistent design with existing components
- ✅ Responsive design works on all devices
- ✅ Accessibility compliance

## 📝 **Implementation Steps**
1. Create page/view structure following existing patterns
2. Implement API integration following existing service patterns
3. Build UI elements with validation following existing patterns
4. Add error handling and loading states following existing patterns
5. Test all user flows
6. Optimize for mobile and accessibility

**Note**: Match the existing design system and coding patterns exactly. Use your existing component library, styling approach, and architectural patterns.


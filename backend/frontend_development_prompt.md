# Frontend Development Prompt: Course Code Redemption & Course Purchasing

## 🎯 **Project Overview**

You are building a React frontend application for an educational platform (Ostaz App) that needs course code redemption and course purchasing functionality. The design system already exists, and you need to create consistent components for these new features.

## 🏗️ **Technical Stack & Architecture**

### **Core Technologies**
- Use your existing frontend framework and architecture
- Match existing state management patterns
- Use existing styling approach and design system
- Use existing HTTP client and API patterns
- Use existing form handling and validation patterns
- Use existing UI component library
- Use existing routing system

### **API Integration**
- **Base URL**: `https://your-api-domain.com/api`
- **Authentication**: Bearer token in Authorization header
- **Response Format**: `{ status: number, success: boolean, message: string, data: any }`

## 📱 **Required Pages & Components**

### **1. Course Code Redemption Page**
**Route**: `/redeem-code` or `/course-codes/redeem`

#### **Features**
- Code input field with validation
- Real-time code validation (check endpoint)
- Success/error feedback
- Course preview after successful redemption
- Navigation to course after redemption

#### **API Endpoints Used**
```typescript
// Check code validity
POST /api/course-codes/check
Body: { code: string }

// Redeem code
POST /api/course-codes/redeem
Body: { code: string }

// Get user's redeemed codes
GET /api/course-codes/my-codes
```

#### **Required Functionality**
- Code input field with validation
- Real-time code validation
- Success/error feedback display
- Course preview after successful redemption
- Navigation to course after redemption
- List of user's redeemed codes

### **2. Course Purchasing Page**
**Route**: `/courses/:courseId/purchase` or `/purchase-course`

#### **Features**
- Course details display
- Payment method selection (online, course_code, wallet)
- Course code input (if selected)
- Coupon code input (optional)
- Payment screenshot upload (for online/wallet)
- Order summary
- Purchase confirmation

#### **API Endpoints Used**
```typescript
// Authorize course access
POST /api/courses/authorize
Body: {
  course_id: number,
  payment_method: 'online' | 'course_code' | 'wallet',
  course_code?: string,
  coupon_code?: string,
  payment_screenshot?: File,
  notes?: string
}

// Check course access
POST /api/courses/check-access
Body: { course_id: number }

// Get user enrollments
GET /api/courses/my-enrollments
```

#### **Required Functionality**
- Course details display
- Payment method selection interface
- Conditional form fields based on payment method
- File upload for payment screenshots
- Order summary display
- Purchase confirmation
- Coupon code input (optional)

### **3. Course Access & Navigation**
**Route**: `/courses/:courseId` (existing, needs enhancement)

#### **Features**
- Check if user has access to course
- Redirect to purchase page if no access
- Display course content if authorized
- Show enrollment status

#### **API Endpoints Used**
```typescript
// Get course details (authorized users)
GET /api/courses/{courseId}/details

// Check course access
POST /api/courses/check-access
```

## 🎨 **Design Requirements**

### **Consistency Guidelines**
- Use existing color palette and typography
- Match existing component patterns
- Follow established spacing and layout rules
- Use consistent button styles and form inputs
- Maintain responsive design patterns

### **Key Design Elements**
- **Loading States**: Skeleton loaders or spinners
- **Error States**: Clear error messages with retry options
- **Success States**: Confirmation modals with next steps
- **Form Validation**: Real-time validation with helpful messages
- **Responsive Design**: Mobile-first approach

### **UI Patterns**
- Use existing button styles and patterns
- Use existing form input patterns
- Use existing modal/dialog patterns
- Use existing loading state patterns
- Use existing error display patterns
- Use existing success confirmation patterns

## 🔧 **Implementation Details**

### **1. State Management**
- Follow existing state management patterns
- Store course code data (redeemed codes, current code, loading, errors)
- Store course purchase data (current course, payment method, order summary, loading, errors)
- Use existing data structures and naming conventions

### **2. API Service Layer**
- Follow existing API service patterns
- Create service functions for course code operations (check, redeem, get user codes)
- Create service functions for course purchase operations (authorize, check access, get enrollments)
- Use existing HTTP client and error handling patterns

### **3. Form Validation**
- Follow existing validation patterns and libraries
- Validate course code input (required, min/max length)
- Validate purchase form (course_id, payment_method, conditional fields)
- Use existing error message patterns and localization

### **4. Error Handling**
- Follow existing error handling patterns
- Handle API errors gracefully
- Use existing notification/toast patterns
- Display user-friendly error messages

## 🚀 **User Flow Implementation**

### **Flow 1: Course Code Redemption**
1. User navigates to `/redeem-code`
2. User enters course code
3. Real-time validation checks code
4. User submits code
5. Success: Show course preview and redirect to course
6. Error: Show error message with retry option

### **Flow 2: Course Purchase**
1. User navigates to course page
2. If no access, show purchase options
3. User selects payment method
4. User fills required fields
5. User submits purchase
6. Success: Redirect to course content
7. Error: Show error message with retry option

### **Flow 3: Course Access**
1. User navigates to course page
2. Check access status
3. If authorized: Show course content
4. If not authorized: Show purchase options

## 📋 **Testing Requirements**

### **Unit Tests**
- Component rendering
- Form validation
- API service calls
- Error handling

### **Integration Tests**
- Complete user flows
- API integration
- Navigation between pages

### **User Acceptance Tests**
- Code redemption flow
- Course purchase flow
- Error scenarios
- Mobile responsiveness

## 🔒 **Security Considerations**

### **Authentication**
- Verify user is authenticated for all protected routes
- Handle token expiration gracefully
- Secure API calls with proper headers

### **Input Validation**
- Client-side validation for immediate feedback
- Server-side validation for security
- Sanitize user inputs

### **File Upload**
- Validate file types and sizes
- Secure file upload handling
- Progress indicators for large files

## 📱 **Mobile Considerations**

### **Responsive Design**
- Mobile-first approach
- Touch-friendly interfaces
- Optimized form inputs for mobile
- Proper keyboard handling

### **Performance**
- Lazy loading for components
- Optimized images
- Efficient state management
- Minimal bundle size

## 🎯 **Success Criteria**

### **Functional Requirements**
- ✅ Users can redeem course codes successfully
- ✅ Users can purchase courses with different payment methods
- ✅ Users can access purchased courses
- ✅ Proper error handling and user feedback
- ✅ Responsive design works on all devices

### **Technical Requirements**
- ✅ Consistent component design
- ✅ Proper TypeScript implementation
- ✅ Comprehensive error handling
- ✅ Optimized performance
- ✅ Accessibility compliance

### **User Experience Requirements**
- ✅ Intuitive navigation
- ✅ Clear feedback for all actions
- ✅ Smooth loading states
- ✅ Consistent visual design

## 📝 **Implementation Checklist**

- [ ] Set up project structure and routing
- [ ] Create reusable components
- [ ] Implement API service layer
- [ ] Add form validation
- [ ] Implement error handling
- [ ] Add loading states
- [ ] Test all user flows
- [ ] Optimize for mobile
- [ ] Add accessibility features
- [ ] Performance optimization
- [ ] Documentation

## 🔗 **Integration Points**

### **Existing Components to Extend**
- Navigation component (add new routes)
- Course card component (add purchase button)
- Modal system (for confirmations)
- Toast notification system
- Loading components

### **New Functionality to Add**
- Code redemption interface
- Payment method selection interface
- File upload functionality
- Order summary display
- Success/error feedback

This prompt provides a comprehensive guide for implementing the course code redemption and course purchasing features while maintaining consistency with your existing design system and architecture.


# Tenant-Specific Phone Validation Implementation Guide

## 🎯 **Backend Changes Implemented**

### 1. **Updated RegisterRequest Validation**
- Added tenant-specific phone uniqueness validation
- Added custom Arabic error message for duplicate phone numbers
- Added `getTenantId()` method to retrieve tenant from request attributes

### 2. **Updated Registration Controller**
- Added `tenant_id` to user creation process
- Ensures users are properly associated with their tenant

## 📱 **Frontend Implementation Guide**

### **Error Response Format**
When a phone number already exists for the tenant, the API returns:

```json
{
  "status": 422,
  "success": false,
  "message": "Validation failed",
  "errors": {
    "phone": [
      "رقم الهاتف مسجل مسبقاً في حساب آخر. يمكنك تسجيل الدخول الآن."
    ]
  }
}
```

### **Frontend Implementation Steps**

#### **1. Update Registration Form Validation**
```javascript
const handleRegistration = async (formData) => {
  try {
    const response = await fetch('/api/register', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'subdomain': 'your-tenant-subdomain' // Required header
      },
      body: JSON.stringify(formData)
    });

    const result = await response.json();

    if (result.success) {
      // Registration successful
      showSuccessMessage('تم التسجيل بنجاح!');
      // Redirect to login or dashboard
    } else {
      // Handle validation errors
      if (result.errors && result.errors.phone) {
        handlePhoneAlreadyExists(result.errors.phone[0]);
      } else {
        showErrorMessage(result.message);
      }
    }
  } catch (error) {
    showErrorMessage('حدث خطأ في التسجيل');
  }
};
```

#### **2. Handle Phone Already Exists Error**
```javascript
const handlePhoneAlreadyExists = (errorMessage) => {
  // Show the error message
  showErrorMessage(errorMessage);
  
  // Show sign-in option
  showSignInOption();
};

const showSignInOption = () => {
  // Create a modal or alert with sign-in option
  const signInModal = `
    <div class="phone-exists-modal">
      <h3>رقم الهاتف مسجل مسبقاً</h3>
      <p>رقم الهاتف هذا مسجل في حساب آخر. يمكنك تسجيل الدخول الآن.</p>
      <div class="modal-actions">
        <button onclick="goToSignIn()" class="btn-primary">
          تسجيل الدخول
        </button>
        <button onclick="closeModal()" class="btn-secondary">
          إلغاء
        </button>
      </div>
    </div>
  `;
  
  // Show modal (implement based on your UI framework)
  showModal(signInModal);
};

const goToSignIn = () => {
  // Navigate to sign-in page
  window.location.href = '/signin';
  // Or use your routing system
  // router.push('/signin');
};
```

#### **3. React Implementation Example**
```jsx
import React, { useState } from 'react';

const RegistrationForm = () => {
  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    phone: '',
    password: '',
    // ... other fields
  });
  const [errors, setErrors] = useState({});
  const [showSignInOption, setShowSignInOption] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});
    setShowSignInOption(false);

    try {
      const response = await fetch('/api/register', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'subdomain': 'your-tenant-subdomain'
        },
        body: JSON.stringify(formData)
      });

      const result = await response.json();

      if (result.success) {
        // Registration successful
        alert('تم التسجيل بنجاح!');
        // Redirect to login
      } else {
        if (result.errors && result.errors.phone) {
          setErrors({ phone: result.errors.phone[0] });
          setShowSignInOption(true);
        } else {
          setErrors({ general: result.message });
        }
      }
    } catch (error) {
      setErrors({ general: 'حدث خطأ في التسجيل' });
    }
  };

  return (
    <div>
      <form onSubmit={handleSubmit}>
        {/* Form fields */}
        <div>
          <input
            type="tel"
            name="phone"
            value={formData.phone}
            onChange={(e) => setFormData({...formData, phone: e.target.value})}
            placeholder="رقم الهاتف"
          />
          {errors.phone && <span className="error">{errors.phone}</span>}
        </div>
        
        {/* Other form fields */}
        
        <button type="submit">تسجيل</button>
      </form>

      {/* Sign-in option modal */}
      {showSignInOption && (
        <div className="modal">
          <div className="modal-content">
            <h3>رقم الهاتف مسجل مسبقاً</h3>
            <p>رقم الهاتف هذا مسجل في حساب آخر. يمكنك تسجيل الدخول الآن.</p>
            <div className="modal-actions">
              <button onClick={() => window.location.href = '/signin'}>
                تسجيل الدخول
              </button>
              <button onClick={() => setShowSignInOption(false)}>
                إلغاء
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
```

#### **4. Vue.js Implementation Example**
```vue
<template>
  <div>
    <form @submit.prevent="handleSubmit">
      <div>
        <input
          v-model="formData.phone"
          type="tel"
          placeholder="رقم الهاتف"
        />
        <span v-if="errors.phone" class="error">{{ errors.phone }}</span>
      </div>
      
      <!-- Other form fields -->
      
      <button type="submit">تسجيل</button>
    </form>

    <!-- Sign-in option modal -->
    <div v-if="showSignInOption" class="modal">
      <div class="modal-content">
        <h3>رقم الهاتف مسجل مسبقاً</h3>
        <p>رقم الهاتف هذا مسجل في حساب آخر. يمكنك تسجيل الدخول الآن.</p>
        <div class="modal-actions">
          <button @click="goToSignIn">تسجيل الدخول</button>
          <button @click="showSignInOption = false">إلغاء</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      formData: {
        first_name: '',
        last_name: '',
        phone: '',
        password: '',
        // ... other fields
      },
      errors: {},
      showSignInOption: false
    };
  },
  methods: {
    async handleSubmit() {
      this.errors = {};
      this.showSignInOption = false;

      try {
        const response = await fetch('/api/register', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'subdomain': 'your-tenant-subdomain'
          },
          body: JSON.stringify(this.formData)
        });

        const result = await response.json();

        if (result.success) {
          alert('تم التسجيل بنجاح!');
          this.$router.push('/signin');
        } else {
          if (result.errors && result.errors.phone) {
            this.errors.phone = result.errors.phone[0];
            this.showSignInOption = true;
          } else {
            this.errors.general = result.message;
          }
        }
      } catch (error) {
        this.errors.general = 'حدث خطأ في التسجيل';
      }
    },
    goToSignIn() {
      this.$router.push('/signin');
    }
  }
};
</script>
```

## 🎨 **UI/UX Recommendations**

### **1. Error Message Display**
- Show the Arabic error message prominently
- Use red color for error text
- Position error message below the phone input field

### **2. Sign-in Option Modal**
- Create a modal or popup when phone exists
- Include clear call-to-action button for sign-in
- Provide option to close modal and try different phone number

### **3. User Experience Flow**
```
User enters phone → API validates → Phone exists?
├─ No: Continue registration
└─ Yes: Show error + Sign-in option
    ├─ User clicks "Sign In" → Redirect to login
    └─ User clicks "Cancel" → Allow phone change
```

## 🔧 **Required Headers**
Make sure to include the `subdomain` header in all API requests:
```javascript
headers: {
  'Content-Type': 'application/json',
  'subdomain': 'your-tenant-subdomain' // This is crucial for tenant validation
}
```

## ✅ **Testing Checklist**
- [ ] Test with new phone number (should register successfully)
- [ ] Test with existing phone number (should show error + sign-in option)
- [ ] Test sign-in option redirects to login page
- [ ] Test modal can be closed and user can try different phone
- [ ] Test with different tenants (phone should be unique per tenant)

## 🚀 **Benefits**
- Prevents duplicate accounts within the same tenant
- Provides clear user guidance when phone exists
- Maintains data integrity across multi-tenant system
- Improves user experience with helpful error messages

# React App Quick Start Guide

## 🚀 Quick Setup (5 Steps)

### Step 1: Update Your React App's Vite Config

Copy the `vite.config.sample.ts` to your React app and rename it to `vite.config.ts`:

```bash
cp vite.config.sample.ts /path/to/your/react-app/vite.config.ts
```

Then edit the `outDir` path to match your Laravel installation path.

### Step 2: Build Your React App

```bash
cd /path/to/your/react-app
npm run build
# or
bun run build
```

### Step 3: Deploy to Laravel (Windows)

```bash
cd D:\laragon\www\ostaz-backend
deploy-react.bat C:\path\to\your\react-app
```

### Step 4: Start Laravel Server

```bash
php artisan serve
```

### Step 5: Visit Your App

Open your browser and go to: **http://127.0.0.1:8000/**

Your React app will be displayed! 🎉

---

## 📋 What Was Set Up

### 1. Laravel Route Configuration
- **Home route ("/")**: Serves your React app
- **All other routes**: Continue to work normally (API, admin, merchant, etc.)

### 2. Blade Template Created
- `resources/views/react-app.blade.php`: Loads your React app's built files

### 3. Deployment Scripts
- `deploy-react.sh`: Linux/Mac deployment script
- `deploy-react.bat`: Windows deployment script

---

## 🔧 Manual Deployment (Alternative)

If you prefer to deploy manually:

```bash
# 1. Build React app
cd /path/to/react-app
npm run build

# 2. Create directory in Laravel
mkdir -p D:\laragon\www\ostaz-backend\public\react

# 3. Copy files (Windows PowerShell)
Copy-Item -Path C:\path\to\react-app\dist\* -Destination D:\laragon\www\ostaz-backend\public\react\ -Recurse -Force

# 4. Or use Windows Command Prompt
xcopy C:\path\to\react-app\dist\* D:\laragon\www\ostaz-backend\public\react\ /E /I /Y
```

---

## 🧪 Testing

### Test the React App (Home Route)
```
http://127.0.0.1:8000/
```
Should display your React application.

### Test Other Laravel Routes (Should Still Work)
```
http://127.0.0.1:8000/privacy
http://127.0.0.1:8000/contact
http://127.0.0.1:8000/api/courses
```
All these routes should continue to work as before.

---

## 🐛 Troubleshooting

### Problem: Blank Page
**Solution:**
1. Check if files exist in `public/react/assets/`
2. Open browser console (F12) and check for errors
3. Verify the base path in vite.config.ts is set to `/react/`

### Problem: Assets Not Loading (404 Errors)
**Solution:**
```php
// In resources/views/react-app.blade.php, try absolute paths:
<link rel="stylesheet" href="{{ asset('react/assets/index.css') }}">
<script type="module" src="{{ asset('react/assets/index.js') }}"></script>
```

### Problem: API Calls Fail from React
**Solution:**
1. Make sure you're sending the Authorization token
2. Include the 'subdomain' header if required
3. Check CORS configuration in `config/cors.php`

### Problem: React Router Navigation Not Working
**Solution:**
Add a catch-all route in `routes/web.php` (at the END of the file):
```php
Route::get('/{any}', function () {
    return view('react-app');
})->where('any', '^(?!api|merchant|admin|privacy|contact).*$');
```

---

## 📁 Expected File Structure

After deployment, your Laravel public directory should look like this:

```
public/
├── react/                  # Your React app
│   ├── assets/
│   │   ├── index.css      # Compiled styles
│   │   └── index.js       # Compiled JavaScript
│   ├── favicon.ico
│   └── (other static files)
├── css/                    # Laravel assets
├── js/                     # Laravel assets
└── index.php              # Laravel entry point
```

---

## 🔄 Update Workflow

When you make changes to your React app:

```bash
# 1. Make changes in React app
# 2. Rebuild
cd /path/to/react-app
npm run build

# 3. Redeploy
cd D:\laragon\www\ostaz-backend
deploy-react.bat C:\path\to\your\react-app

# 4. Refresh browser (Ctrl + F5 to clear cache)
```

---

## 🎯 Pro Tips

### Tip 1: Auto-Deploy on Build
Add this to your React app's `package.json`:

```json
{
  "scripts": {
    "build": "vite build",
    "build:deploy": "vite build && cd ../ostaz-backend && deploy-react.bat %cd%"
  }
}
```

### Tip 2: Environment Variables
Create `.env.local` in your React app:

```env
VITE_API_URL=http://127.0.0.1:8000/api
VITE_APP_NAME=Ostaz
```

Access in React:
```typescript
const apiUrl = import.meta.env.VITE_API_URL;
```

### Tip 3: API Integration Helper
Create a helper in your React app:

```typescript
// src/lib/api.ts
const API_BASE = import.meta.env.VITE_API_URL || '/api';

export async function apiCall(endpoint: string, options: RequestInit = {}) {
  const token = localStorage.getItem('auth_token');
  const subdomain = localStorage.getItem('subdomain');
  
  const response = await fetch(`${API_BASE}${endpoint}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'Authorization': token ? `Bearer ${token}` : '',
      'subdomain': subdomain || '',
      ...options.headers,
    },
  });
  
  return response.json();
}
```

---

## 📞 Need Help?

Check the detailed documentation: `REACT_APP_INTEGRATION.md`

---

## ✅ Checklist

- [ ] React app's vite.config.ts is updated
- [ ] React app builds successfully (`npm run build`)
- [ ] Files are deployed to `public/react/`
- [ ] Home route (/) displays React app
- [ ] Other Laravel routes still work
- [ ] API calls work from React app
- [ ] No console errors in browser

If all checkboxes are checked, you're ready to go! 🚀



# React App Integration Guide

This guide explains how to integrate your React.js app with this Laravel backend so that it appears only on the home route ("/") while all other Laravel routes continue to work normally.

## Setup Instructions

### 1. Build Your React App

Navigate to your React app directory and build it for production:

```bash
cd /path/to/your/react-app
npm run build
# or if using bun
bun run build
```

This will create a `dist/` folder with your compiled React app.

### 2. Copy Built Files to Laravel Public Directory

Copy your React app's build output to Laravel's `public/react/` directory:

```bash
# Create the react directory if it doesn't exist
mkdir -p /path/to/laravel-app/public/react

# Copy all files from React dist to Laravel public/react
cp -r /path/to/your/react-app/dist/* /path/to/laravel-app/public/react/
```

**Important**: Make sure the structure looks like this:
```
public/
└── react/
    ├── assets/
    │   ├── index.css
    │   └── index.js
    ├── favicon.ico
    └── index.html (not needed, we use Blade template)
```

### 3. Update React App's Vite Configuration

To make future builds easier, update your React app's `vite.config.ts`:

```typescript
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: path.resolve(__dirname, '../ostaz-backend/public/react'),
    emptyOutDir: true,
    rollupOptions: {
      output: {
        assetFileNames: 'assets/[name].[ext]',
        chunkFileNames: 'assets/[name].js',
        entryFileNames: 'assets/[name].js',
      }
    }
  },
  base: '/react/', // Important: This ensures assets load correctly
})
```

### 4. Update React App's Base URL (if needed)

If your React app uses React Router, update it to work with the base path:

**In your React app's `main.tsx` or routing setup:**

```tsx
import { BrowserRouter } from 'react-router-dom'

// Use basename only if you need client-side routing
<BrowserRouter basename="/">
  <App />
</BrowserRouter>
```

### 5. Configure API Calls

If your React app needs to call Laravel API endpoints, configure the base URL:

**Create `.env.local` in your React app:**
```env
VITE_API_URL=http://127.0.0.1:8000/api
```

**In your React app's API client:**
```typescript
const API_BASE_URL = import.meta.env.VITE_API_URL || '/api'

// Example API call
fetch(`${API_BASE_URL}/courses`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'subdomain': 'your-tenant.ostaz.net'
  }
})
```

## How It Works

1. **Home Route ("/")**: When users visit the root URL, Laravel serves the `react-app.blade.php` view, which loads your React app.

2. **Other Routes**: All other routes (`/privacy`, `/contact`, `/api/*`, etc.) continue to work as normal Laravel routes.

3. **Static Assets**: React app assets are served from `public/react/assets/` directory.

## Development Workflow

### Option 1: Separate Development Servers

During development, run both servers separately:

```bash
# Terminal 1: Laravel backend
cd /path/to/laravel-app
php artisan serve

# Terminal 2: React frontend (with proxy)
cd /path/to/react-app
npm run dev
```

Then update your React app's `vite.config.ts` to proxy API calls:

```typescript
export default defineConfig({
  plugins: [react()],
  server: {
    port: 3000,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
        secure: false,
      }
    }
  }
})
```

### Option 2: Build and Test

For testing the production setup:

```bash
# Build React app
cd /path/to/react-app
npm run build

# Copy to Laravel (or it auto-copies if vite.config is set up)
# Then visit http://127.0.0.1:8000/ to see your React app
```

## Automated Build Script

Create a build script to automate the process:

**build-react.sh:**
```bash
#!/bin/bash

# Build React app
cd /path/to/your/react-app
echo "Building React app..."
npm run build

# Copy to Laravel public directory
echo "Copying files to Laravel..."
mkdir -p /path/to/laravel-app/public/react
cp -r dist/* /path/to/laravel-app/public/react/

echo "React app deployed successfully!"
```

Make it executable:
```bash
chmod +x build-react.sh
```

## Troubleshooting

### Issue: React app shows blank page
- Check browser console for errors
- Verify files exist in `public/react/assets/`
- Check file permissions: `chmod -R 755 public/react`

### Issue: 404 on assets
- Ensure `base` in `vite.config.ts` is set to `/react/`
- Clear Laravel cache: `php artisan cache:clear`

### Issue: API calls fail
- Check CORS configuration in Laravel
- Verify API routes are prefixed with `/api`
- Check Authorization headers are being sent

### Issue: React Router navigation doesn't work
- If using client-side routing, you may need to add a catch-all route:
```php
// In routes/web.php - Add this at the END of the file
Route::get('/{any}', function () {
    return view('react-app');
})->where('any', '^(?!api|merchant|admin).*$');
```

## Production Deployment

For production, ensure:

1. Build React app with production optimizations: `npm run build`
2. Set correct environment variables
3. Enable Laravel caching: `php artisan config:cache`
4. Use a proper web server (Nginx/Apache) instead of `php artisan serve`

## File Structure

```
laravel-app/
├── public/
│   └── react/           # React app build output
│       ├── assets/
│       │   ├── index.css
│       │   └── index.js
│       └── favicon.ico
├── resources/
│   └── views/
│       └── react-app.blade.php  # Blade template that loads React
└── routes/
    └── web.php          # Route configuration
```

## Summary

- ✅ React app served ONLY on "/" route
- ✅ All other Laravel routes work normally
- ✅ API routes remain accessible
- ✅ Easy to update: Just rebuild and copy



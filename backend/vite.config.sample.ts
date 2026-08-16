/**
 * Sample Vite Configuration for React App Integration with Laravel
 * 
 * Copy this file to your React app root directory and rename it to vite.config.ts
 * Update the paths according to your project structure
 */

import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  
  // Development server configuration
  server: {
    port: 3000,
    // Proxy API requests to Laravel backend during development
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
        secure: false,
      }
    }
  },
  
  // Build configuration
  build: {
    // Output directory - adjust the path to point to your Laravel public/react directory
    outDir: path.resolve(__dirname, '../ostaz-backend/public/react'),
    
    // Clear the output directory before building
    emptyOutDir: true,
    
    // Rollup options for output naming
    rollupOptions: {
      output: {
        // Asset file naming pattern
        assetFileNames: 'assets/[name].[ext]',
        
        // Chunk file naming pattern
        chunkFileNames: 'assets/[name].js',
        
        // Entry file naming pattern
        entryFileNames: 'assets/[name].js',
      }
    },
    
    // Generate sourcemaps for debugging (optional, remove in production)
    sourcemap: false,
    
    // Minification
    minify: 'esbuild',
  },
  
  // Base public path - important for asset loading
  base: '/react/',
  
  // Resolve configuration
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  
  // Define environment variables
  define: {
    // Make sure your app can access these
    'import.meta.env.VITE_API_URL': JSON.stringify(process.env.VITE_API_URL || 'http://127.0.0.1:8000/api'),
  },
})



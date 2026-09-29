import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
export default defineConfig({ plugins: [react(), tailwindcss()], server: { host: '0.0.0.0', watch: { ignored: ['**/backend/**'] }, proxy: { '/api': { target: process.env.LEADSPACE_API_TARGET || 'http://127.0.0.1:8000', changeOrigin: true } } } });

# ============================================================
# Frontend Security Patrol — build Vue 3 + Vite, hasil dist
# disajikan Nginx (SPA) + proxy /api → backend php-fpm
# Build context: folder frontend/
# ============================================================
FROM node:22-alpine AS build

WORKDIR /app

# install dependency (pakai package-lock untuk hasil deterministik)
COPY package.json package-lock.json ./
RUN npm ci

# build produksi → dist/
COPY . .
RUN npm run build

# ------------------------------------------------------------------
FROM nginx:1.27-alpine

# konfigurasi: SPA + proxy API ke service `backend:9000`
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf

# hasil build Vue
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80

# syntax=docker/dockerfile:1
FROM node:24-alpine AS build
WORKDIR /app
RUN corepack enable
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY . .
ARG VITE_API_MODE=http
ARG VITE_API_BASE_URL=/v1
ARG GIT_SHA=unknown
ENV VITE_API_MODE=$VITE_API_MODE VITE_API_BASE_URL=$VITE_API_BASE_URL VITE_BUILD_SHA=$GIT_SHA
RUN pnpm build

FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=3s CMD wget -qO- http://127.0.0.1/ >/dev/null || exit 1

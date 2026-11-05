FROM node:22-alpine AS deps
WORKDIR /app
COPY package*.json ./
RUN npm ci

FROM node:22-alpine AS build
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .

ARG NEXT_PUBLIC_LARAVEL_URL=http://localhost:18080
ARG NEXT_PUBLIC_INVENTORY_URL=http://localhost:18080
ARG NEXT_PUBLIC_WS_URL=http://localhost:18080
ARG NEXT_PUBLIC_WS_PATH=/ws
ENV NEXT_PUBLIC_LARAVEL_URL=$NEXT_PUBLIC_LARAVEL_URL
ENV NEXT_PUBLIC_INVENTORY_URL=$NEXT_PUBLIC_INVENTORY_URL
ENV NEXT_PUBLIC_WS_URL=$NEXT_PUBLIC_WS_URL
ENV NEXT_PUBLIC_WS_PATH=$NEXT_PUBLIC_WS_PATH

RUN npm run build

FROM node:22-alpine AS runtime
RUN apk add --no-cache wget
WORKDIR /app
ENV NODE_ENV=production
ENV LARAVEL_INTERNAL_URL=http://laravel-core.omnitix.svc.cluster.local:8000
ENV INVENTORY_INTERNAL_URL=http://go-inventory.omnitix.svc.cluster.local:8080
COPY --from=build /app/.next/standalone ./
COPY --from=build /app/.next/static ./.next/static
COPY --from=build /app/public ./public
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=3s --start-period=15s \
  CMD wget -qO- http://localhost:3000/api/health || exit 1
CMD ["node", "server.js"]

FROM node:20-alpine AS build
WORKDIR /app
COPY package*.json .npmrc* ./
RUN npm ci --legacy-peer-deps
COPY . .
RUN npm run build

FROM nginxinc/nginx-unprivileged:alpine AS runtime
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 8080
USER nginx
CMD ["nginx", "-g", "daemon off;"]
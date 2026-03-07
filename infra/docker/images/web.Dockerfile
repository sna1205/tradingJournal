FROM node:22-alpine AS build

WORKDIR /app
COPY apps/web/package*.json ./
RUN npm ci
COPY apps/web/ ./
RUN npm run build

FROM nginx:1.27-alpine

RUN apk add --no-cache gettext

COPY infra/docker/nginx/web.default.conf /etc/nginx/templates/default.conf.template
COPY infra/docker/entrypoints/web.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && sed -i 's/\r$//' /entrypoint.sh

COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]

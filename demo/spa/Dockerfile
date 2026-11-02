# syntax=docker/dockerfile:1
# Demo SPA image: built with Vite, served by nginx on port 80.
# The browser client comes from the "oauth-dpop-client" build context (packages/oauth-dpop-client),
# placed where vite.config.js expects it: ../../packages/oauth-dpop-client from the SPA.
FROM node:22-alpine AS build
WORKDIR /src/demo/spa
COPY package.json package-lock.json* ./
RUN npm install --no-audit --no-fund
COPY --from=oauth-dpop-client src /src/packages/oauth-dpop-client/src
COPY . ./
# Addresses as the BROWSER reaches them (not the Docker network names).
ARG VITE_ISSUER=http://localhost:8080
ARG VITE_CLIENT_ID=my-spa
ARG VITE_API_URL=http://localhost:8081
ENV VITE_ISSUER=$VITE_ISSUER VITE_CLIENT_ID=$VITE_CLIENT_ID VITE_API_URL=$VITE_API_URL
RUN npm run build

FROM nginx:1.27-alpine
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /src/demo/spa/dist /usr/share/nginx/html
EXPOSE 80

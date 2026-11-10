FROM node:22-alpine
WORKDIR /app
COPY server.mjs ./
USER node
ENV NODE_ENV=production DATA_DIR=/data
EXPOSE 8130
HEALTHCHECK --interval=30s --timeout=5s CMD wget -qO- http://127.0.0.1:${PORT:-8130}${BASE_PATH:-/wa-bridge}/health || exit 1
CMD ["node", "server.mjs"]

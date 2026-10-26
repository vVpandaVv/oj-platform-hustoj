# Build from the server package root (the folder that holds game-server/, shared/ and data/):
#   docker build -f game-server/Dockerfile -t oj-code-arena-server .
FROM node:22-alpine
WORKDIR /app
COPY shared ./shared
COPY data/default-bank.json ./data/default-bank.json
COPY game-server/package.json game-server/package-lock.json* ./game-server/
RUN cd game-server && npm install --omit=dev --no-audit --no-fund
COPY game-server/src ./game-server/src
# results the server could not deliver to WordPress are kept here
RUN mkdir -p game-server/data && chown node game-server/data
ENV HOST=0.0.0.0 PORT=3200
EXPOSE 3200
USER node
CMD ["node", "game-server/src/index.js"]

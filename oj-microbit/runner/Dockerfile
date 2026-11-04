FROM node:24.22.1-bookworm-slim
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund && npm cache clean --force
COPY src ./src
RUN test -x /usr/bin/timeout && /usr/bin/timeout --version >/dev/null \
    && groupadd --gid 10001 microbit && useradd --uid 10001 --gid microbit --no-create-home --shell /usr/sbin/nologin microbit
USER 10001:10001
ENV NODE_ENV=production
# Keep a wall-clock limit inside the container even if the API launcher dies.
ENTRYPOINT ["/usr/bin/timeout", "--signal=KILL", "40s"]
CMD ["node", "--max-old-space-size=256", "src/job.mjs"]

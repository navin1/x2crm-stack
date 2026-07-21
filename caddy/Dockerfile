# Stock caddy:2-alpine has no rate-limiting support built in — it needs the
# community caddy-ratelimit plugin compiled in via xcaddy. This multi-stage
# build does that, then copies just the resulting binary into a normal
# caddy:2-alpine runtime image (keeps the final image small, no build
# toolchain left behind).
FROM caddy:2-builder-alpine AS builder

RUN xcaddy build --with github.com/mholt/caddy-ratelimit

FROM caddy:2-alpine

COPY --from=builder /usr/bin/caddy /usr/bin/caddy

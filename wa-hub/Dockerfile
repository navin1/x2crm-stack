FROM node:20-alpine
RUN apk add --no-cache git python3 make g++
WORKDIR /app
COPY package.json .
RUN npm install --production
COPY server.js ./
EXPOSE 3001
CMD ["npm", "start"]

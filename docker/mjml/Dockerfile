FROM node:22-alpine

WORKDIR /app

RUN npm init -y && npm install mjml express

COPY server.js .

EXPOSE 15500

CMD ["node", "server.js"]

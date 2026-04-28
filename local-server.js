const http = require("node:http");
const fs = require("node:fs");
const path = require("node:path");
const querystring = require("node:querystring");

const rootDir = __dirname;
const port = Number(process.env.PORT || 8000);
const vkApiUrl = "https://api.vk.com/method/messages.send";

const mimeTypes = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "application/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".png": "image/png",
  ".webp": "image/webp",
  ".gif": "image/gif",
  ".svg": "image/svg+xml",
  ".ico": "image/x-icon",
};

function readLocalConfig() {
  const configPath = path.join(rootDir, "config.local.json");

  if (!fs.existsSync(configPath)) {
    return {};
  }

  return JSON.parse(fs.readFileSync(configPath, "utf8"));
}

function readRequestBody(request) {
  return new Promise((resolve, reject) => {
    let body = "";

    request.on("data", (chunk) => {
      body += chunk;
    });

    request.on("end", () => resolve(body));
    request.on("error", reject);
  });
}

function sendJson(response, statusCode, payload) {
  response.writeHead(statusCode, {
    "Content-Type": "application/json; charset=utf-8",
  });
  response.end(JSON.stringify(payload));
}

function formatDate(value) {
  const date = value ? new Date(value) : new Date();

  return new Intl.DateTimeFormat("ru-RU", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(date);
}

async function sendVkNotification(payload) {
  const config = readLocalConfig();
  const vkToken = config.VK_BOT_TOKEN || process.env.VK_BOT_TOKEN || "";
  const vkPeerId = config.VK_PEER_ID || process.env.VK_PEER_ID || "";
  const vkGroupId = config.VK_GROUP_ID || process.env.VK_GROUP_ID || "238127506";

  if (!vkToken || !vkPeerId) {
    return {
      ok: false,
      statusCode: 500,
      error: "VK credentials are not configured",
    };
  }

  const name = String(payload.name || "").trim();
  const phone = String(payload.phone || "").trim();
  const message = String(payload.message || "").trim();

  if (!name || !phone) {
    return {
      ok: false,
      statusCode: 400,
      error: "Name and phone are required",
    };
  }

  const vkMessage = [
    "Новая заявка с сайта",
    "Интегративный психотерапевт Алёна Писарева",
    `Сообщество: https://vk.com/club${vkGroupId}`,
    "",
    `Имя и фамилия клиента: ${name}`,
    `Номер телефона: ${phone}`,
    `Краткое описание проблемы: ${message || "не указано"}`,
    `Дата: ${formatDate(payload.createdAt)}`,
  ].join("\n");

  const recipientParam = /^\d+$/.test(vkPeerId) ? "peer_id" : "domain";
  const requestPayload = {
    access_token: vkToken,
    v: "5.199",
    [recipientParam]: vkPeerId,
    random_id: String(Date.now()),
    message: vkMessage,
  };

  const vkResponse = await fetch(vkApiUrl, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: querystring.stringify(requestPayload),
  });
  const result = await vkResponse.json();

  if (!vkResponse.ok || result.error) {
    return {
      ok: false,
      statusCode: 502,
      error: result.error?.error_msg || "VK request failed",
    };
  }

  return { ok: true, statusCode: 200 };
}

async function handleVkRequest(request, response) {
  try {
    const body = await readRequestBody(request);
    const payload = JSON.parse(body || "{}");
    const result = await sendVkNotification(payload);

    sendJson(response, result.statusCode, result.ok ? { ok: true } : result);
  } catch (error) {
    sendJson(response, 500, {
      ok: false,
      error: error.message || "Server error",
    });
  }
}

function serveStaticFile(request, response) {
  const requestUrl = new URL(request.url, `http://localhost:${port}`);
  const pathname = decodeURIComponent(requestUrl.pathname);
  const safePath = path.normalize(pathname === "/" ? "/index.html" : pathname).replace(/^(\.\.[/\\])+/, "");
  const filePath = path.join(rootDir, safePath);

  if (!filePath.startsWith(rootDir)) {
    response.writeHead(403);
    response.end("Forbidden");
    return;
  }

  fs.readFile(filePath, (error, content) => {
    if (error) {
      response.writeHead(404);
      response.end("Not found");
      return;
    }

    response.writeHead(200, {
      "Content-Type": mimeTypes[path.extname(filePath).toLowerCase()] || "application/octet-stream",
    });
    response.end(content);
  });
}

const server = http.createServer((request, response) => {
  if (request.method === "POST" && request.url === "/send-vk.php") {
    handleVkRequest(request, response);
    return;
  }

  if (request.method === "GET") {
    serveStaticFile(request, response);
    return;
  }

  response.writeHead(405);
  response.end("Method not allowed");
});

server.listen(port, () => {
  console.log(`Local test server is running: http://localhost:${port}`);
  console.log("Press Ctrl+C to stop it.");
});

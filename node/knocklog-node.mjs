// Knocklog collector for Node.js: Express, Fastify (middie), Connect or plain http (https://knocklog.ai)
//
// Reports AI-agent visits, and a sample of human pageviews, after each response is sent.
// It never changes responses and never throws into your app. Needs Node 18 or newer.
//
//   import { knocklog } from "./knocklog.mjs";
//   app.use(knocklog());                 // Express / Connect: before your routes
//   // plain http: const track = knocklog(); http.createServer((req, res) => { track(req, res); ... })
//
// The visitor's IP comes from Express's req.ip, so behind a proxy or CDN set
// app.set("trust proxy", ...) as you already do for your own logs. Without Express it uses
// the socket address. Do not use it on serverless functions that freeze between requests
// (use the Next.js middleware there).

const KNOCKLOG_ENDPOINT = "https://api.knocklog.ai/v1/events";
// Ingest-only key: it can send visits, it cannot read your data.
const KNOCKLOG_KEY = "kl_ing_YOUR_INGEST_KEY";
// Share of ordinary browser pageviews to report (0 to 1). Agents are always reported.
const HUMAN_SAMPLE_RATE = 1;

const FORWARDED_HEADERS = ["user-agent", "accept", "accept-language", "referer", "signature", "signature-input", "signature-agent", "from"];
const AGENT_HINT = /bot|crawl|spider|slurp|agent|gpt|claude|anthropic|perplexity|python|curl|wget|headless|scrapy|go-http|axios|node-fetch|okhttp|java\//i;
const ASSET = /\.(css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|wav|pdf|zip|gz)$/i;
const FLUSH_EVERY_MS = 10_000;
const MAX_BATCH = 100;

export function knocklog(options = {}) {
  const endpoint = options.endpoint ?? KNOCKLOG_ENDPOINT;
  const key = options.key ?? KNOCKLOG_KEY;
  const rate = options.humanSampleRate ?? HUMAN_SAMPLE_RATE;
  let queue = [];
  let timer;

  function flush() {
    clearTimeout(timer);
    timer = undefined;
    while (queue.length) {
      const events = queue.slice(0, MAX_BATCH);
      queue = queue.slice(MAX_BATCH);
      fetch(endpoint, {
        method: "POST",
        headers: { "content-type": "application/json", authorization: `Bearer ${key}` },
        body: JSON.stringify({ events }),
        signal: AbortSignal.timeout(5000),
      }).catch(() => {});
    }
  }

  function enqueue(event) {
    queue.push(event);
    if (queue.length >= MAX_BATCH) flush();
    else if (!timer) {
      timer = setTimeout(flush, FLUSH_EVERY_MS);
      timer.unref?.();
    }
  }

  const middleware = (req, res, next) => {
    try {
      const started = new Date();
      res.once("finish", () => {
        try {
          const event = toEvent(req, res, started, rate);
          if (event) enqueue(event);
        } catch {
          // Never let analytics break the app.
        }
      });
    } catch {
      // Never let analytics break the app.
    }
    if (typeof next === "function") next();
  };
  middleware.flush = flush;
  return middleware;
}

function header(req, name) {
  const v = req.headers[name];
  return Array.isArray(v) ? v.join(", ") : typeof v === "string" ? v : "";
}

function toEvent(req, res, started, rate) {
  const host = header(req, "host");
  const path = req.originalUrl || req.url || "";
  if (!host || !path.startsWith("/") || !/^[a-z0-9.-]+(:\d+)?$/i.test(host)) return null;
  const protocol = req.protocol || (req.socket?.encrypted ? "https" : "http");
  const url = new URL(`${protocol}://${host}${path}`);
  const method = (req.method || "GET").toUpperCase();
  const headers = {};
  for (const name of FORWARDED_HEADERS) {
    const value = header(req, name);
    if (value) headers[name] = value.slice(0, 4096);
  }
  const ua = headers["user-agent"] || "";
  const signed = Boolean(headers["signature-agent"] || headers["signature-input"]);
  const agentLike = signed || !/^mozilla\//i.test(ua) || AGENT_HINT.test(ua);
  let sampleRate;
  if (!agentLike) {
    // People: HTML pageviews only, sampled.
    if (method !== "GET" || ASSET.test(url.pathname) || !(headers.accept || "").includes("text/html")) return null;
    if (rate <= 0 || Math.random() >= rate) return null;
    if (rate < 1) sampleRate = rate;
  }
  // Agents: the exact URL and IP are needed to verify them.
  // People: only utm_* parameters are kept from the query string. Their IP is used once, to
  // look up which organization's network it belongs to, and is not stored.
  let reported = url.href;
  if (!agentLike) {
    const clean = new URL(url.origin + url.pathname);
    for (const [k, v] of url.searchParams) if (k.startsWith("utm_")) clean.searchParams.append(k, v);
    reported = clean.href;
  }
  const ip = req.ip || req.socket?.remoteAddress || undefined;
  return {
    timestamp: started.toISOString(),
    method,
    url: reported,
    ip: ip ? ip.replace(/^::ffff:/, "") : undefined,
    status: res.statusCode,
    headers,
    sampleRate,
  };
}

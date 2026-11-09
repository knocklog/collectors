// Knocklog edge collector for Cloudflare Workers (https://knocklog.ai)
// Add it to your site's route (e.g. example.com/*). It passes every request to your
// origin unchanged and reports AI-agent visits to Knocklog after the response is sent.
const KNOCKLOG_ENDPOINT = "https://api.knocklog.ai/v1/events";
// Ingest-only key: it can send events, it cannot read your data.
const KNOCKLOG_KEY = "kl_ing_YOUR_INGEST_KEY";
// Share of ordinary browser pageviews to report (0 to 1). Agents are always reported.
const HUMAN_SAMPLE_RATE = 1;

const FORWARDED_HEADERS = ["user-agent", "accept", "accept-language", "referer", "signature", "signature-input", "signature-agent", "from"];
const AGENT_HINT = /bot|crawl|spider|slurp|agent|gpt|claude|anthropic|perplexity|python|curl|wget|headless|scrapy|go-http|axios|node-fetch|okhttp|java\//i;
const ASSET = /\.(css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|wav|pdf|zip|gz)$/i;

export default {
  async fetch(request, env, ctx) {
    // If anything in this Worker throws, Cloudflare sends the request to your origin as if
    // the Worker did not exist.
    ctx.passThroughOnException();
    const response = await fetch(request);
    try {
      const event = toEvent(request, response);
      if (event) ctx.waitUntil(send(event).catch(() => {}));
    } catch (e) {
      // Never let analytics break the site.
    }
    return response;
  },
};

function toEvent(request, response) {
  const url = new URL(request.url);
  const ua = request.headers.get("user-agent") || "";
  const signed = request.headers.has("signature-agent") || request.headers.has("signature-input");
  const agentLike = signed || !/^mozilla\//i.test(ua) || AGENT_HINT.test(ua);
  let sampleRate;
  if (!agentLike) {
    // Humans: count HTML pageviews only, sampled.
    const accept = request.headers.get("accept") || "";
    if (request.method !== "GET" || ASSET.test(url.pathname) || !accept.includes("text/html")) return null;
    if (HUMAN_SAMPLE_RATE <= 0 || Math.random() >= HUMAN_SAMPLE_RATE) return null;
    if (HUMAN_SAMPLE_RATE < 1) sampleRate = HUMAN_SAMPLE_RATE;
  }
  const headers = {};
  for (const name of FORWARDED_HEADERS) {
    const value = request.headers.get(name);
    if (value) headers[name] = value.slice(0, 4096);
  }
  // Agents: the exact URL and IP are needed to verify signatures and published IP ranges.
  // People: only utm_* parameters are kept from the query string. Their IP is used once, to
  // look up which organization's network it belongs to, and is not stored.
  let reportedUrl = request.url;
  if (!agentLike) {
    const clean = new URL(url.origin + url.pathname);
    for (const [k, v] of url.searchParams) if (k.startsWith("utm_")) clean.searchParams.append(k, v);
    reportedUrl = clean.href;
  }
  return {
    timestamp: new Date().toISOString(),
    method: request.method,
    url: reportedUrl,
    ip: request.headers.get("cf-connecting-ip") || undefined,
    status: response.status,
    headers,
    sampleRate,
  };
}

function send(event) {
  return fetch(KNOCKLOG_ENDPOINT, {
    method: "POST",
    headers: { "content-type": "application/json", authorization: "Bearer " + KNOCKLOG_KEY },
    body: JSON.stringify({ events: [event] }),
  });
}

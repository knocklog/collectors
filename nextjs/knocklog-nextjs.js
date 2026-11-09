// Knocklog collector for Next.js middleware, on Vercel or Netlify (https://knocklog.ai)
//
// Reports AI-agent visits, and a sample of human pageviews, without delaying the page:
// the report is sent in the background with event.waitUntil.
//
// No middleware yet: save this file at the root of your project (next to package.json, or
// inside src/ if you use it) as proxy.js on Next.js 16 or newer, middleware.js before that,
// and deploy.
// Already have one: copy reportToKnocklog() into it and call
//   event.waitUntil(reportToKnocklog(request));
// at the top of your function (its second argument is `event`).
//
// The visitor's IP is read from x-real-ip / x-forwarded-for, which Vercel and Netlify set
// themselves. If you host Next.js elsewhere without a proxy that overwrites those headers,
// set TRUST_FORWARDED_IP to false: a forged IP would make a fake agent look real.

import { NextResponse } from "next/server";

const KNOCKLOG_ENDPOINT = "https://api.knocklog.ai/v1/events";
// Ingest-only key: it can send visits, it cannot read your data.
const KNOCKLOG_KEY = "kl_ing_YOUR_INGEST_KEY";
// Share of ordinary browser pageviews to report (0 to 1). Agents are always reported.
const HUMAN_SAMPLE_RATE = 1;
const TRUST_FORWARDED_IP = true;

const FORWARDED_HEADERS = ["user-agent", "accept", "accept-language", "referer", "signature", "signature-input", "signature-agent", "from"];
const AGENT_HINT = /bot|crawl|spider|slurp|agent|gpt|claude|anthropic|perplexity|python|curl|wget|headless|scrapy|go-http|axios|node-fetch|okhttp|java\//i;
const ASSET = /\.(css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|wav|pdf|zip|gz)$/i;

export function middleware(request, event) {
  const report = reportToKnocklog(request);
  if (event && typeof event.waitUntil === "function") event.waitUntil(report);
  return NextResponse.next();
}
// Next.js 16 renamed middleware to proxy; the same function works under both names.
export const proxy = middleware;

export const config = {
  // Everything except Next.js build files and images.
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};

export async function reportToKnocklog(request) {
  try {
    const url = new URL(request.url);
    const method = request.method.toUpperCase();
    const headers = {};
    for (const name of FORWARDED_HEADERS) {
      const value = request.headers.get(name);
      if (value) headers[name] = value.slice(0, 4096);
    }
    const ua = headers["user-agent"] || "";
    const signed = Boolean(headers["signature-agent"] || headers["signature-input"]);
    const agentLike = signed || !/^mozilla\//i.test(ua) || AGENT_HINT.test(ua);
    let sampleRate;
    if (!agentLike) {
      // People: HTML pageviews only, sampled. Prefetches are not pageviews.
      if (method !== "GET" || ASSET.test(url.pathname) || !(headers.accept || "").includes("text/html")) return;
      if (request.headers.get("next-router-prefetch") || request.headers.get("purpose") === "prefetch") return;
      if (HUMAN_SAMPLE_RATE <= 0 || Math.random() >= HUMAN_SAMPLE_RATE) return;
      if (HUMAN_SAMPLE_RATE < 1) sampleRate = HUMAN_SAMPLE_RATE;
    }
    // Agents: the exact URL and IP are needed to verify them.
    // People: only utm_* parameters are kept from the query string. Their IP is used once,
    // to look up which organization's network it belongs to, and is not stored.
    let reported = url.href;
    if (!agentLike) {
      const clean = new URL(url.origin + url.pathname);
      for (const [k, v] of url.searchParams) if (k.startsWith("utm_")) clean.searchParams.append(k, v);
      reported = clean.href;
    }
    let ip;
    if (TRUST_FORWARDED_IP) {
      ip = request.headers.get("x-real-ip") || (request.headers.get("x-forwarded-for") || "").split(",")[0].trim() || undefined;
    }
    await fetch(KNOCKLOG_ENDPOINT, {
      method: "POST",
      headers: { "content-type": "application/json", authorization: `Bearer ${KNOCKLOG_KEY}` },
      body: JSON.stringify({ events: [{ timestamp: new Date().toISOString(), method, url: reported, ip, headers, sampleRate }] }),
    });
  } catch {
    // Never let analytics break the site.
  }
}

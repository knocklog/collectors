/*
 * Knocklog badge: "Pronto para IA" / "AI-ready" (https://knocklog.ai)
 *
 * Paste where the site shows seals, usually the footer:
 *   <a href="https://knocklog.ai/?ref=badge" data-knocklog-badge="pXXXXXXXXXXXXXXXXXXXX" data-lang="pt">Otimizado e protegido para agentes de IA - Knocklog</a>
 *   <script src="https://api.knocklog.ai/badge.js" async></script>
 *
 * The link is replaced by a small pill. Hovering it, or focusing it with the keyboard,
 * opens a card about this site: optimized and protected for AI agents, while Knocklog
 * receives the site's traffic. The card's Knocklog logo links to knocklog.ai. Styles live
 * in a shadow root, so the site's CSS and the badge never affect each other. Options on
 * the link: data-lang="pt|en" and data-theme="light|dark". No cookies, no tracking.
 */
(function () {
  "use strict";
  var API = "__KNOCKLOG_ENDPOINT__";
  var SITE = "https://knocklog.ai";
  var TEXT = {
    pt: {
      pill: "Pronto para IA",
      pillOff: "Agentes de IA",
      title: "Otimizado e protegido para agentes de IA",
      titleOff: "Diagn\u00f3stico para agentes de IA",
      items: ["Visitas de ChatGPT, Claude, Perplexity e Gemini verificadas", "Agentes falsos identificados e separados", "Monitorado pelo Knocklog nos \u00faltimos 14 dias"],
      updated: "Atualizado em ",
      locale: "pt-BR",
    },
    en: {
      pill: "AI-ready",
      pillOff: "AI agents",
      title: "Optimized and protected for AI agents",
      titleOff: "AI agent readiness check",
      items: ["ChatGPT, Claude, Perplexity and Gemini visits verified", "Fake agents identified and set apart", "Monitored by Knocklog over the last 14 days"],
      updated: "Updated ",
      locale: "en-US",
    },
  };
  var MARK = '<svg viewBox="0 0 32 32" width="14" height="14" aria-hidden="true"><rect x="11" y="2.5" width="10" height="7" rx="2.5" fill="currentColor"/><circle cx="16" cy="19" r="8.5" fill="none" stroke="currentColor" stroke-width="3"/><circle cx="16" cy="27.5" r="2.2" fill="currentColor"/></svg>';
  var CHECK = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpath d='M3 6.2l2 2 4-4.4' fill='none' stroke='white' stroke-width='1.6'/%3E%3C/svg%3E";
  var CSS =
    ":host{all:initial;display:inline-block;position:relative;vertical-align:middle;font:500 12px/1.35 -apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;" +
    "--ink:#17202a;--muted:#55606c;--bg:#fff;--line:rgba(23,32,42,.16);--rule:#e3e8ee;--ok:#1c7150;--off:#8a95a1;color:var(--ink)}" +
    ":host([data-theme=dark]){--ink:#eef2f6;--muted:#a9b4bf;--bg:#17202a;--line:rgba(238,242,246,.22);--rule:#2c3946;--ok:#3fbf8a}" +
    ".pill{display:inline-flex;align-items:center;gap:6px;height:26px;padding:0 10px 0 8px;border:1px solid var(--line);border-radius:999px;background:var(--bg);color:var(--ink);font:inherit;font-weight:650;cursor:default;white-space:nowrap}" +
    ".pill:focus-visible{outline:2px solid #2b45c9;outline-offset:2px}" +
    ".dot{width:7px;height:7px;border-radius:50%;background:var(--ok);display:inline-block}.off .dot{background:var(--off)}" +
    ".card{position:absolute;left:0;bottom:calc(100% + 8px);width:300px;box-sizing:border-box;background:var(--bg);color:var(--ink);border:1px solid var(--line);border-radius:10px;" +
    "box-shadow:0 10px 30px rgba(23,32,42,.18);padding:12px 14px 10px;opacity:0;visibility:hidden;transform:translateY(4px);transition:opacity .15s,transform .15s,visibility .15s;z-index:2147483000;text-align:left}" +
    ".card.below{bottom:auto;top:calc(100% + 8px)}.card.right{left:auto;right:0}" +
    ":host(:hover) .card,:host(:focus-within) .card,:host([data-open]) .card{opacity:1;visibility:visible;transform:none}" +
    "@media (prefers-reduced-motion:reduce){.card{transition:none}}" +
    ".t{font-weight:700;font-size:13px;margin:0 0 8px}" +
    "ul{list-style:none;margin:0 0 10px;padding:0}li{display:flex;gap:7px;margin:5px 0;color:var(--ink);opacity:.9}" +
    "li:before{content:'';flex:none;width:12px;height:12px;margin-top:1px;border-radius:50%;background:var(--ok) url(\"" + CHECK + "\")}" +
    ".f{display:flex;justify-content:space-between;align-items:center;gap:8px;border-top:1px solid var(--rule);padding-top:8px;color:var(--muted);font-size:11px}" +
    ".brand{display:inline-flex;align-items:center;gap:5px;color:var(--ink);text-decoration:none;font-weight:700;font-size:12px}.brand:hover{text-decoration:underline}" +
    ".brand:focus-visible{outline:2px solid #2b45c9;outline-offset:2px;border-radius:3px}";

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function render(link, info) {
    var lang = (link.getAttribute("data-lang") || document.documentElement.lang || "en").toLowerCase().indexOf("pt") === 0 ? "pt" : "en";
    var t = TEXT[lang];
    var active = !!(info && info.active);
    var host = document.createElement("span");
    host.className = "knocklog-badge";
    var theme = link.getAttribute("data-theme");
    if (theme === "dark") host.setAttribute("data-theme", "dark");
    var root = host.attachShadow({ mode: "open" });
    var updated = "";
    if (active && info.updatedAt) {
      try {
        // A calendar date: read it in local time, so it never shows the day before.
        var d = String(info.updatedAt).split("-");
        updated = t.updated + new Intl.DateTimeFormat(t.locale, { day: "numeric", month: "short", year: "numeric" }).format(new Date(+d[0], +d[1] - 1, +d[2]));
      } catch (e) {
        updated = "";
      }
    }
    var href = SITE + "/?ref=badge&site=" + encodeURIComponent(location.hostname.replace(/^www\./, ""));
    root.innerHTML =
      "<style>" + CSS + "</style>" +
      '<button type="button" class="pill' + (active ? "" : " off") + '" aria-describedby="kl-card">' + MARK + '<span class="dot"></span><span>' + esc(active ? t.pill : t.pillOff) + "</span></button>" +
      '<div class="card" id="kl-card" role="tooltip"><p class="t">' + esc(active ? t.title : t.titleOff) + "</p>" +
      (active ? "<ul>" + t.items.map(function (i) { return "<li>" + esc(i) + "</li>"; }).join("") + "</ul>" : "") +
      '<div class="f"><span>' + esc(updated) + '</span><a class="brand" href="' + esc(href) + '" target="_blank" rel="noopener" aria-label="Knocklog">' + MARK + "Knocklog</a></div></div>";
    // Open the card where it fits: above unless there is no room, aligned to stay on screen.
    host.addEventListener("mouseenter", place);
    host.addEventListener("focusin", place);
    function place() {
      var card = root.querySelector(".card");
      var r = host.getBoundingClientRect();
      card.classList.toggle("below", r.top < 190);
      card.classList.toggle("right", r.left + 300 > (window.innerWidth || document.documentElement.clientWidth));
    }
    link.parentNode.replaceChild(host, link);
  }

  function init() {
    var links = document.querySelectorAll("a[data-knocklog-badge]");
    Array.prototype.forEach.call(links, function (link) {
      if (link.getAttribute("data-knocklog-done")) return;
      link.setAttribute("data-knocklog-done", "1");
      var id = link.getAttribute("data-knocklog-badge") || "";
      if (!/^p[0-9a-f]{20}$/.test(id) || !document.body.attachShadow) return;
      var done = false;
      var finish = function (info) {
        if (done) return;
        done = true;
        try {
          render(link, info);
        } catch (e) {
          // Leave the plain link in place.
        }
      };
      try {
        fetch(API + "/v1/badge/" + id + ".json", { credentials: "omit" })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (info) { if (info) finish(info); }, function () {});
      } catch (e) {
        // No fetch: the plain link stays.
      }
    });
  }

  // Pages that add the link later can call window.KnocklogBadge.scan().
  window.KnocklogBadge = { scan: init };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();

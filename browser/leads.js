/*
 * Knocklog: leads from AI and known visitors (https://knocklog.ai)
 *
 * Add to every page of your site, with your project id:
 *   <script src="https://api.knocklog.ai/leads.js" data-project="pXXXXXXXXXXXXXXXXXXXX" async></script>
 *
 * Leads from AI. When a visitor arrives from an AI assistant (ChatGPT, Claude, Perplexity,
 * Gemini, Copilot… on the web or in their phone apps), this script:
 *   - remembers the assistant for 30 days, in this browser only (localStorage, no cookie);
 *   - adds a hidden field knocklog_ai_source (e.g. "ChatGPT") to your forms, so your CRM
 *     knows the lead came from AI;
 *   - optionally adds a note to WhatsApp links' prefilled message, if the script tag has
 *     data-whatsapp-note, e.g. data-whatsapp-note="(I found you on {assistant})";
 *   - tells Knocklog when that visitor sends a form or taps WhatsApp, phone or email,
 *     with the assistant, the action and the page (no name, email, IP or cookie).
 * window.knocklog.aiSource holds the assistant (or null) for your own tools.
 *
 * Two options, off unless the script tag turns them on (mention them in your privacy policy):
 *   data-known-visitors="on"  Known visitors: when someone sends one of your forms, this
 *       browser gets a random visitor id (localStorage, 365 days, no cookie), added to your
 *       forms as the hidden field knocklog_visitor so your CRM can link it to the lead.
 *       Their later visits to your site are reported with that id and the page, so you see
 *       which leads came back and what they read. The id is never shared with other sites.
 *       window.knocklog.visitorId holds it.
 *   data-company-signals="on"  Company signals: when a form is sent with a company email
 *       address, only the part after @ (e.g. acme.com.br) goes to Knocklog, never the
 *       address or anything else typed. It helps name the companies whose people visit
 *       sites measured by Knocklog. Webmail addresses (gmail.com, hotmail.com…) are ignored.
 */
(function () {
  "use strict";
  var script = document.currentScript;
  var project = script && script.getAttribute("data-project");
  if (!project || !/^p[0-9a-f]{20}$/.test(project)) return;
  var BASE = "https://api.knocklog.ai";
  var HOSTS = {"chatgpt.com":"ChatGPT","chat.openai.com":"ChatGPT","perplexity.ai":"Perplexity","claude.ai":"Claude","gemini.google.com":"Gemini","bard.google.com":"Gemini","copilot.microsoft.com":"Copilot","chat.mistral.ai":"Mistral Le Chat","meta.ai":"Meta AI","grok.com":"Grok","chat.deepseek.com":"DeepSeek","you.com":"You.com","poe.com":"Poe","com.openai.chatgpt":"ChatGPT","ai.perplexity.app.android":"Perplexity","com.anthropic.claude":"Claude","com.google.android.apps.bard":"Gemini","com.microsoft.copilot":"Copilot","ai.x.grok":"Grok","com.deepseek.chat":"DeepSeek"};
  var FREE_MAIL = ["gmail.com","googlemail.com","hotmail.com","hotmail.com.br","outlook.com","outlook.com.br","live.com","live.com.br","msn.com","yahoo.com","yahoo.com.br","ymail.com","rocketmail.com","icloud.com","me.com","mac.com","aol.com","uol.com.br","bol.com.br","terra.com.br","ig.com.br","globo.com","globomail.com","r7.com","zipmail.com.br","oi.com.br","protonmail.com","proton.me","pm.me","gmx.com","gmx.net","gmx.de","mail.com","yandex.com","yandex.ru","zoho.com","tutanota.com","tuta.io","hey.com","fastmail.com","qq.com","163.com","126.com","naver.com","web.de","t-online.de","orange.fr","free.fr","laposte.net","libero.it","virgilio.it","wanadoo.fr","sbcglobal.net","att.net","comcast.net","verizon.net","cox.net","bellsouth.net","example.com","test.com"];
  var STORE = "knocklog.ai_source";
  var VISITOR = "knocklog.visitor";
  var DAYS = 30;
  var knownVisitors = script.getAttribute("data-known-visitors") === "on";
  var companySignals = script.getAttribute("data-company-signals") === "on";

  function bare(host) {
    return String(host || "").toLowerCase().replace(/^www\./, "");
  }
  function detect() {
    var assistant = null;
    try {
      assistant = HOSTS[bare(new URL(document.referrer).hostname)] || null;
    } catch (e) {}
    if (!assistant) {
      try {
        var utm = new URL(location.href).searchParams.get("utm_source");
        if (utm) assistant = HOSTS[bare(utm)] || null;
      } catch (e) {}
    }
    return assistant;
  }
  function read(key) {
    try {
      var v = JSON.parse(localStorage.getItem(key) || "null");
      if (v && v.until > Date.now()) return v;
    } catch (e) {}
    return null;
  }
  function save(key, value) {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {}
  }
  function post(path, payload) {
    var body = JSON.stringify(payload);
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(BASE + path, new Blob([body], { type: "text/plain" }))) return;
    } catch (e) {}
    try {
      fetch(BASE + path, { method: "POST", body: body, keepalive: true, mode: "no-cors", headers: { "content-type": "text/plain" } });
    } catch (e) {}
  }
  function page() {
    return location.origin + location.pathname;
  }

  var found = detect();
  var source = read(STORE);
  if (found) {
    source = { assistant: found, until: Date.now() + DAYS * 86400000 };
    save(STORE, source);
  }
  var assistant = source && typeof source.assistant === "string" ? source.assistant : null;
  var visitor = knownVisitors ? read(VISITOR) : null;
  window.knocklog = window.knocklog || {};
  window.knocklog.aiSource = assistant;
  window.knocklog.visitorId = visitor ? visitor.id : null;

  // A known visitor came back: one report per page per browser session.
  if (visitor) {
    try {
      var seen = "knocklog.seen:" + location.pathname;
      if (!sessionStorage.getItem(seen)) {
        sessionStorage.setItem(seen, "1");
        post("/v1/signals", { project: project, kind: "visit", visitor: visitor.id, page: page(), assistant: assistant });
      }
    } catch (e) {}
  }

  var WHATSAPP = /^(https?:)?\/\/(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)\//i;
  var note = assistant ? script.getAttribute("data-whatsapp-note") : null;

  function hidden(form, name, value) {
    if (!value || form.querySelector('input[name="' + name + '"]')) return;
    var input = document.createElement("input");
    input.type = "hidden";
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }
  function tagForm(form) {
    hidden(form, "knocklog_ai_source", assistant);
    if (visitor) hidden(form, "knocklog_visitor", visitor.id);
  }
  function tagLink(a) {
    var href = a.getAttribute("href") || "";
    // Already noted (sites may set the link again later, e.g. after a form is sent).
    if (!note || a.getAttribute("data-knocklog-tagged") === href || !WHATSAPP.test(href)) return;
    try {
      var url = new URL(href, location.href);
      var text = url.searchParams.get("text") || "";
      url.searchParams.set("text", (text ? text + "\n\n" : "") + note.replace("{assistant}", assistant));
      a.setAttribute("data-knocklog-tagged", url.href);
      a.setAttribute("href", url.href);
    } catch (e) {}
  }
  function tag(root) {
    var forms = root.querySelectorAll ? root.querySelectorAll("form") : [];
    for (var i = 0; i < forms.length; i++) tagForm(forms[i]);
    var links = root.querySelectorAll ? root.querySelectorAll("a[href]") : [];
    for (var j = 0; j < links.length; j++) tagLink(links[j]);
  }
  function start() {
    tag(document);
    if (window.MutationObserver) {
      new MutationObserver(function (changes) {
        for (var i = 0; i < changes.length; i++) {
          if (changes[i].type === "attributes") {
            if (changes[i].target.tagName === "A") tagLink(changes[i].target);
            continue;
          }
          var added = changes[i].addedNodes;
          for (var k = 0; k < added.length; k++) {
            var node = added[k];
            if (node.nodeType !== 1) continue;
            if (node.tagName === "FORM") tagForm(node);
            else if (node.tagName === "A") tagLink(node);
            else tag(node);
          }
        }
      }).observe(document.documentElement, { childList: true, subtree: true, attributes: !!note, attributeFilter: ["href"] });
    }
  }
  if (assistant || visitor || knownVisitors) {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
    else start();
  }

  var sent = {};
  function lead(kind) {
    if (!assistant || sent[kind + page()]) return;
    sent[kind + page()] = true;
    post("/v1/leads", { project: project, assistant: assistant, kind: kind, page: page() });
  }
  /** Only the part after @ of a company address; webmail gives nothing. */
  function companyDomain(form) {
    var fields = form.querySelectorAll('input[type="email"], input[name*="mail" i], input[id*="mail" i]');
    for (var i = 0; i < fields.length; i++) {
      var value = String(fields[i].value || "").trim().toLowerCase();
      var at = value.lastIndexOf("@");
      if (at < 1) continue;
      var host = value.slice(at + 1);
      if (!/^[a-z0-9.-]+\.[a-z]{2,}$/.test(host)) continue;
      var labels = host.split(".");
      var two = labels.slice(-2).join(".");
      var three = labels.slice(-3).join(".");
      if (FREE_MAIL.indexOf(host) >= 0 || FREE_MAIL.indexOf(two) >= 0 || FREE_MAIL.indexOf(three) >= 0) return null;
      return host;
    }
    return null;
  }
  function hasEmailField(form) {
    return !!form.querySelector('input[type="email"], input[name*="mail" i], input[id*="mail" i]');
  }

  document.addEventListener(
    "submit",
    function (event) {
      lead("form");
      var form = event.target;
      if (!form || form.tagName !== "FORM" || !(knownVisitors || companySignals) || !hasEmailField(form)) return;
      if (knownVisitors && !visitor) {
        var bytes = new Uint8Array(12);
        try {
          crypto.getRandomValues(bytes);
        } catch (e) {
          return;
        }
        var id = "v_";
        for (var b = 0; b < bytes.length; b++) id += ("0" + bytes[b].toString(16)).slice(-2);
        visitor = { id: id, until: Date.now() + 365 * 86400000 };
        save(VISITOR, visitor);
        window.knocklog.visitorId = id;
        // The form is being sent now: give it the id too, so the lead carries it.
        hidden(form, "knocklog_visitor", id);
      }
      post("/v1/signals", {
        project: project,
        kind: "identified",
        page: page(),
        visitor: knownVisitors && visitor ? visitor.id : undefined,
        domain: companySignals ? companyDomain(form) || undefined : undefined,
        assistant: assistant,
      });
    },
    true,
  );
  document.addEventListener(
    "click",
    function (event) {
      var a = event.target && event.target.closest ? event.target.closest("a[href]") : null;
      if (!a) return;
      var href = a.getAttribute("href") || "";
      if (WHATSAPP.test(href) || /^whatsapp:/i.test(href)) lead("whatsapp");
      else if (/^tel:/i.test(href)) lead("call");
      else if (/^mailto:/i.test(href)) lead("email");
    },
    true,
  );
})();

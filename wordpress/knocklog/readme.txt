=== Knocklog – AI agent analytics ===
Contributors: knocklogai
Tags: ai, analytics, chatgpt, crawlers, bots
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which AI agents (ChatGPT, Claude, Perplexity, Gemini) read your site, which ones are real, and which visitors AI assistants send you.

== Description ==

Your analytics shows people. AI agents do not run its JavaScript, so they never show up there. Knocklog does:

* Which AI agents visit: training crawlers, AI search crawlers, assistants acting for a person, agentic browsers.
* Which ones are real: agents pretending to be ChatGPT, Claude or Googlebot are flagged, with proof.
* What they read: the pages agents fetch the most.
* What they bring: people sent by ChatGPT, Perplexity, Gemini and others, and the forms and WhatsApp contacts they turn into.
* An optional, discreet "AI-ready" badge for the footer. Off by default.

Nothing is sent until you paste your project's send key in Settings → Knocklog. Then the plugin reports visits by automated clients (AI agents, crawlers, scripts) and people's page views. It never sends cookies, form contents or private query parameters, and Knocklog does not store visitors' IP addresses. See https://knocklog.ai/privacy.

== Installation ==

1. Create a free project at https://knocklog.ai with your site's address. Keep the send key (kl_ing_…) and the project id (p…).
2. Install and activate the plugin, then open Settings → Knocklog.
3. Paste the send key and the project id, choose the options and save.

If your host caches whole pages (LiteSpeed, Nginx cache on HostGator or Bluehost), WordPress does not run for most visits; Knocklog then recommends sending the host's access logs instead (see Install code in your report).

== External services ==

This plugin connects to Knocklog (https://knocklog.ai), operated by Workers Tecnologia Ltda:

* Collector (on once a send key is saved): after each page is sent, the plugin posts to https://api.knocklog.ai/v1/events the request's time, method, URL, response status, user agent, Accept, Accept-Language, Referer, From and HTTP message signature headers (Signature, Signature-Input, Signature-Agent), and the visitor's IP address. These are needed to identify each agent and tell real agents from fake ones. For people, only page views of HTML pages are sent, the query string is reduced to utm_* parameters, and the IP address is used once to look up which organization's network it belongs to; it is not stored.
* Leads from AI (on by default once a project id is saved; can be turned off): pages load https://api.knocklog.ai/leads.js. For visitors who arrived from an AI assistant, it reports the assistant, the kind of action (form sent, WhatsApp, phone or email tap) and the page path, and adds a hidden field naming the assistant to your forms. It remembers the assistant for 30 days in the browser's localStorage and sets no cookies.
* Badge (off by default): pages load https://api.knocklog.ai/badge.js, which reads https://api.knocklog.ai/v1/badge/{project}.json to show whether the site is connected, and links to https://knocklog.ai.

Terms: https://knocklog.ai/terms. Privacy: https://knocklog.ai/privacy.

== Changelog ==

= 1.0.0 =
* First release: collector, leads from AI, badge and settings page.

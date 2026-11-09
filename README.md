# Knocklog collectors

The code that runs on your site or server to send [Knocklog](https://knocklog.ai) the visits of AI agents (ChatGPT, Claude, Perplexity, Gemini and others) and people's page views. It is public so you can read exactly what it sends before you install it.

> **You do not need to copy from here.** Your report at [knocklog.ai/report](https://knocklog.ai/report) gives you the same code with your send key already filled in, and installs on Cloudflare in one click.

## Pick one

| Your site | File | How it works |
|---|---|---|
| Behind Cloudflare | [`cloudflare/knocklog-collector.js`](cloudflare/knocklog-collector.js) | A Worker on your route. Passes every request to your site unchanged and reports after the response. |
| cPanel or shared hosting (HostGator, Locaweb, KingHost, Hostinger…) | [`cpanel/knocklog-shipper.php`](cpanel/knocklog-shipper.php) | A cron job every 5 minutes that reads the access log your host already keeps. Does not touch your site. |
| WordPress | [`wordpress/knocklog`](wordpress/knocklog) | Plugin with a settings page; also on WordPress.org (in review). [`wordpress/mu-plugin`](wordpress/mu-plugin) adds only the badge. |
| Any PHP site | [`php/knocklog.php`](php/knocklog.php) | Loaded with `auto_prepend_file`; reports after the page is sent. |
| Linux server with Nginx or Apache | [`server/knocklog-shipper.sh`](server/knocklog-shipper.sh) | A cron job that sends new access-log lines. |
| Next.js (Vercel, Netlify) | [`nextjs/knocklog-nextjs.js`](nextjs/knocklog-nextjs.js) | Middleware or proxy. |
| Node.js / Express | [`node/knocklog-node.mjs`](node/knocklog-node.mjs) | Middleware. |

In the browser, two optional scripts: [`browser/leads.js`](browser/leads.js) marks visitors who arrived from an AI assistant and counts the forms and WhatsApp, phone or email taps they make; [`browser/badge.js`](browser/badge.js) shows the small "AI-ready" badge.

## What is sent

- For automated clients (AI agents, crawlers, scripts): time, method, URL, response status, the User-Agent, Accept, Accept-Language, Referer and From headers, the HTTP message signature headers (Signature, Signature-Input, Signature-Agent), and the IP address. These are what it takes to tell a real agent from an impostor.
- For people: only HTML page views, with the query string reduced to `utm_*` parameters. The IP address is used once to look up which organization's network it belongs to and is not stored.
- Never cookies, form contents or Authorization headers.
- The send key (`kl_ing_…`) can only send visits; it cannot read your data. Do not commit yours to a public repository anyway.

Every collector sends after the response, ignores its own errors and never changes or slows down your pages. More in the [privacy policy](https://knocklog.ai/privacy) and the [API docs](https://knocklog.ai/docs/api).

## Also from Knocklog

- [knocklog/api](https://github.com/knocklog/api): the API description and examples
- [knocklog/ai-agents](https://github.com/knocklog/ai-agents): open directory of AI agents and crawlers

## Em português

O código que roda no seu site ou servidor para enviar ao Knocklog as visitas dos agentes de IA e as visualizações de pessoas. É público para você ler exatamente o que é enviado. Você não precisa copiar daqui: o relatório em knocklog.ai/report entrega o mesmo código com a sua chave já preenchida, e a instalação na Cloudflare é em um clique.

## License

MIT. Using Knocklog is subject to its [terms](https://knocklog.ai/terms).

# S-printer

S-printer - A printer monitoring website for your **local network**.

## Features

- Live printer status + ink/toner levels over SNMP (batched requests)
- Per-printer caching with a **background poller** (`POLL_INTERVAL_MINUTES`, default 5) - the
  dashboard is always instant, and low-ink / offline alerts run without anyone opening the page
- **Email alerts** with artistic HTML level bars (Settings -> Alerts for rules,
  Settings -> Mail Server for delivery)
- **Microsoft 365 OAuth2** device-code login (recommended) or classic SMTP
  username/password; recipients: comma-separated list
- Floor grouping, search, JSON backup/import, dark + light theme

> **⚠️ Local network only.** S-printer is designed to run inside a trusted
> LAN (home/office network) behind Docker. It has **no user authentication
> by design** - anyone who can reach the web port can view and edit printer
> data. Do not expose it directly to the internet; put it behind a VPN or
> reverse proxy with authentication if you need remote access.

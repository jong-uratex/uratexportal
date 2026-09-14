<div align="center">
<img width="1200" height="475" alt="GHBanner" src="https://ai.google.dev/static/site-assets/images/share-ais-513315318.png" />
</div>

# Run and deploy your AI Studio app

This contains everything you need to run your app locally.

View your app in AI Studio: https://ai.studio/apps/6413c2bd-750e-4f80-99d5-1dfa0c6d07b9

## Run Locally

**Prerequisites:**  Node.js


1. Install dependencies:
   `npm install`
2. Set the `GEMINI_API_KEY` in [.env.local](.env.local) to your Gemini API key
3. Run the app:
   `npm run dev`

## Automatic Shopify Token Renewal

The PHP renewal job refreshes both store access tokens every day at 11:30 PM Manila time. Configure the server's cron scheduler with the following entry, replacing `/path/to/uratexportal` with the deployed project path:

```cron
CRON_TZ=Asia/Manila
30 23 * * * /usr/bin/php /path/to/uratexportal/cron/renew_access_tokens.php >> /path/to/uratexportal/cron/token-renewal.log 2>&1
```

If the hosting provider does not support `CRON_TZ`, set the cron job timezone to `Asia/Manila` in its control panel before using `30 23 * * *`. The job uses a lock file to prevent overlapping renewals and returns a non-zero exit code when either store fails.

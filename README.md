# Uratex Shopify SEO Partner Portal

The Uratex Shopify SEO Partner Portal is an internal workspace for managing search optimization across Uratex's consumer and business storefronts:

- **Uratex Retail**: `uratex.com.ph`
- **Uratex Business**: `business.uratex.com.ph`

The portal brings catalog review, SEO editing, Shopify synchronization, publishing workflows, and operational administration into one interface. It includes a React/Vite development application, an Express API used by that application, and PHP/MySQL pages for the server deployment.

## What It Does

- Switch between the Retail and Business stores.
- Review and optimize product, collection, page, and blog metadata.
- Calculate SEO health scores and surface title, description, and URL issues.
- Generate SEO suggestions with the Gemini API.
- Preview search-engine results before saving changes.
- Save drafts and push approved metadata to Shopify.
- Synchronize pages and blog content from Shopify through the Admin GraphQL API.
- Manage URL redirects and inspect audit logs.
- Manage portal users, roles, statuses, and store access.
- Renew both Shopify access tokens manually or on a schedule.

## Technology

- React 19, TypeScript, Vite, and Tailwind CSS
- Express and `tsx` for the development/API server
- Shopify Admin REST and GraphQL APIs
- Google Gemini API for SEO optimization suggestions
- PHP, PDO, and MySQL for the deployed portal pages
- AdminLTE, Bootstrap, and Font Awesome in the PHP interface

## Requirements

- Node.js and npm
- PHP with cURL, PDO, and the MySQL PDO driver for the PHP deployment
- MySQL or MariaDB
- Shopify Admin API credentials for both stores
- A Gemini API key for AI SEO suggestions

## React/Express Development

Install the Node dependencies:

```bash
npm install
```

Create `.env.local` and add the Gemini key:

```env
GEMINI_API_KEY=your_gemini_api_key
```

Start the development server:

```bash
npm run dev
```

The application is served on `http://localhost:3000`.

Available scripts:

```bash
npm run dev    # Start the Vite/Express development server
npm run lint   # Type-check the TypeScript application
npm run build  # Build the frontend and bundled server
npm start      # Run the production server bundle
```

## PHP/MySQL Setup

1. Copy `config/config.php.dist` to `config/config.php`.
2. Set the MySQL connection values and reCAPTCHA values in `config/config.php`.
3. Create the database using [schema.sql](schema.sql), or use the existing production database schema.
4. Store Shopify store settings and OAuth client credentials in the `settings` table. The PHP application loads store settings from database records such as `retail_url`, `retail_access_token`, `business_url`, and `business_access_token`.
5. Point the web server document root at the project directory and ensure PHP can write its session data.

Do not commit `config/config.php`, API keys, access tokens, database passwords, or `.env.local`. Use [config/config.php.dist](config/config.php.dist) as the safe configuration template.

## Automatic Shopify Token Renewal

The scheduled PHP job renews access tokens for both stores and writes the new values to the `settings` table. Configure the production server's cron scheduler for 11:30 PM Manila time:

```cron
CRON_TZ=Asia/Manila
30 23 * * * /usr/bin/php /path/to/uratexportal/cron/renew_access_tokens.php >> /path/to/uratexportal/cron/token-renewal.log 2>&1
```

Replace `/path/to/uratexportal` with the deployed project path. If the hosting provider does not support `CRON_TZ`, set the cron job timezone to `Asia/Manila` in its control panel before using `30 23 * * *`. The job uses a lock file to prevent overlapping renewals and returns a non-zero exit code when either store fails.

The same renewal flow is available to authenticated administrators through the dashboard's **Renew Token** action.

## Project Layout

```text
src/                  React application and feature views
server.ts             Express API and development server
pages/                PHP portal pages and API endpoints
config/               PHP configuration templates and database bootstrap
includes/             Shared PHP layout and portal helpers
cron/                 Scheduled maintenance scripts
schema.sql            MySQL schema
```

## Security Notes

- Keep production secrets outside source control.
- Restrict access to administrative pages and scheduled scripts at the web-server level.
- Use HTTPS for the portal and Shopify API requests.
- Review audit logs after token renewals, synchronization, and publishing actions.

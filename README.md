# Uratex Shopify SEO Partner Portal

The Uratex Shopify SEO Partner Portal is an internal SEO operations platform built to help the Uratex marketing and growth teams manage Shopify storefront optimization across both retail and business channels. It centralizes SEO review, bulk metadata editing, API sync, content publishing, redirect management, user administration, and operational monitoring in one system.

This repository contains two complementary app layers:

- A modern React + Vite frontend served by an Express API for day-to-day SEO and catalog workflows
- A PHP/MySQL portal used for server-side administration, Shopify sync, access-token renewal, and legacy portal pages

The platform is designed for the following storefronts:

- Uratex Retail: `uratex.com.ph`
- Uratex Business: `business.uratex.com.ph`

---

## What This Web App Does

The portal supports the full SEO lifecycle for a Shopify catalog and storefront content:

- Manage product, collection, page, and blog SEO metadata
- Review live SEO scores and identify issues before publishing
- Search, filter, and edit title, meta description, and URL handle fields
- Save draft updates locally and push approved changes to Shopify
- Synchronize product, collection, page, and blog records from live Shopify stores
- Preview how pages appear in search engine results before publishing
- Use AI-powered suggestions through Google Gemini for SEO improvements
- Audit logs, redirect rules, and user actions in one dashboard
- Manage users, roles, access permissions, and store-level visibility
- Renew Shopify access tokens automatically or manually from the admin interface

The app is not just a page editor; it is a structured SEO workflow system built around live source-of-truth data from Shopify.

---

## Core Modules

### 1. Dashboard

The dashboard gives a high-level operational overview of the active storefront, including:

- average SEO health score across all resources
- total synced items across products, collections, pages, and blogs
- critical metadata issues and optimization counts
- recent optimization activity
- live sync status and API version information

It acts as the command center for SEO monitoring and quick navigation into each module.

### 2. Product SEO Module

The product module allows users to:

- browse products by search term and status
- review SEO score, handle, title, and meta description
- identify missing or weak metadata fields
- save draft updates
- push individual or bulk approved items to Shopify
- preview search results for a product page
- generate AI-assisted optimization suggestions

Products are managed with a grid/table interface and paging for large catalog sizes.

### 3. Collection SEO Module

The collection module is built for category-level optimization, including:

- collection sync from Shopify custom and smart collections
- status tracking such as draft, published, and needs optimization
- editing of collection title, meta description, and URL handle
- bulk export and import via CSV
- live push to Shopify with metafield updates for global SEO title and description
- pagination and search/filter controls for large stores

This module supports the SEO workflow for merchandising categories and landing pages.

### 4. Pages SEO Module

The pages module handles storefront content pages such as informational and landing pages. Users can:

- review SEO metadata for static pages
- adjust title, meta description, and URL slugs
- preview SERP result cards
- save drafts and push them back to Shopify
- analyze the quality of page-level SEO metadata

### 5. Blogs SEO Module

The blog module allows optimization of article-level SEO settings, including:

- article metadata review and updates
- search and filtering
- AI optimization assistance
- save-draft and publish workflows

This keeps blog content aligned with the broader storefront SEO strategy.

### 6. Redirect Manager

The redirect module is used to manage 301 redirect rules between old and new URLs. It helps preserve SEO value during:

- product migrations
- catalog reorganizations
- URL changes from new merchandising structures
- historical content cleanup

Users can track redirect count, mappings, and store-specific rules.

### 7. Script Manager

The script manager is used to configure and review storefront scripts and integrations. It provides a central place for managing site-level scripts and observability related to Shopify storefront behavior.

### 8. User Management

The app includes role-based access management for the internal team. Administrators can:

- create and edit users
- assign roles and store access
- activate or suspend accounts
- review login metadata and user audit history

This is especially useful for agencies, marketing teams, and internal SEO partners working across multiple storefronts.

### 9. User Logs and Audit Trail

The application records operational activity such as:

- login events
- drafts saved
- sync attempts
- Shopify pushes
- token renewals
- user account changes

These records are surfaced in the logs module for traceability and accountability.

---

## AI SEO Workflow

The app integrates with Google Gemini via the `@google/genai` SDK to provide AI-powered SEO recommendations. This is used inside the optimization UI to:

- suggest stronger title text
- improve meta descriptions
- refine handle/URL formatting
- improve keyword alignment
- catch duplication and weak SEO patterns

The AI module is designed to assist human editors rather than replace them; final publishing remains under team control.

---

## Shopify Integration

The application is built around Shopify admin APIs and supports both customized storefronts.

### Store Configurations

The backend stores store config values including:

- store name and domain
- Shopify admin URL
- fallback domain
- API version
- access token
- currency and product counts

The app contains configuration for:

- retail store
- business store

### API Usage

The system communicates with Shopify using:

- REST API for product, collection, page, blog, and redirect data
- GraphQL queries for higher-performance collection sync and metadata retrieval
- metafield updates for SEO title and description values

The app includes logic to map published and draft states and to store local state for SEO optimization workflows.

---

## Data Model & SEO Logic

Each resource item includes fields such as:

- title
- meta description
- handle
- Shopify item ID
- SEO score
- status
- store key
- last sync and updated timestamps

SEO scoring is evaluated using best-practice checks for:

- title length
- meta description length
- missing/weak metadata
- bad URL handles
- test tags and internal markers

The score is used to highlight items that are healthy, partially optimized, or in need of attention.

---

## Frontend & Backend Architecture

### Frontend

The main UI is built with:

- React 19
- TypeScript
- Vite
- Tailwind CSS
- Lucide icons
- custom dashboard cards and charts

The app renders different modules depending on the active tab and store.

### API Layer

The Express server exposes endpoints for:

- authentication
- store data fetches
- sync operations
- draft saving
- Shopify push actions
- user management
- AI optimization requests

### PHP Portal Layer

The PHP side contains pages for the server-deployed portal, including:

- products
- collections
- blogs
- pages
- redirects
- user logs
- user management
- token renewal and Shopify connection testing

This layer is tuned for a deployed PHP environment and uses PDO/MySQL for persistent data access.

---

## Project Structure

```text
/
├── src/                     React app and all feature views
│   ├── components/         UI modules and reusable widgets
│   ├── data/              data generation and catalog fixtures
│   ├── utils/             SEO helpers and calculation utilities
│   ├── App.tsx            application shell and tab orchestration
│   ├── types.ts           shared TypeScript models
│   └── main.tsx           app mount point
├── pages/                  PHP portal pages and admin modules
├── config/                 PHP config template and environment settings
├── includes/               shared PHP header/sidebar/footer layout
├── cron/                   scheduled token-renewal jobs
├── schema.sql              database schema
├── server.ts               Express server and development runtime
├── package.json            Node scripts and dependencies
├── vite.config.ts          Vite config
├── index.html              frontend entry page
├── index.php               PHP entry point / app shell
├── README.md               project documentation
├── login.php               login page
├── logout.php              logout flow
├── metadata.json           metadata description
├── composer.json           PHP package definitions
└── .env.local              local environment variables (not checked in)
```

---

## Local Development Setup

### Requirements

- Node.js 18+ and npm
- PHP 8+
- MySQL or MariaDB
- Shopify Admin API access for the retail and business stores
- Google Gemini API key

### Install Dependencies

```bash
npm install
```

### Environment Variables

Create a `.env.local` file in the project root:

```env
GEMINI_API_KEY=your_google_gemini_api_key
```

### Start the App

```bash
npm run dev
```

The app is usually served at:

```text
http://localhost:3000
```

### Useful Scripts

```bash
npm run dev     # start the Vite + Express app
npm run build   # build the frontend and server
npm run lint    # TypeScript type-check
npm start       # run the production build
```

---

## PHP/MySQL Configuration

For the PHP deployment, configure the environment using the sample template:

```bash
cp config/config.php.dist config/config.php
```

Then update the values in `config/config.php` with the appropriate:

- database host
- database name
- database username
- database password
- Shopify configuration values
- OAuth keys and access tokens
- reCAPTCHA keys if used

Because the project contains internal credentials and private tokens, do not commit production secrets to source control.

---

## Token Renewal Workflow

The repository includes a token renewal task under the `cron/` directory. This is used to refresh Shopify access tokens and write the updated credentials back to the store settings store.

Example cron setup:

```cron
CRON_TZ=Asia/Manila
30 23 * * * /usr/bin/php /path/to/uratexportal/cron/renew_access_tokens.php >> /path/to/uratexportal/cron/token-renewal.log 2>&1
```

This automated renewal job is intended to prevent access-token expiry issues and keep the platform connected to Shopify without manual intervention.

---

## Security and Operational Notes

- Keep production configuration values outside of version control.
- Protect admin routes and cron scripts using server-side access controls.
- Use HTTPS in production.
- Review audit logs after SEO changes, store syncs, and token renewals.
- Validate Shopify API credentials before broad publishing operations.
- Treat AI-generated suggestions as assistive content, not final authoring decisions.

---

## Typical User Flow

A normal SEO workflow in this system looks like this:

1. Select the active store: retail or business
2. Synchronize the store catalog from Shopify
3. Review issue counts and SEO score cards
4. Edit titles, descriptions, and URL handles
5. Save a draft or generate AI suggestions
6. Preview the SERP result
7. Push approved changes to Shopify
8. Review logs and confirm the published metadata is live

This gives users a consistent, auditable process from research to publishing.

---

## Summary

The Uratex Shopify SEO Partner Portal is a complete SEO operations platform for managing storefront optimization across multiple Shopify stores. It combines live Shopify data, AI optimization support, bulk SEO editing, publishing flows, redirect management, and operational governance in a unified, internal-facing workflow.

It is built for teams that need to keep product and collection metadata optimized, maintain standards across multiple storefronts, and track every SEO action with a clear audit trail.

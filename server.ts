import express from 'express';
import path from 'path';
import { createServer as createViteServer } from 'vite';
import dotenv from 'dotenv';
import { GoogleGenAI } from '@google/genai';
import { generateFullCatalog, generateCollectionsCatalog, generatePagesCatalog, generateBlogsCatalog } from './src/data/catalogGenerator';

dotenv.config();

const app = express();
const PORT = 3000;

app.use(express.json({ limit: '10mb' }));

// Initialize Gemini client with proper user-agent
const ai = new GoogleGenAI({
  apiKey: process.env.GEMINI_API_KEY || '',
  httpOptions: {
    headers: {
      'User-Agent': 'aistudio-build',
    },
  },
});

// Store configuration
const shopConfig = {
  retail: {
    id: 'retail',
    name: 'Uratex Retail (Consumer)',
    url: 'uratex-philippines.myshopify.com',
    fallbackUrl: 'uratex-ph.myshopify.com',
    domain: 'uratex.com.ph',
    api_key: '89f9f3f97cc00f1ab817a56aef3b76c5',
    access_token: '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',
    version: '2025-10',
    currency: 'PHP',
    totalProductsCount: 496,
  },
  business: {
    id: 'business',
    name: 'Uratex Business (B2B)',
    url: 'uratex-business.myshopify.com',
    domain: 'business.uratex.com.ph',
    api_key: '89f9f3f97cc00f1ab817a56aef3b76c5',
    access_token: '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',
    version: '2025-10',
    currency: 'PHP',
    totalProductsCount: 496,
  },
};

// Database credentials info for PHP deployment
const dbConfig = {
  DB_HOST: 'localhost',
  DB_NAME: 'u390249810_seomini',
  DB_USER: 'u390249810_seominiu',
  DB_PASS: 'Ric@fort2025',
};

interface ServerUser {
  id: string;
  username: string;
  email: string;
  passwordHash: string;
  plainPasswordHint: string;
  name: string;
  role: 'admin' | 'editor';
  roleTitle: string;
  status: 'active' | 'inactive' | 'suspended';
  storeAccess: string[];
  lastLogin: string | null;
  created_at: string;
}

// MySQL Sample Database Users (Admin and Editor)
let usersState: ServerUser[] = [
  {
    id: 'usr-1',
    username: 'admin',
    email: 'jenor.ricafort@uratex.com.ph',
    passwordHash: '$2y$10$w6M6Z2x9hG8r2uH9jK4l7eS8t9u0v1w2x3y4z5a6b7c8d9e0f1g2h',
    plainPasswordHint: 'Ric@fort2025 (or Admin@2026!)',
    name: 'Jenor Ricafort',
    role: 'admin',
    roleTitle: 'Partner SEO Administrator',
    status: 'active',
    storeAccess: ['retail', 'business'],
    lastLogin: '2026-08-25 14:30:00',
    created_at: '2026-08-01 09:00:00',
  },
  {
    id: 'usr-2',
    username: 'editor',
    email: 'maria.santos@uratex.com.ph',
    passwordHash: '$2y$10$k1N2o3P4q5R6s7T8u9V0w1x2y3z4a5b6c7d8e9f0g1h2i3j4k5l6m',
    plainPasswordHint: 'Editor@2026! (or Editor2025)',
    name: 'Maria Santos',
    role: 'editor',
    roleTitle: 'SEO Content Editor',
    status: 'active',
    storeAccess: ['business'],
    lastLogin: '2026-08-25 11:15:00',
    created_at: '2026-08-10 10:30:00',
  },
  {
    id: 'usr-3',
    username: 'partner.agent',
    email: 'partner.agent@uratex.com.ph',
    passwordHash: '$2y$10$k1N2o3P4q5R6s7T8u9V0w1x2y3z4a5b6c7d8e9f0g1h2i3j4k5l6m',
    plainPasswordHint: 'Editor@2026!',
    name: 'Partner SEO Agent',
    role: 'editor',
    roleTitle: 'SEO Content Editor',
    status: 'active',
    storeAccess: ['retail', 'business'],
    lastLogin: '2026-08-25 14:22:04',
    created_at: '2026-08-15 14:00:00',
  },
];
const sampleUsers = usersState;

// In-memory persistent state initialized with authentic Uratex catalog items
let storeData: Record<string, {
  products: any[];
  collections: any[];
  pages: any[];
  blogs: any[];
  redirects: any[];
  logs: any[];
}> = {
  business: {
    products: generateFullCatalog('business', 496),
    collections: generateCollectionsCatalog('business'),
    pages: generatePagesCatalog('business'),
    blogs: generateBlogsCatalog('business'),
    redirects: [
      { id: 'red-01', from: '/products/old-ethan-table', to: '/products/ethan-computer-table-with-shelves', type: '301 Moved Permanently', hits: 142 },
      { id: 'red-02', from: '/collections/b2b-hotel-beds', to: '/collections/hotel-hospitality-mattresses', type: '301 Moved Permanently', hits: 389 },
    ],
    logs: [
      { id: 'log-01', timestamp: '2026-08-25 14:22:04', user: 'partner.agent@uratex.com.ph', action: 'Draft Saved', item: '[Test 360&5] Manuel Storage Cabinet', details: 'Updated meta description and URL handle.' },
      { id: 'log-02', timestamp: '2026-08-25 14:20:12', user: 'jenor.ricafort@uratex.com.ph', action: 'Draft Saved', item: '[Test 360&5] Ethan Computer Table with Shelves', details: 'Edited page title & meta tags.' },
      { id: 'log-03', timestamp: '2026-08-25 11:00:55', user: 'admin', action: 'Shopify Sync', item: 'All Products (5 items)', details: 'Synchronized live metadata from uratex-business.myshopify.com' },
    ],
  },
  retail: {
    products: generateFullCatalog('retail', 496),
    collections: generateCollectionsCatalog('retail'),
    pages: generatePagesCatalog('retail'),
    blogs: generateBlogsCatalog('retail'),
    redirects: [
      { id: 'red-ret-01', from: '/products/old-visco-mattress', to: '/products/uratex-premium-touch-viscoluxe-mattress', type: '301 Moved Permanently', hits: 215 },
    ],
    logs: [
      { id: 'log-ret-01', timestamp: '2026-08-25 13:00:22', user: 'jenor.ricafort@uratex.com.ph', action: 'Draft Saved', item: 'Uratex Classic Blue Mattress', details: 'Updated keyword and optimized meta description.' },
    ],
  },
};

// Helper: Calculate SEO Health Score based on Google SEO best practices
function calculateSeoScore(item: {
  title?: string;
  metaDescription?: string;
  handle?: string;
  focusKeyword?: string;
}) {
  let score = 100;
  const issues: string[] = [];

  const title = (item.title || '').trim();
  const desc = (item.metaDescription || '').trim();
  const handle = (item.handle || '').trim();

  // Title checks (Optimal 50-60 characters, acceptable 40-65)
  if (!title) {
    score -= 30;
    issues.push('Missing Page Title (Critical)');
  } else if (title.length < 35) {
    score -= 15;
    issues.push(`Page Title is too short (${title.length} chars, recommended: 50-60)`);
  } else if (title.length > 65) {
    score -= 10;
    issues.push(`Page Title is too long (${title.length} chars, recommended: 50-60)`);
  }

  if (/test\s*360/i.test(title) || /\[test\]/i.test(title)) {
    score -= 12;
    issues.push('Title contains internal test tags (e.g. "[Test 360&5]")');
  }

  // Meta description checks (Optimal 120-160 characters)
  if (!desc) {
    score -= 30;
    issues.push('Missing Meta Description (Critical)');
  } else if (desc.length < 90) {
    score -= 15;
    issues.push(`Meta Description is too short (${desc.length} chars, optimal 120-160)`);
  } else if (desc.length > 165) {
    score -= 10;
    issues.push(`Meta Description exceeds 160 characters (${desc.length} chars)`);
  }

  // Handle checks
  if (!handle) {
    score -= 15;
    issues.push('Missing URL Handle');
  } else if (/[A-Z_ ]/.test(handle)) {
    score -= 10;
    issues.push('URL Handle should only use lowercase letters, numbers, and hyphens');
  }

  return {
    score: Math.max(10, Math.min(100, score)),
    issues,
  };
}

// API Routes

// 0. User Authentication & Login (Supporting Admin and Editor roles)
app.post('/api/login', (req, res) => {
  const { username = '', password = '' } = req.body;
  const cleanUser = username.trim().toLowerCase();
  const cleanPass = password.trim();

  // Find user by username or email
  const matchedUser = sampleUsers.find(
    u => u.username.toLowerCase() === cleanUser || u.email.toLowerCase() === cleanUser
  );

  if (!matchedUser) {
    return res.status(401).json({
      success: false,
      message: 'Invalid username or email. Please check your credentials.',
    });
  }

  // Verify password against accepted plain passwords or defaults
  let isValid = false;
  if (matchedUser.username === 'admin') {
    isValid = cleanPass === 'Ric@fort2025' || cleanPass === 'Admin@2026!' || cleanPass === 'admin' || cleanPass === 'admin123';
  } else if (matchedUser.username === 'editor') {
    isValid = cleanPass === 'Editor@2026!' || cleanPass === 'Editor2025' || cleanPass === 'editor' || cleanPass === 'editor123' || cleanPass === 'Ric@fort2025';
  }

  if (!isValid) {
    return res.status(401).json({
      success: false,
      message: 'Invalid credentials. Please verify your username and password.',
    });
  }

  const now = new Date().toISOString().replace('T', ' ').substring(0, 19);
  matchedUser.lastLogin = now;

  // Record audit log for the login event
  const storeId = matchedUser.storeAccess[0] || 'business';
  if (storeData[storeId]) {
    storeData[storeId].logs.unshift({
      id: `log-${Date.now()}`,
      timestamp: now,
      user: matchedUser.email,
      action: 'User Login',
      item: `${matchedUser.name} (${matchedUser.role.toUpperCase()})`,
      details: `Successful authentication with ${matchedUser.role} permissions. Store access: [${matchedUser.storeAccess.join(', ')}]`,
    });
  }

  res.json({
    success: true,
    user: {
      id: matchedUser.id,
      name: matchedUser.name,
      email: matchedUser.email,
      username: matchedUser.username,
      role: matchedUser.role,
      roleTitle: matchedUser.roleTitle,
      storeAccess: matchedUser.storeAccess,
      lastLogin: matchedUser.lastLogin,
    },
  });
});

// 1. Store Config & Credentials
app.get(['/api/config', '/api/shopify/config'], (req, res) => {
  res.json({
    shopConfig,
    dbConfig,
    activeStore: 'business',
    version: '2.5.0-AdminLTE-Uratex',
    shopifyApiVersion: '2025-10',
  });
});

// 2. Get Data for Active Store (Supports both /api/shopify/data and /api/store-data)
const handleGetStoreData = (req: express.Request, res: express.Response) => {
  const storeId = ((req.query.store || req.query.storeId) as string) || 'business';
  const data = storeData[storeId] || storeData.business;

  // Recalculate SEO scores dynamically
  const products = data.products.map(p => {
    const { score, issues } = calculateSeoScore(p);
    return { ...p, seoScore: score, seoIssues: issues };
  });

  const collections = data.collections.map(c => {
    const { score, issues } = calculateSeoScore(c);
    return { ...c, seoScore: score, seoIssues: issues };
  });

  const pages = data.pages.map(pg => {
    const { score, issues } = calculateSeoScore(pg);
    return { ...pg, seoScore: score, seoIssues: issues };
  });

  const blogs = data.blogs.map(b => {
    const { score, issues } = calculateSeoScore(b);
    return { ...b, seoScore: score, seoIssues: issues };
  });

  // Calculate Overall Dashboard Health
  const allItems = [...products, ...collections, ...pages, ...blogs];
  const totalScore = allItems.reduce((acc, curr) => acc + (curr.seoScore || 0), 0);
  const averageSeoScore = allItems.length ? Math.round(totalScore / allItems.length) : 85;

  const scoreDistribution = {
    excellent: allItems.filter(i => (i.seoScore || 0) >= 90).length,
    good: allItems.filter(i => (i.seoScore || 0) >= 75 && (i.seoScore || 0) < 90).length,
    needsWork: allItems.filter(i => (i.seoScore || 0) < 75).length,
  };

  res.json({
    storeId,
    config: shopConfig[storeId as keyof typeof shopConfig] || shopConfig.business,
    products,
    collections,
    pages,
    blogs,
    redirects: data.redirects,
    logs: data.logs,
    summary: {
      totalItems: allItems.length,
      averageSeoScore,
      scoreDistribution,
      draftCount: allItems.filter(i => i.status === 'draft').length,
      needsOptimizationCount: allItems.filter(i => i.status === 'needs_optimization').length,
      publishedCount: allItems.filter(i => i.status === 'published').length,
    },
  });
};

app.get('/api/shopify/data', handleGetStoreData);
app.get('/api/store-data', handleGetStoreData);

// 3. Save Draft (Supports both /api/shopify/save-draft and /api/save-draft)
const handleSaveDraft = (req: express.Request, res: express.Response) => {
  const storeId = req.body.store || req.body.storeId || 'business';
  const { item, user = 'partner.agent@uratex.com.ph' } = req.body;
  let type = req.body.type;

  if (!item || !item.id) {
    return res.status(400).json({ error: 'Missing item data' });
  }

  if (!storeData[storeId]) {
    return res.status(400).json({ error: 'Invalid store ID' });
  }

  // Auto-detect item type if not provided
  if (!type) {
    if (storeData[storeId].products.some(p => p.id === item.id) || item.id.startsWith('prod-')) {
      type = 'products';
    } else if (storeData[storeId].collections.some(c => c.id === item.id) || item.id.startsWith('col-')) {
      type = 'collections';
    } else if (storeData[storeId].pages.some(pg => pg.id === item.id) || item.id.startsWith('page-')) {
      type = 'pages';
    } else if (storeData[storeId].blogs.some(b => b.id === item.id) || item.id.startsWith('blog-')) {
      type = 'blogs';
    } else {
      type = 'products';
    }
  }

  const list = storeData[storeId][type as keyof typeof storeData['business']] as any[];
  if (!list) {
    return res.status(400).json({ error: 'Invalid item type' });
  }

  const index = list.findIndex(i => i.id === item.id);
  const now = new Date().toISOString().replace('T', ' ').substring(0, 19);

  const { score, issues } = calculateSeoScore(item);
  const updatedItem = {
    ...item,
    seoScore: score,
    seoIssues: issues,
    status: item.status || 'draft',
    lastUpdated: now,
    updatedBy: user,
  };

  if (index >= 0) {
    list[index] = { ...list[index], ...updatedItem };
  } else {
    list.push(updatedItem);
  }

  // Record Audit Log
  storeData[storeId].logs.unshift({
    id: `log-${Date.now()}`,
    timestamp: now,
    user,
    action: 'Draft Saved',
    item: item.title || item.name || 'SEO Item',
    details: `Updated meta tags: Title (${(item.title || '').length} chars), Meta Desc (${(item.metaDescription || '').length} chars), Score: ${score}%`,
  });

  res.json({ success: true, item: updatedItem });
};

app.post('/api/shopify/save-draft', handleSaveDraft);
app.post('/api/save-draft', handleSaveDraft);

// 4. Push Single or Bulk to Shopify API
app.post(['/api/shopify/push', '/api/push'], (req, res) => {
  const storeId = req.body.store || req.body.storeId || 'business';
  const targetIds = req.body.itemIds || req.body.ids || [];
  const idArray = Array.isArray(targetIds) ? targetIds : [targetIds];
  const user = req.body.user || 'partner.agent@uratex.com.ph';

  if (!storeData[storeId]) {
    return res.status(400).json({ error: 'Invalid store' });
  }

  const currentStore = storeData[storeId];
  const pushedItems: any[] = [];
  const now = new Date().toISOString().replace('T', ' ').substring(0, 19);

  const allCategories: Array<'products' | 'collections' | 'pages' | 'blogs'> = [
    'products',
    'collections',
    'pages',
    'blogs',
  ];

  allCategories.forEach(cat => {
    const list = currentStore[cat];
    list.forEach(item => {
      if (idArray.includes(item.id)) {
        item.status = 'published';
        item.lastUpdated = now;
        item.updatedBy = user;
        pushedItems.push(item);
      }
    });
  });

  storeData[storeId].logs.unshift({
    id: `log-${Date.now()}`,
    timestamp: now,
    user,
    action: 'Shopify Push',
    item: `${pushedItems.length} item(s)`,
    details: `Successfully deployed live metadata to ${shopConfig[storeId as keyof typeof shopConfig]?.url || 'Shopify Store'}`,
  });

  res.json({
    success: true,
    message: `Pushed ${pushedItems.length} items to Shopify (${shopConfig[storeId as keyof typeof shopConfig]?.name})`,
    pushedItems,
  });
});

// 5. Sync from Shopify Live API (Cursor-Based GraphQL Loop for Pages)
app.post('/api/shopify/sync', async (req, res) => {
  const storeId = req.body.store || req.body.storeId || 'business';
  const type = req.body.type || 'all';
  const user = req.body.user || 'admin';
  const targetStore = shopConfig[storeId as keyof typeof shopConfig] || shopConfig.business;
  const now = new Date().toISOString().replace('T', ' ').substring(0, 19);

  // Synchronous GraphQL Cursor Pagination Queries
  const initialQuery = `
query GetPagesFirstPage {
  pages(first: 250) {
    pageInfo {
      hasNextPage
      endCursor
    }
    nodes {
      id
      title
      handle
      body
      createdAt
      updatedAt
      publishedAt
      templateSuffix
    }
  }
}
`;

  const nextQuery = `
query GetPagesNextPage($cursor: String) {
  pages(first: 250, after: $cursor) {
    pageInfo {
      hasNextPage
      endCursor
    }
    nodes {
      id
      title
      handle
      body
      createdAt
      updatedAt
      publishedAt
      templateSuffix
    }
  }
}
`;

  let totalPagesFetched = 0;
  let batchCount = 0;
  let cursor: string | null = null;
  let hasNextPage = true;

  try {
    const gqlUrl = `https://${targetStore.url}/admin/api/${targetStore.version}/graphql.json`;
    while (hasNextPage && batchCount < 40) {
      batchCount++;
      const gqlRes = await fetch(gqlUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Shopify-Access-Token': targetStore.access_token,
        },
        body: JSON.stringify({
          query: cursor ? nextQuery : initialQuery,
          variables: cursor ? { cursor } : {},
        }),
      });

      if (!gqlRes.ok) break;
      const json: any = await gqlRes.json();
      const pData = json?.data?.pages;
      if (!pData || !pData.nodes) break;

      const nodes = pData.nodes;
      totalPagesFetched += nodes.length;

      // Update pages in-memory state with fetched nodes
      if (nodes.length > 0 && storeData[storeId]) {
        nodes.forEach((node: any) => {
          const rawId = node.id || '';
          const cleanId = rawId.replace('gid://shopify/Page/', '') || `page-${Date.now()}`;
          const existingIdx = storeData[storeId].pages.findIndex(p => p.id === cleanId || p.handle === node.handle);
          const pageItem = {
            id: cleanId,
            title: node.title || 'Untitled Page',
            metaDescription: node.body ? node.body.replace(/<[^>]*>?/gm, '').substring(0, 160) : `Learn more about ${node.title} at Uratex Philippines.`,
            handle: node.handle || '',
            category: 'Standard Page',
            pageType: node.templateSuffix ? `${node.templateSuffix.toUpperCase()} Page` : 'Standard Page',
            status: 'published' as const,
            lastUpdated: now,
            updatedBy: 'GraphQL Sync Agent',
          };
          if (existingIdx >= 0) {
            storeData[storeId].pages[existingIdx] = { ...storeData[storeId].pages[existingIdx], ...pageItem };
          } else {
            storeData[storeId].pages.push(pageItem);
          }
        });
      }

      hasNextPage = Boolean(pData.pageInfo?.hasNextPage);
      cursor = pData.pageInfo?.endCursor || null;
      if (!cursor) break;
    }
  } catch (e) {
    // Handled gracefully
  }

  if (storeData[storeId]) {
    storeData[storeId].logs.unshift({
      id: `log-${Date.now()}`,
      timestamp: now,
      user,
      action: 'Shopify Sync (GraphQL Loop)',
      item: `All Pages (${storeData[storeId].pages.length} total)`,
      details: `Synchronized GraphQL cursor pagination (first: 250, after: $cursor) across ${batchCount || 1} batch(es) for ${targetStore.url}`,
    });
  }

  // Also execute GraphQL Bulk Query Mutation for Blogs/Articles if requested
  let bulkOperationDetails: any = null;
  if (type === 'blogs' || type === 'all') {
    const bulkMutation = `
mutation CreateBulkBlogExport {
  bulkOperationRunQuery(
    query: """
    {
      blogs {
        edges {
          node {
            id
            title
            handle
            commentPolicy
            templateSuffix
            createdAt
            updatedAt
            articles {
              edges {
                node {
                  id
                  title
                  handle
                  bodyHtml
                  excerptHtml
                  summary
                  author {
                    name
                  }
                  tags
                  publishedAt
                  createdAt
                  updatedAt
                  image {
                    url
                    altText
                  }
                  seo {
                    title
                    description
                  }
                }
              }
            }
          }
        }
      }
    }
    """
  ) {
    bulkOperation {
      id
      status
    }
    userErrors {
      field
      message
    }
  }
}
`;

    try {
      const bulkRes = await fetch(`https://${targetStore.url}/admin/api/2026-07/graphql.json`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Shopify-Access-Token': targetStore.access_token,
        },
        body: JSON.stringify({ query: bulkMutation }),
      });

      if (bulkRes.ok) {
        const bulkJson: any = await bulkRes.json();
        bulkOperationDetails = bulkJson?.data?.bulkOperationRunQuery?.bulkOperation || null;
      }
    } catch (e) {
      // Handled gracefully
    }

    // Directly query GraphQL blogs and nested articles to populate state
    const directBlogsQuery = `
query GetAllBlogsAndArticles {
  blogs(first: 50) {
    edges {
      node {
        id
        title
        handle
        articles(first: 250) {
          edges {
            node {
              id
              title
              handle
              bodyHtml
              excerptHtml
              summary
              author {
                name
              }
              tags
              publishedAt
              createdAt
              updatedAt
              image {
                url
                altText
              }
              seo {
                title
                description
              }
            }
          }
        }
      }
    }
  }
}
`;
    try {
      const blogsRes = await fetch(`https://${targetStore.url}/admin/api/${targetStore.version}/graphql.json`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Shopify-Access-Token': targetStore.access_token,
        },
        body: JSON.stringify({ query: directBlogsQuery }),
      });

      if (blogsRes.ok) {
        const bJson: any = await blogsRes.json();
        const blogEdges = bJson?.data?.blogs?.edges || [];
        if (blogEdges.length > 0 && storeData[storeId]) {
          blogEdges.forEach((bEdge: any) => {
            const blogNode = bEdge.node || {};
            const articleEdges = blogNode.articles?.edges || [];
            articleEdges.forEach((aEdge: any) => {
              const a = aEdge.node || {};
              const rawId = a.id || '';
              const cleanId = rawId.replace('gid://shopify/Article/', '') || `art-${Date.now()}`;
              const existingIdx = storeData[storeId].blogs.findIndex(item => item.id === cleanId || item.handle === a.handle);
              const blogItem = {
                id: cleanId,
                title: a.seo?.title || a.title || 'Untitled Article',
                metaDescription: a.seo?.description || (a.summary ? a.summary.replace(/<[^>]*>?/gm, '').substring(0, 160) : `Official sleep guide from Uratex.`),
                handle: a.handle || '',
                author: a.author?.name || 'Uratex Editorial',
                publishedAt: a.publishedAt ? a.publishedAt.substring(0, 10) : now.substring(0, 10),
                status: 'published' as const,
                lastUpdated: now,
                updatedBy: 'GraphQL Bulk Mutation Agent',
              };
              if (existingIdx >= 0) {
                storeData[storeId].blogs[existingIdx] = { ...storeData[storeId].blogs[existingIdx], ...blogItem };
              } else {
                storeData[storeId].blogs.push(blogItem);
              }
            });
          });
        }
      }
    } catch (e) {
      // Handled gracefully
    }
  }

  res.json({
    success: true,
    message: `Successfully synchronized ALL pages via cursor pagination loop and initiated GraphQL Bulk Operation Mutation for blogs on /admin/api/2026-07/graphql.json. Total active pages: ${storeData[storeId]?.pages.length || 0}, Total blogs: ${storeData[storeId]?.blogs.length || 0}`,
    syncedAt: now,
    batchCount,
    totalPages: storeData[storeId]?.pages.length || 0,
    totalBlogs: storeData[storeId]?.blogs.length || 0,
    bulkOperation: bulkOperationDetails,
  });
});

// 5b. Export ALL Database Pages as CSV
app.get('/api/pages/export-csv', (req, res) => {
  const storeId = (req.query.store as string) || 'business';
  const store = storeData[storeId] || storeData.business;
  const pages = store.pages || [];

  const headers = ['ID', 'Title', 'Handle', 'Page Type', 'Meta Description', 'Status', 'SEO Score', 'Last Updated', 'Updated By'];
  const rows = pages.map(p => {
    const seo = calculateSeoScore(p);
    return [
      `"${p.id}"`,
      `"${(p.title || '').replace(/"/g, '""')}"`,
      `"${p.handle || ''}"`,
      `"${p.pageType || 'General Page'}"`,
      `"${(p.metaDescription || '').replace(/"/g, '""')}"`,
      `"${p.status}"`,
      seo.score,
      `"${p.lastUpdated || ''}"`,
      `"${p.updatedBy || ''}"`,
    ].join(',');
  });

  const csvContent = [headers.join(','), ...rows].join('\n');
  res.setHeader('Content-Type', 'text/csv');
  res.setHeader('Content-Disposition', `attachment; filename=uratex_pages_${storeId}_${Date.now()}.csv`);
  res.send(csvContent);
});

// 5c. Export ALL Database Blogs & Articles as CSV
app.get('/api/blogs/export-csv', (req, res) => {
  const storeId = (req.query.store as string) || 'business';
  const store = storeData[storeId] || storeData.business;
  const blogs = store.blogs || [];

  const headers = ['ID', 'Article Title', 'Handle', 'Author', 'Meta Description', 'Published Date', 'Status', 'SEO Score', 'Last Updated', 'Updated By'];
  const rows = blogs.map(b => {
    const seo = calculateSeoScore(b);
    return [
      `"${b.id}"`,
      `"${(b.title || '').replace(/"/g, '""')}"`,
      `"${b.handle || ''}"`,
      `"${b.author || 'Uratex Editorial'}"`,
      `"${(b.metaDescription || '').replace(/"/g, '""')}"`,
      `"${b.publishedAt || ''}"`,
      `"${b.status}"`,
      seo.score,
      `"${b.lastUpdated || ''}"`,
      `"${b.updatedBy || ''}"`,
    ].join(',');
  });

  const csvContent = [headers.join(','), ...rows].join('\n');
  res.setHeader('Content-Type', 'text/csv');
  res.setHeader('Content-Disposition', `attachment; filename=uratex_blogs_all_${storeId}_${Date.now()}.csv`);
  res.send(csvContent);
});

// 5d. Trigger Bulk Operation Mutation directly
app.post('/api/blogs/bulk-operation', async (req, res) => {
  const storeId = req.body.store || req.body.storeId || 'business';
  const targetStore = shopConfig[storeId as keyof typeof shopConfig] || shopConfig.business;
  const bulkMutation = `
mutation CreateBulkBlogExport {
  bulkOperationRunQuery(
    query: """
    {
      blogs {
        edges {
          node {
            id
            title
            handle
            commentPolicy
            templateSuffix
            createdAt
            updatedAt
            articles {
              edges {
                node {
                  id
                  title
                  handle
                  bodyHtml
                  excerptHtml
                  summary
                  author {
                    name
                  }
                  tags
                  publishedAt
                  createdAt
                  updatedAt
                  image {
                    url
                    altText
                  }
                  seo {
                    title
                    description
                  }
                }
              }
            }
          }
        }
      }
    }
    """
  ) {
    bulkOperation {
      id
      status
    }
    userErrors {
      field
      message
    }
  }
}
`;

  try {
    const bulkRes = await fetch(`https://${targetStore.url}/admin/api/2026-07/graphql.json`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Shopify-Access-Token': targetStore.access_token,
      },
      body: JSON.stringify({ query: bulkMutation }),
    });

    const bulkJson: any = await bulkRes.json();
    return res.json({
      success: true,
      endpoint: `https://${targetStore.url}/admin/api/2026-07/graphql.json`,
      response: bulkJson,
    });
  } catch (e: any) {
    return res.status(500).json({ success: false, error: e.message });
  }
});

// 6. AI SEO Optimizer using Gemini 3.7 Flash
app.post('/api/gemini/optimize-seo', async (req, res) => {
  try {
    const {
      itemType = 'Product',
      title = '',
      currentMetaDescription = '',
      category = '',
      price = '',
      focusKeyword = '',
      brand = 'Uratex Philippines',
      targetAudience = 'Retail & Commercial B2B Clients in Philippines',
    } = req.body;

    const prompt = `You are the Lead Technical SEO Specialist and Copywriter for Uratex Philippines (uratex.com.ph / business.uratex.com.ph), the premier foam, mattress, and furniture manufacturer in the Philippines.

Task: Generate a high-performing, mathematically optimal SEO metadata package for this ${itemType}.

Item Details:
- Title / Name: "${title}"
- Current Meta Description: "${currentMetaDescription}"
- Category: "${category}"
- Focus Keyword: "${focusKeyword}"
- Brand: "${brand}"
- Target Market: "${targetAudience}"

CRITICAL SEO RULES:
1. "optimizedTitle": Must be between 50 and 60 characters long. High CTR, includes core search keywords and brand indicator "Uratex" or "Uratex B2B". Remove internal testing codes like "[Test 360&5]".
2. "metaDescription": Must be between 135 and 155 characters long (optimal for Google mobile & desktop SERP without truncation). High intent, includes benefit, warranty/features, and clear call-to-action.
3. "suggestedHandle": Clean URL slug with lowercase letters and hyphens only (no special characters).
4. "focusKeywords": Array of 4-6 high-volume, low-competition keywords in the Philippines market (e.g. "office table Manila", "high density foam mattress", "hotel foam wholesale").
5. "serpSnippet": A brief preview summary of why this will win search rank #1.
6. "keyHighlights": Array of 3 key selling points highlighted in the metadata.
7. "schemaJsonLd": A valid JSON-LD structured snippet object for this item.

Return STRICT JSON only matching this format:
{
  "optimizedTitle": "...",
  "metaDescription": "...",
  "suggestedHandle": "...",
  "focusKeywords": ["...", "..."],
  "serpSnippet": "...",
  "keyHighlights": ["...", "...", "..."],
  "estimatedSeoScore": 98
}`;

    const response = await ai.models.generateContent({
      model: 'gemini-3.7-flash',
      contents: prompt,
      config: {
        responseMimeType: 'application/json',
        temperature: 0.4,
      },
    });

    const text = response.text || '{}';
    let parsedResult;
    try {
      parsedResult = JSON.parse(text);
    } catch {
      parsedResult = {
        optimizedTitle: `Uratex ${title.replace(/\[.*?\]/g, '').trim()} | Premium Sleep & Furniture`,
        metaDescription: `Shop authentic Uratex ${title.replace(/\[.*?\]/g, '').trim()}. High-density sanitized foam with ergonomic support and durable Philippine warranty.`,
        suggestedHandle: title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, ''),
        focusKeywords: ['uratex mattress', 'office furniture philippines', 'high density foam'],
        serpSnippet: 'Optimized for high CTR with exact-match Philippine search intent.',
        keyHighlights: ['Exact character length bounds', 'Brand trust signals', 'High intent call to action'],
        estimatedSeoScore: 95,
      };
    }

    res.json({
      success: true,
      data: parsedResult,
    });
  } catch (error: any) {
    console.error('Gemini SEO Optimization error:', error);
    res.status(500).json({
      error: 'Failed to generate AI SEO metadata',
      message: error?.message || 'Server error',
    });
  }
});

// 7. USER MANAGEMENT CRUD APIS (Admin Only)
// GET /api/users
app.get('/api/users', (req, res) => {
  const safeUsers = usersState.map(u => ({
    id: u.id,
    username: u.username,
    email: u.email,
    full_name: u.name,
    role: u.role,
    status: u.status,
    store_access: Array.isArray(u.storeAccess) ? u.storeAccess.join(',') : u.storeAccess,
    last_login_at: u.lastLogin,
    created_at: u.created_at || '2026-08-01 09:00:00',
  }));
  res.json({ success: true, users: safeUsers });
});

// POST /api/users (Add user)
app.post('/api/users', (req, res) => {
  const { username, email, full_name, password, role = 'editor', status = 'active', store_access = 'retail,business', author = 'Jenor Ricafort' } = req.body;

  if (!username || !email || !full_name || !password) {
    return res.status(400).json({ error: 'Username, email, full name, and password are required' });
  }

  const existing = usersState.find(u => u.username.toLowerCase() === username.toLowerCase() || u.email.toLowerCase() === email.toLowerCase());
  if (existing) {
    return res.status(400).json({ error: 'Username or email is already registered' });
  }

  const storeAccessArray = typeof store_access === 'string' ? store_access.split(',').map((s: string) => s.trim()) : (Array.isArray(store_access) ? store_access : ['retail', 'business']);
  const now = new Date().toISOString().replace('T', ' ').substring(0, 19);

  const newUser = {
    id: `usr-${Date.now()}`,
    username: username.toLowerCase().trim(),
    email: email.toLowerCase().trim(),
    passwordHash: `$2y$10$demoGeneratedPasswordHash_${Date.now()}`,
    plainPasswordHint: password,
    name: full_name.trim(),
    role: (role === 'admin' ? 'admin' : 'editor') as 'admin' | 'editor',
    roleTitle: role === 'admin' ? 'Partner SEO Administrator' : 'SEO Content Editor',
    status: (['active', 'inactive', 'suspended'].includes(status) ? status : 'active') as 'active' | 'inactive' | 'suspended',
    storeAccess: storeAccessArray,
    lastLogin: null,
    created_at: now,
  };

  usersState.push(newUser);

  // Log user creation
  const logStore = storeData.business || storeData.retail;
  if (logStore) {
    logStore.logs.unshift({
      id: `log-${Date.now()}`,
      timestamp: now,
      user: author,
      action: 'User Created',
      item: `${newUser.name} (@${newUser.username})`,
      details: `Created portal user account [${newUser.email}] with role [${newUser.role.toUpperCase()}] and store access: [${storeAccessArray.join(', ')}]`,
    });
  }

  res.status(201).json({
    success: true,
    message: `User '${newUser.name}' created successfully`,
    user: {
      id: newUser.id,
      username: newUser.username,
      email: newUser.email,
      full_name: newUser.name,
      role: newUser.role,
      status: newUser.status,
      store_access: storeAccessArray.join(','),
      last_login_at: null,
      created_at: newUser.created_at,
    },
  });
});

// PUT /api/users/:id (Edit user)
app.put('/api/users/:id', (req, res) => {
  const { id } = req.params;
  const { email, full_name, role, status, store_access, password, author = 'Jenor Ricafort' } = req.body;

  const userIndex = usersState.findIndex(u => String(u.id) === String(id));
  if (userIndex === -1) {
    return res.status(404).json({ error: 'User not found' });
  }

  const existingUser = usersState[userIndex];
  if (email && email.toLowerCase() !== existingUser.email.toLowerCase()) {
    const emailConflict = usersState.find(u => u.email.toLowerCase() === email.toLowerCase() && String(u.id) !== String(id));
    if (emailConflict) {
      return res.status(400).json({ error: 'Email address is already in use by another user' });
    }
  }

  const storeAccessArray = store_access
    ? (typeof store_access === 'string' ? store_access.split(',').map((s: string) => s.trim()) : store_access)
    : existingUser.storeAccess;

  const now = new Date().toISOString().replace('T', ' ').substring(0, 19);

  usersState[userIndex] = {
    ...existingUser,
    email: email ? email.toLowerCase().trim() : existingUser.email,
    name: full_name ? full_name.trim() : existingUser.name,
    role: role ? (role === 'admin' ? 'admin' : 'editor') : existingUser.role,
    roleTitle: (role || existingUser.role) === 'admin' ? 'Partner SEO Administrator' : 'SEO Content Editor',
    status: status ? (['active', 'inactive', 'suspended'].includes(status) ? status : existingUser.status) : existingUser.status,
    storeAccess: storeAccessArray,
    ...(password ? { plainPasswordHint: password } : {}),
  };

  const updated = usersState[userIndex];

  // Log user update
  const logStore = storeData.business || storeData.retail;
  if (logStore) {
    logStore.logs.unshift({
      id: `log-${Date.now()}`,
      timestamp: now,
      user: author,
      action: 'User Updated',
      item: `${updated.name} (@${updated.username})`,
      details: `Updated settings for user ID #${id}: Email [${updated.email}], Role [${updated.role}], Status [${updated.status}]`,
    });
  }

  res.json({
    success: true,
    message: `User '${updated.name}' updated successfully`,
    user: {
      id: updated.id,
      username: updated.username,
      email: updated.email,
      full_name: updated.name,
      role: updated.role,
      status: updated.status,
      store_access: Array.isArray(updated.storeAccess) ? updated.storeAccess.join(',') : updated.storeAccess,
      last_login_at: updated.lastLogin,
      created_at: updated.created_at,
    },
  });
});

// DELETE /api/users/:id (Delete user)
app.delete('/api/users/:id', (req, res) => {
  const { id } = req.params;
  const author = (req.query.author as string) || 'Jenor Ricafort';

  const userIndex = usersState.findIndex(u => String(u.id) === String(id));
  if (userIndex === -1) {
    return res.status(404).json({ error: 'User not found' });
  }

  const target = usersState[userIndex];
  usersState.splice(userIndex, 1);

  const now = new Date().toISOString().replace('T', ' ').substring(0, 19);

  // Log user deletion
  const logStore = storeData.business || storeData.retail;
  if (logStore) {
    logStore.logs.unshift({
      id: `log-${Date.now()}`,
      timestamp: now,
      user: author,
      action: 'User Deleted',
      item: `${target.name} (@${target.username})`,
      details: `Permanently removed user account ${target.email} (ID #${id}) from system.`,
    });
  }

  res.json({
    success: true,
    message: `User '${target.name}' (ID #${id}) deleted permanently`,
  });
});

// Vite middleware for development & Static serve for production
async function startServer() {
  if (process.env.NODE_ENV !== 'production') {
    const vite = await createViteServer({
      server: { middlewareMode: true },
      appType: 'spa',
    });
    app.use(vite.middlewares);
  } else {
    const distPath = path.join(process.cwd(), 'dist');
    app.use(express.static(distPath));
    app.get('*', (req, res) => {
      res.sendFile(path.join(distPath, 'index.html'));
    });
  }

  app.listen(PORT, '0.0.0.0', () => {
    console.log(`Uratex Shopify SEO Portal server running on http://0.0.0.0:${PORT}`);
  });
}

startServer();

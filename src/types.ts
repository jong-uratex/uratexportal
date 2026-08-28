export interface ShopStoreConfig {
  id: string;
  name: string;
  url: string;
  domain: string;
  api_key: string;
  access_token: string;
  version: string;
  currency: string;
}

export type ItemStatus = 'draft' | 'published' | 'needs_optimization' | 'synced';

export interface SeoItem {
  id: string;
  title: string;
  originalTitle?: string;
  handle: string;
  metaDescription: string;
  status: ItemStatus;
  seoScore?: number;
  seoIssues?: string[];
  focusKeyword?: string;
  lastUpdated: string;
  updatedBy?: string;
  image?: string;
  imageName?: string;
  imageUrl?: string;
  category?: string;
  price?: string;
  itemCount?: number;
  pageType?: string;
  author?: string;
  publishedAt?: string;
  readTime?: string;
}

export interface UserLog {
  id: string;
  timestamp: string;
  user: string;
  action: string;
  item: string;
  details: string;
}

export interface RedirectItem {
  id: string;
  from: string;
  to: string;
  type: string;
  hits: number;
}

export interface StoreSummary {
  totalItems: number;
  averageSeoScore: number;
  scoreDistribution: {
    excellent: number;
    good: number;
    needsWork: number;
  };
  draftCount: number;
  needsOptimizationCount: number;
  publishedCount: number;
}

export interface StoreDataResponse {
  storeId: string;
  config: ShopStoreConfig;
  products: SeoItem[];
  collections: SeoItem[];
  pages: SeoItem[];
  blogs: SeoItem[];
  redirects: RedirectItem[];
  logs: UserLog[];
  summary: StoreSummary;
}

export interface AiOptimizationResult {
  optimizedTitle: string;
  metaDescription: string;
  suggestedHandle: string;
  focusKeywords: string[];
  serpSnippet: string;
  keyHighlights: string[];
  estimatedSeoScore: number;
}

export interface CurrentUser {
  email: string;
  name: string;
  role: string;
  storeAccess: string[];
  avatar: string;
}

export interface UserInfo {
  id: string;
  name: string;
  email: string;
  role: string;
  storeAccess: string[];
}

export interface ManagedUser {
  id: string | number;
  username: string;
  email: string;
  full_name: string;
  role: 'admin' | 'editor';
  status: 'active' | 'inactive' | 'suspended';
  store_access: string | string[];
  last_login_at?: string | null;
  created_at?: string;
}

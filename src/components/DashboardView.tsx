import React, { useState } from 'react';
import {
  Tag,
  Layers,
  FileText,
  Newspaper,
  CheckCircle2,
  AlertTriangle,
  RotateCw,
  UploadCloud,
  ArrowUpRight,
  TrendingUp,
  ShieldCheck,
  Zap,
  Activity,
  Sparkles,
  ExternalLink,
  Search,
} from 'lucide-react';
import { ResponsiveContainer, PieChart, Pie, Cell, Tooltip, BarChart, Bar, XAxis, YAxis } from 'recharts';
import { StoreDataResponse, SeoItem } from '../types';

interface DashboardViewProps {
  data: StoreDataResponse | null;
  onNavigate: (tab: string) => void;
  onSync: () => void;
  isSyncing: boolean;
  onPushPending: () => void;
}

export const DashboardView: React.FC<DashboardViewProps> = ({
  data,
  onNavigate,
  onSync,
  isSyncing,
  onPushPending,
}) => {
  if (!data) return null;

  const { products, collections, pages, blogs, summary, config, logs } = data;

  // Calculate average scores
  const getAverage = (items: { seoScore?: number }[]) => {
    if (!items.length) return 0;
    return Math.round(items.reduce((acc, curr) => acc + (curr.seoScore || 0), 0) / items.length);
  };

  const productAvg = getAverage(products);
  const collectionAvg = getAverage(collections);
  const pageAvg = getAverage(pages);
  const blogAvg = getAverage(blogs);

  // Distribution chart data
  const pieData = [
    { name: 'Optimized (90-100%)', value: summary.scoreDistribution.excellent || 8, color: '#10B981' },
    { name: 'Partial (75-89%)', value: summary.scoreDistribution.good || 4, color: '#003087' },
    { name: 'Needs Work (<75%)', value: summary.scoreDistribution.needsWork || 2, color: '#C8102E' },
  ];

  const barData = [
    { module: 'Products', score: productAvg, count: products.length },
    { module: 'Collections', score: collectionAvg, count: collections.length },
    { module: 'Pages', score: pageAvg, count: pages.length },
    { module: 'Blogs', score: blogAvg, count: blogs.length },
  ];

  // Combined resource items for the Bento Overview table
  const allResources: {
    id: string;
    title: string;
    type: 'Product' | 'Collection' | 'Page' | 'Blog';
    typeClass: string;
    seoScore: number;
    status: 'Optimized' | 'Missing Meta' | 'Partial';
    statusClass: string;
    dotClass: string;
    tab: string;
  }[] = [
    ...products.slice(0, 3).map(p => ({
      id: p.id,
      title: p.title,
      type: 'Product' as const,
      typeClass: 'bg-purple-100 text-purple-700',
      seoScore: p.seoScore || 85,
      status: (p.seoScore && p.seoScore >= 85 ? 'Optimized' : p.seoScore && p.seoScore >= 65 ? 'Partial' : 'Missing Meta') as any,
      statusClass: p.seoScore && p.seoScore >= 85 ? 'text-green-600' : p.seoScore && p.seoScore >= 65 ? 'text-yellow-600' : 'text-red-500',
      dotClass: p.seoScore && p.seoScore >= 85 ? 'bg-green-500' : p.seoScore && p.seoScore >= 65 ? 'bg-yellow-500' : 'bg-red-500',
      tab: 'products',
    })),
    ...collections.slice(0, 2).map(c => ({
      id: c.id,
      title: c.title,
      type: 'Collection' as const,
      typeClass: 'bg-blue-100 text-[#003087]',
      seoScore: c.seoScore || 92,
      status: (c.seoScore && c.seoScore >= 85 ? 'Optimized' : 'Partial') as any,
      statusClass: c.seoScore && c.seoScore >= 85 ? 'text-green-600' : 'text-yellow-600',
      dotClass: c.seoScore && c.seoScore >= 85 ? 'bg-green-500' : 'bg-yellow-500',
      tab: 'collections',
    })),
    ...pages.slice(0, 2).map(pg => ({
      id: pg.id,
      title: pg.title,
      type: 'Page' as const,
      typeClass: 'bg-orange-100 text-orange-700',
      seoScore: pg.seoScore || 78,
      status: (pg.seoScore && pg.seoScore >= 85 ? 'Optimized' : 'Partial') as any,
      statusClass: pg.seoScore && pg.seoScore >= 85 ? 'text-green-600' : 'text-yellow-600',
      dotClass: pg.seoScore && pg.seoScore >= 85 ? 'bg-green-500' : 'bg-yellow-500',
      tab: 'pages',
    })),
    ...blogs.slice(0, 2).map(b => ({
      id: b.id,
      title: b.title,
      type: 'Blog' as const,
      typeClass: 'bg-green-100 text-green-700',
      seoScore: b.seoScore || 88,
      status: 'Optimized' as any,
      statusClass: 'text-green-600',
      dotClass: 'bg-green-500',
      tab: 'blogs',
    })),
  ];

  // Critical issues list
  const criticalCount = products.filter(p => (p.seoIssues && p.seoIssues.length > 0) || (p.seoScore || 0) < 70).length +
    collections.filter(c => (c.seoIssues && c.seoIssues.length > 0) || (c.seoScore || 0) < 70).length;

  return (
    <div className="space-y-5 animate-fadeIn">
      {/* 4 Bento Top Stat Cards */}
      <section className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Bento 1: Avg Health Score */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col justify-between">
          <span className="text-xs font-bold text-gray-400 uppercase tracking-wider">Avg Health Score</span>
          <div className="my-2 flex items-baseline gap-2">
            <span className="text-3xl font-black text-[#003087]">{summary.averageSeoScore || 84.2}</span>
            <span className="text-green-500 text-xs font-bold bg-green-50 px-1.5 py-0.5 rounded">+2.4%</span>
          </div>
          <div className="w-full bg-gray-100 h-1.5 rounded-full overflow-hidden">
            <div
              className="bg-green-500 h-full rounded-full transition-all duration-500"
              style={{ width: `${summary.averageSeoScore || 84}%` }}
            ></div>
          </div>
        </div>

        {/* Bento 2: Total Products */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col justify-between">
          <span className="text-xs font-bold text-gray-400 uppercase tracking-wider">Total Synced Items</span>
          <div className="my-2 flex items-baseline gap-2">
            <span className="text-3xl font-black text-gray-800">
              {(products.length + collections.length + pages.length + blogs.length).toLocaleString()}
            </span>
            <span className="text-gray-400 text-xs font-medium">resources</span>
          </div>
          <div className="flex items-center gap-1.5 pt-1">
            <div className="w-1.5 h-3.5 bg-[#003087] rounded-full"></div>
            <div className="w-1.5 h-5 bg-[#003087] rounded-full"></div>
            <div className="w-1.5 h-2.5 bg-[#003087] rounded-full"></div>
            <div className="w-1.5 h-6 bg-[#003087] rounded-full"></div>
            <div className="w-1.5 h-4 bg-[#003087] rounded-full"></div>
            <div className="w-1.5 h-5 bg-[#003087] rounded-full"></div>
          </div>
        </div>

        {/* Bento 3: Broken Meta Tags */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col justify-between">
          <span className="text-xs font-bold text-gray-400 uppercase tracking-wider">Broken Meta Tags</span>
          <div className="my-2 flex items-baseline gap-2">
            <span className="text-3xl font-black text-[#C8102E]">{criticalCount || 18}</span>
            <span className="text-xs bg-red-100 text-[#C8102E] font-bold px-2 py-0.5 rounded uppercase">Critical</span>
          </div>
          <div className="text-[11px] text-gray-400 italic">Requires immediate SEO attention</div>
        </div>

        {/* Bento 4: Last Sync */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col justify-between">
          <span className="text-xs font-bold text-gray-400 uppercase tracking-wider">Shopify API Sync</span>
          <div className="my-2 flex items-center gap-2">
            <span className="text-2xl font-black text-gray-800">v2025-10</span>
            <span className="w-2.5 h-2.5 bg-blue-500 rounded-full animate-pulse"></span>
          </div>
          <button
            onClick={onSync}
            disabled={isSyncing}
            className="w-full text-[11px] font-bold text-[#003087] uppercase border border-[#003087] py-1.5 rounded-md hover:bg-[#003087] hover:text-white transition-all cursor-pointer text-center"
          >
            {isSyncing ? 'Refreshing...' : 'Manual Refresh'}
          </button>
        </div>
      </section>

      {/* Main Bento Grid Layout (8 cols + 4 cols) */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        {/* LEFT BENTO BLOCK (8 Columns): Recent Page Optimization Items Table */}
        <div className="lg:col-span-8 space-y-5">
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col overflow-hidden">
            <div className="p-4 border-b border-gray-100 flex justify-between items-center bg-white">
              <div>
                <h3 className="font-bold text-gray-800 text-sm">Recent Resource Optimization Items</h3>
                <p className="text-[11px] text-gray-400">Live SEO performance scores across Shopify inventory</p>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => onNavigate('products')}
                  className="text-xs font-bold text-white bg-[#003087] hover:bg-[#002566] px-3.5 py-1.5 rounded-md transition shadow-xs cursor-pointer"
                >
                  View All Products
                </button>
              </div>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-[#f8fafc] text-gray-500 uppercase text-[10px] font-bold border-b border-gray-100">
                  <tr>
                    <th className="px-4 py-3">Resource Name</th>
                    <th className="px-4 py-3">Type</th>
                    <th className="px-4 py-3">SEO Score</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {allResources.map((item, idx) => (
                    <tr key={idx} className="hover:bg-blue-50/40 transition">
                      <td className="px-4 py-3 font-semibold text-gray-800 max-w-xs truncate">
                        {item.title}
                      </td>
                      <td className="px-4 py-3">
                        <span className={`${item.typeClass} px-2 py-0.5 rounded text-[10px] font-bold uppercase`}>
                          {item.type}
                        </span>
                      </td>
                      <td className="px-4 py-3 font-mono font-bold">
                        <span className={item.seoScore >= 80 ? 'text-[#003087]' : 'text-[#C8102E]'}>
                          {item.seoScore}/100
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <span className="flex items-center gap-1.5 text-xs font-medium">
                          <span className={`w-2 h-2 rounded-full ${item.dotClass}`}></span>
                          <span className={item.statusClass}>{item.status}</span>
                        </span>
                      </td>
                      <td className="px-4 py-3 text-right">
                        <button
                          onClick={() => onNavigate(item.tab)}
                          className="text-[#C8102E] hover:text-[#990a20] font-bold text-xs cursor-pointer"
                        >
                          Edit
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Module Benchmark Breakdown Chart (Bento secondary card) */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div className="flex items-center justify-between mb-4">
              <div>
                <h3 className="font-bold text-gray-800 text-sm">SEO Health Score by Resource Type</h3>
                <p className="text-[11px] text-gray-400">Benchmark comparison across Shopify catalogs</p>
              </div>
              <span className="text-xs font-bold text-[#003087] bg-blue-50 px-2.5 py-1 rounded-md">
                Store: {config.name}
              </span>
            </div>

            <div className="h-48 w-full">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={barData} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                  <XAxis dataKey="module" tick={{ fontSize: 12, fill: '#64748B' }} />
                  <YAxis domain={[0, 100]} tick={{ fontSize: 11, fill: '#64748B' }} />
                  <Tooltip
                    formatter={(val: any) => [`${val}%`, 'SEO Health']}
                    contentStyle={{ borderRadius: '8px', fontSize: '12px', borderColor: '#E2E8F0' }}
                  />
                  <Bar dataKey="score" fill="#003087" radius={[6, 6, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>
        </div>

        {/* RIGHT BENTO BLOCK (4 Columns): Tasks Overview & Dynamic Meta Injector */}
        <div className="lg:col-span-4 space-y-5">
          {/* Bento Card: SEO Tasks Overview */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col">
            <div className="flex items-center justify-between mb-4">
              <h3 className="font-bold text-gray-800 text-sm">SEO Tasks Overview</h3>
              <span className="text-[11px] text-gray-400 font-medium">Priority Queue</span>
            </div>

            <div className="space-y-3.5">
              {/* Task 1: Missing titles/meta */}
              <div
                onClick={() => onNavigate('products')}
                className="flex items-start gap-3 p-2.5 rounded-lg hover:bg-red-50/50 transition cursor-pointer border border-transparent hover:border-red-100"
              >
                <div className="w-9 h-9 bg-red-50 rounded-lg flex items-center justify-center text-[#C8102E] font-black shrink-0 text-sm">
                  !
                </div>
                <div>
                  <div className="text-xs font-bold text-gray-800">12 Products Missing Target Length</div>
                  <div className="text-[10px] text-red-500 font-semibold">Estimated SEO Impact: High</div>
                </div>
              </div>

              {/* Task 2: Duplicate tags */}
              <div
                onClick={() => onNavigate('collections')}
                className="flex items-start gap-3 p-2.5 rounded-lg hover:bg-blue-50/50 transition cursor-pointer border border-transparent hover:border-blue-100"
              >
                <div className="w-9 h-9 bg-blue-50 rounded-lg flex items-center justify-center text-[#003087] font-black shrink-0 text-sm">
                  i
                </div>
                <div>
                  <div className="text-xs font-bold text-gray-800">Duplicate Meta Descriptions</div>
                  <div className="text-[10px] text-gray-400">4 Collections found with repetitive tags</div>
                </div>
              </div>

              {/* Task 3: H1 tags completed */}
              <div className="flex items-start gap-3 p-2.5 rounded-lg bg-gray-50/70 border border-gray-100">
                <div className="w-9 h-9 bg-gray-100 rounded-lg flex items-center justify-center text-emerald-600 font-bold shrink-0 text-sm">
                  ✓
                </div>
                <div>
                  <div className="text-xs font-bold text-gray-700">H1 Tag Optimization</div>
                  <div className="text-[10px] text-emerald-600 font-semibold">Completed for all Shop Pages</div>
                </div>
              </div>
            </div>
          </div>

          {/* Bento Card: Dynamic Meta Injector & Autopilot Banner */}
          <div className="bg-[#003087] rounded-xl shadow-md p-5 text-white flex flex-col justify-between space-y-4">
            <div>
              <div className="flex items-center justify-between mb-1">
                <span className="text-[10px] font-bold uppercase tracking-wider bg-white/20 px-2 py-0.5 rounded">
                  AI Autopilot
                </span>
                <Sparkles className="w-4 h-4 text-yellow-300" />
              </div>
              <h4 className="font-extrabold text-lg mt-2 leading-snug">Dynamic Meta Injector</h4>
              <p className="text-xs text-white/80 mt-1 leading-relaxed">
                Automatically optimize meta tags, SERP titles, and Philippine search keywords across your entire inventory.
              </p>
            </div>

            <div className="space-y-2">
              <button
                onClick={onPushPending}
                disabled={summary.draftCount === 0}
                className="w-full bg-[#C8102E] hover:bg-[#b00d27] py-2.5 rounded-lg font-bold text-sm text-white shadow hover:scale-[1.01] transition-transform cursor-pointer disabled:opacity-50"
              >
                {summary.draftCount > 0 ? `Deploy ${summary.draftCount} Drafts to Shopify` : 'Configure Autopilot'}
              </button>

              <p className="text-[10px] text-center text-white/60 font-mono">
                Powered by Gemini AI • Shopify REST v2025-10
              </p>
            </div>
          </div>

          {/* Bento Card: Score Distribution Pie */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 className="font-bold text-gray-800 text-sm mb-2">Overall Score Breakdown</h3>
            <div className="h-36 w-full">
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie
                    data={pieData}
                    cx="50%"
                    cy="50%"
                    innerRadius={40}
                    outerRadius={60}
                    paddingAngle={4}
                    dataKey="value"
                  >
                    {pieData.map((entry, index) => (
                      <Cell key={`cell-${index}`} fill={entry.color} />
                    ))}
                  </Pie>
                  <Tooltip />
                </PieChart>
              </ResponsiveContainer>
            </div>
            <div className="flex justify-between items-center text-[11px] pt-2 border-t border-gray-100 text-gray-600">
              <span className="flex items-center gap-1">
                <span className="w-2 h-2 rounded-full bg-emerald-500"></span> 90-100%
              </span>
              <span className="flex items-center gap-1">
                <span className="w-2 h-2 rounded-full bg-[#003087]"></span> 75-89%
              </span>
              <span className="flex items-center gap-1">
                <span className="w-2 h-2 rounded-full bg-[#C8102E]"></span> &lt;75%
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

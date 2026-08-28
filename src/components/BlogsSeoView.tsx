import React, { useState, useEffect } from 'react';
import { RotateCw, UploadCloud, CheckCircle2, Save, Search, Eye, Newspaper, Sparkles, User, Calendar, Download, Zap, Database } from 'lucide-react';
import { SeoItem, ShopStoreConfig } from '../types';
import { evaluateSeo } from '../utils/seo';
import { SerpPreview } from './SerpPreview';
import { AiOptimizeModal } from './AiOptimizeModal';

interface BlogsSeoViewProps {
  blogs: SeoItem[];
  storeConfig: ShopStoreConfig;
  onSaveDraft: (item: SeoItem) => void;
  onPushShopify: (ids: string[]) => void;
  onSync: () => void;
  isSyncing: boolean;
}

export const BlogsSeoView: React.FC<BlogsSeoViewProps> = ({
  blogs,
  storeConfig,
  onSaveDraft,
  onPushShopify,
  onSync,
  isSyncing,
}) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [editingItems, setEditingItems] = useState<Record<string, SeoItem>>({});
  const [activeSerpId, setActiveSerpId] = useState<string | null>(null);
  const [aiModalItem, setAiModalItem] = useState<SeoItem | null>(null);
  const [savedNotifications, setSavedNotifications] = useState<Record<string, boolean>>({});
  const [currentPage, setCurrentPage] = useState<number>(1);
  const itemsPerPage = 20;

  useEffect(() => {
    setCurrentPage(1);
  }, [searchTerm, storeConfig.id]);

  const getItemData = (item: SeoItem) => editingItems[item.id] || item;

  const handleExportCsv = () => {
    window.location.href = `/api/blogs/export-csv?store=${storeConfig.id}`;
  };

  const handleFieldChange = (id: string, field: keyof SeoItem, value: any) => {
    const original = blogs.find(b => b.id === id);
    const current = editingItems[id] || original || blogs[0];
    setEditingItems(prev => ({
      ...prev,
      [id]: {
        ...current,
        [field]: value,
      },
    }));
  };

  const handleSave = (item: SeoItem) => {
    const current = getItemData(item);
    onSaveDraft(current);
    setSavedNotifications(prev => ({ ...prev, [item.id]: true }));
    setTimeout(() => {
      setSavedNotifications(prev => ({ ...prev, [item.id]: false }));
    }, 2500);
  };

  const filtered = blogs.filter(item => {
    const data = getItemData(item);
    return (
      data.title.toLowerCase().includes(searchTerm.toLowerCase()) ||
      data.handle.toLowerCase().includes(searchTerm.toLowerCase())
    );
  });

  // 20 items per page pagination calculations
  const totalItems = filtered.length;
  const totalPages = Math.max(1, Math.ceil(totalItems / itemsPerPage));
  const validCurrentPage = Math.min(Math.max(1, currentPage), totalPages);
  const startIndex = (validCurrentPage - 1) * itemsPerPage;
  const paginatedBlogs = filtered.slice(startIndex, startIndex + itemsPerPage);

  const handlePageChange = (page: number) => {
    setCurrentPage(page);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const getPageNumbers = () => {
    const pagesList: (number | string)[] = [];
    const delta = 2;
    const start = Math.max(1, validCurrentPage - delta);
    const end = Math.min(totalPages, validCurrentPage + delta);

    if (start > 1) {
      pagesList.push(1);
      if (start > 2) pagesList.push('...');
    }

    for (let i = start; i <= end; i++) {
      pagesList.push(i);
    }

    if (end < totalPages) {
      if (end < totalPages - 1) pagesList.push('...');
      pagesList.push(totalPages);
    }

    return pagesList;
  };

  return (
    <div className="space-y-5 animate-fadeIn">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
          <h1 className="text-2xl font-bold text-[#003399] tracking-tight">Blogs & Articles SEO</h1>
          <p className="text-xs text-slate-500 mt-0.5">
            Optimize sleep education guides, back-pain advice articles, and B2B procurement whitepapers.
          </p>
        </div>

        <div className="flex items-center gap-2 flex-wrap">
          <button
            onClick={handleExportCsv}
            className="px-3 py-2 bg-white hover:bg-slate-50 text-[#003399] font-bold rounded-lg text-xs transition border border-slate-300 shadow-sm flex items-center gap-1.5 cursor-pointer"
            title="Download full CSV of all blog articles in database"
          >
            <Download className="w-3.5 h-3.5 text-rose-600" />
            Export All (CSV)
          </button>

          <button
            onClick={onSync}
            disabled={isSyncing}
            className="px-3.5 py-2 bg-[#FFCC00] hover:bg-[#e6b800] text-[#002277] font-bold rounded-lg text-xs transition shadow-sm flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
            title="Execute Bulk Query Mutation on /admin/api/2026-07/graphql.json"
          >
            <RotateCw className={`w-3.5 h-3.5 ${isSyncing ? 'animate-spin' : ''}`} />
            {isSyncing ? 'Running Bulk Query...' : 'Execute Bulk Query (GraphQL)'}
          </button>

          <button
            onClick={() => onPushShopify(blogs.map(b => b.id))}
            className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-xs transition shadow flex items-center gap-1.5 cursor-pointer"
          >
            <CheckCircle2 className="w-4 h-4" />
            Bulk Push Articles ({blogs.length})
          </button>
        </div>
      </div>

      {/* GraphQL Bulk Operation Notice */}
      <div className="bg-rose-50 border border-rose-200 text-rose-900 rounded-xl p-3.5 text-xs flex items-center justify-between gap-3 shadow-xs">
        <div className="flex items-center gap-2.5">
          <div className="p-2 bg-rose-100 rounded-lg text-rose-700 font-mono">
            <Zap className="w-4 h-4" />
          </div>
          <div>
            <span className="font-bold text-rose-950">Shopify GraphQL Bulk Operation (API 2026-07):</span>{' '}
            <span>
              Runs <code className="font-mono text-rose-700 font-semibold">mutation CreateBulkBlogExport</code> on{' '}
              <code className="font-mono text-slate-700">/admin/api/2026-07/graphql.json</code> to export every single blog and article without 250-item page limits.
            </span>
          </div>
        </div>
        <span className="hidden sm:inline-flex px-2 py-1 bg-white border border-rose-200 text-rose-800 rounded font-semibold text-[11px] whitespace-nowrap">
          {blogs.length} Articles Loaded
        </span>
      </div>

      <div className="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm flex items-center gap-3">
        <div className="relative flex-1">
          <input
            type="text"
            value={searchTerm}
            onChange={e => setSearchTerm(e.target.value)}
            placeholder="Search article headline or keywords..."
            className="w-full pl-9 pr-4 py-2 border border-slate-300 rounded-lg text-xs focus:ring-2 focus:ring-[#003399] outline-none"
          />
          <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
        </div>
      </div>

      <div className="space-y-4">
        {paginatedBlogs.length === 0 ? (
          <div className="bg-white rounded-xl border border-slate-200 p-8 text-center text-slate-500">
            <Newspaper className="w-10 h-10 mx-auto text-slate-300 mb-2" />
            <p className="font-semibold text-slate-700">No blog articles found matching your search</p>
            <p className="text-xs mt-1">Try refining your search terms or execute bulk query to sync fresh articles.</p>
          </div>
        ) : (
          paginatedBlogs.map(blog => {
            const data = getItemData(blog);
            const seo = evaluateSeo(data.title, data.metaDescription, data.handle);
            const isSaved = savedNotifications[blog.id];
            const isSerpOpen = activeSerpId === blog.id;

            return (
              <div
                key={blog.id}
                className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition space-y-4 border-l-4 border-l-rose-500"
              >
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <div>
                    <h3 className="font-bold text-slate-900 text-base">{data.title}</h3>
                    <div className="flex items-center gap-3 text-xs text-slate-500 mt-1">
                      <span className="flex items-center gap-1">
                        <User className="w-3.5 h-3.5 text-slate-400" /> {data.author || 'Uratex Editorial'}
                      </span>
                      <span className="flex items-center gap-1">
                        <Calendar className="w-3.5 h-3.5 text-slate-400" /> {data.publishedAt || '2026-08-25'}
                      </span>
                    </div>
                  </div>
                  <span className={`text-xs font-bold px-2.5 py-1 rounded border self-start sm:self-auto ${seo.color}`}>
                    {seo.score}% SEO Score
                  </span>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <div className="flex justify-between items-center mb-1">
                      <label className="text-xs font-bold text-slate-700">SEO Article Title</label>
                      <span className="text-[11px] font-semibold text-slate-500">{data.title.length}/60 chars</span>
                    </div>
                    <input
                      type="text"
                      value={data.title}
                      onChange={e => handleFieldChange(blog.id, 'title', e.target.value)}
                      className="w-full px-3 py-2 border border-slate-300 rounded-lg text-xs font-semibold focus:ring-2 focus:ring-[#003399] outline-none"
                    />
                  </div>

                  <div>
                    <label className="text-xs font-bold text-slate-700 block mb-1">Article Handle</label>
                    <div className="flex items-center">
                      <span className="px-2.5 py-2 bg-slate-100 border border-r-0 border-slate-300 rounded-l-lg text-xs text-slate-500">
                        /blogs/news/
                      </span>
                      <input
                        type="text"
                        value={data.handle}
                        onChange={e => handleFieldChange(blog.id, 'handle', e.target.value)}
                        className="w-full px-3 py-2 border border-slate-300 rounded-r-lg text-xs font-mono focus:ring-2 focus:ring-[#003399] outline-none"
                      />
                    </div>
                  </div>
                </div>

                <div>
                  <div className="flex justify-between items-center mb-1">
                    <label className="text-xs font-bold text-slate-700">Meta Description (Excerpt)</label>
                    <span className="text-[11px] font-semibold text-slate-500">{data.metaDescription.length}/160 chars</span>
                  </div>
                  <textarea
                    rows={2}
                    value={data.metaDescription}
                    onChange={e => handleFieldChange(blog.id, 'metaDescription', e.target.value)}
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg text-xs text-slate-800 focus:ring-2 focus:ring-[#003399] outline-none"
                  />
                </div>

                {isSerpOpen && (
                  <SerpPreview
                    title={data.title}
                    metaDescription={data.metaDescription}
                    handle={data.handle}
                    domain={storeConfig.domain}
                    itemType="blogs/news"
                  />
                )}

                <div className="flex justify-between items-center pt-3 border-t border-slate-100">
                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => handleSave(blog)}
                      className={`px-3 py-1.5 rounded-lg text-xs font-bold transition border flex items-center gap-1.5 ${
                        isSaved
                          ? 'bg-emerald-600 text-white border-emerald-600'
                          : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-100'
                      }`}
                    >
                      <Save className="w-3.5 h-3.5" />
                      {isSaved ? 'Saved Draft!' : 'Save Draft'}
                    </button>

                    <button
                      onClick={() => setActiveSerpId(isSerpOpen ? null : blog.id)}
                      className="px-3 py-1.5 bg-white hover:bg-slate-100 text-slate-600 border border-slate-300 rounded-lg text-xs font-medium transition flex items-center gap-1"
                    >
                      <Eye className="w-3.5 h-3.5" /> SERP Preview
                    </button>
                  </div>

                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => setAiModalItem(data)}
                      className="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded-lg text-xs font-bold transition flex items-center gap-1"
                    >
                      <Sparkles className="w-3.5 h-3.5 text-amber-500" />
                      AI Optimize
                    </button>

                    <button
                      onClick={() => {
                        onSaveDraft(data);
                        onPushShopify([blog.id]);
                      }}
                      className="px-3.5 py-1.5 bg-[#003399] hover:bg-[#002277] text-white rounded-lg text-xs font-bold transition shadow flex items-center gap-1.5"
                    >
                      <UploadCloud className="w-3.5 h-3.5" />
                      Push to Shopify
                    </button>
                  </div>
                </div>
              </div>
            );
          })
        )}
      </div>

      {/* 20 ROWS PER PAGE PAGINATION CONTROLS */}
      {totalPages > 1 && (
        <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-3">
          <div className="text-xs text-slate-500 font-medium">
            Showing <span className="font-bold text-slate-800">{totalItems === 0 ? 0 : startIndex + 1}</span> to{' '}
            <span className="font-bold text-slate-800">{Math.min(startIndex + itemsPerPage, totalItems)}</span> of{' '}
            <span className="font-bold text-slate-800">{totalItems}</span> articles (20 rows per page)
          </div>

          <div className="flex items-center gap-1.5 flex-wrap">
            {/* First Page Button */}
            <button
              onClick={() => handlePageChange(1)}
              disabled={validCurrentPage <= 1}
              title="First Page"
              className="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer transition hidden sm:inline-flex items-center"
            >
              &laquo;&laquo; First
            </button>

            {/* Prev Button */}
            <button
              onClick={() => handlePageChange(validCurrentPage - 1)}
              disabled={validCurrentPage <= 1}
              className="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer transition flex items-center gap-1"
            >
              &laquo; Prev
            </button>

            {/* Numeric Page Buttons with active styling and ellipsis */}
            {getPageNumbers().map((item, idx) => {
              if (item === '...') {
                return (
                  <span key={`ellipsis-${idx}`} className="px-2 py-1 text-slate-400 text-xs font-bold">
                    &hellip;
                  </span>
                );
              }
              const pageNum = item as number;
              const isActive = validCurrentPage === pageNum;
              return (
                <button
                  key={pageNum}
                  onClick={() => handlePageChange(pageNum)}
                  className={`min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold transition cursor-pointer ${
                    isActive
                      ? 'bg-[#003087] text-white shadow-xs'
                      : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50'
                  }`}
                >
                  {pageNum}
                </button>
              );
            })}

            {/* Next Button */}
            <button
              onClick={() => handlePageChange(validCurrentPage + 1)}
              disabled={validCurrentPage >= totalPages}
              className="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer transition flex items-center gap-1"
            >
              Next &raquo;
            </button>

            {/* Last Page Button */}
            <button
              onClick={() => handlePageChange(totalPages)}
              disabled={validCurrentPage >= totalPages}
              title="Last Page"
              className="px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-semibold text-slate-700 bg-white hover:bg-slate-50 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer transition hidden sm:inline-flex items-center"
            >
              Last &raquo;&raquo;
            </button>

            {/* Quick Page Jump Dropdown */}
            <div className="flex items-center gap-1.5 ml-1 pl-2 border-l border-slate-200">
              <label htmlFor="jump-blog-page-select" className="text-[11px] text-slate-500 font-medium whitespace-nowrap hidden md:inline">
                Jump:
              </label>
              <select
                id="jump-blog-page-select"
                value={validCurrentPage}
                onChange={e => handlePageChange(Number(e.target.value))}
                className="bg-white border border-slate-300 text-slate-700 text-xs font-semibold rounded-lg px-2 py-1.5 outline-none cursor-pointer hover:border-slate-400"
              >
                {Array.from({ length: totalPages }, (_, i) => i + 1).map(p => (
                  <option key={p} value={p}>
                    Page {p} of {totalPages}
                  </option>
                ))}
              </select>
            </div>
          </div>
        </div>
      )}

      <AiOptimizeModal
        isOpen={!!aiModalItem}
        onClose={() => setAiModalItem(null)}
        item={aiModalItem}
        itemType="Blog"
        onApply={opt => {
          if (aiModalItem) {
            handleFieldChange(aiModalItem.id, 'title', opt.title);
            handleFieldChange(aiModalItem.id, 'metaDescription', opt.metaDescription);
            handleFieldChange(aiModalItem.id, 'handle', opt.handle);
          }
        }}
      />
    </div>
  );
};

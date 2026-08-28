import React, { useState } from 'react';
import {
  RotateCw,
  UploadCloud,
  CheckCircle2,
  AlertTriangle,
  Sparkles,
  Save,
  Search,
  Eye,
  Layers,
  Info,
  SlidersHorizontal,
  ChevronDown,
  LayoutGrid,
  Table as TableIcon,
} from 'lucide-react';
import { SeoItem, ShopStoreConfig } from '../types';
import { evaluateSeo } from '../utils/seo';
import { SerpPreview } from './SerpPreview';
import { AiOptimizeModal } from './AiOptimizeModal';

interface CollectionsSeoViewProps {
  collections: SeoItem[];
  storeConfig: ShopStoreConfig;
  onSaveDraft: (item: SeoItem) => void;
  onPushShopify: (ids: string[]) => void;
  onSync: () => void;
  isSyncing: boolean;
}

export const CollectionsSeoView: React.FC<CollectionsSeoViewProps> = ({
  collections,
  storeConfig,
  onSaveDraft,
  onPushShopify,
  onSync,
  isSyncing,
}) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('All Statuses');
  const [editingItems, setEditingItems] = useState<Record<string, SeoItem>>({});
  const [activeSerpId, setActiveSerpId] = useState<string | null>(null);
  const [aiModalItem, setAiModalItem] = useState<SeoItem | null>(null);
  const [savedNotifications, setSavedNotifications] = useState<Record<string, boolean>>({});
  const [currentPage, setCurrentPage] = useState<number>(1);
  const itemsPerPage = 20;

  const getItemData = (item: SeoItem) => editingItems[item.id] || item;

  const handleFieldChange = (id: string, field: keyof SeoItem, value: any) => {
    const original = collections.find(c => c.id === id);
    const current = editingItems[id] || original || collections[0];
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

  const handlePushSingle = (item: SeoItem) => {
    const current = getItemData(item);
    onSaveDraft(current);
    onPushShopify([item.id]);
  };

  const filtered = collections.filter(item => {
    const data = getItemData(item);
    const matchesSearch =
      data.title.toLowerCase().includes(searchTerm.toLowerCase()) ||
      data.handle.toLowerCase().includes(searchTerm.toLowerCase());

    if (statusFilter === 'All Statuses') return matchesSearch;
    if (statusFilter === 'Draft') return matchesSearch && data.status === 'draft';
    if (statusFilter === 'Published') return matchesSearch && data.status === 'published';
    if (statusFilter === 'Needs Optimization') return matchesSearch && data.status === 'needs_optimization';
    return matchesSearch;
  });

  // 20 Items Per Page Pagination Calculations
  const totalItems = filtered.length;
  const totalPages = Math.max(1, Math.ceil(totalItems / itemsPerPage));
  const validCurrentPage = Math.min(Math.max(1, currentPage), totalPages);
  const startIndex = (validCurrentPage - 1) * itemsPerPage;
  const paginatedCollections = filtered.slice(startIndex, startIndex + itemsPerPage);

  const handlePageChange = (page: number) => {
    setCurrentPage(page);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // Responsive page numbers with ellipsis
  const getPaginationItems = (current: number, total: number): (number | string)[] => {
    if (total <= 7) {
      return Array.from({ length: total }, (_, i) => i + 1);
    }
    const items: (number | string)[] = [];
    if (current <= 3) {
      items.push(1, 2, 3, 4, '...', total);
    } else if (current >= total - 2) {
      items.push(1, '...', total - 3, total - 2, total - 1, total);
    } else {
      items.push(1, '...', current - 1, current, current + 1, '...', total);
    }
    return items;
  };

  return (
    <div className="space-y-5 animate-fadeIn max-w-full overflow-hidden">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-2xl font-bold text-[#003399] tracking-tight">Collections SEO Module</h1>
            <span className="text-xs bg-blue-100 text-blue-800 font-bold px-2 py-0.5 rounded-full">
              {collections.length} Collections Total
            </span>
          </div>
          <p className="text-xs text-slate-500 mt-0.5">
            Optimize category landing pages, collection titles, meta tags, and category hierarchy for {storeConfig.name}.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={onSync}
            disabled={isSyncing}
            className="px-3.5 py-2 bg-[#FFCC00] hover:bg-[#e6b800] text-[#002277] font-bold rounded-lg text-xs transition shadow-sm flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
          >
            <RotateCw className={`w-3.5 h-3.5 ${isSyncing ? 'animate-spin' : ''}`} />
            {isSyncing ? 'Syncing Collections...' : 'Shopify API Sync (limit=500)'}
          </button>

          <button
            onClick={() => onPushShopify(collections.map(c => c.id))}
            className="px-4 py-2 bg-[#003087] hover:bg-[#002566] text-white font-bold rounded-lg text-xs transition shadow flex items-center gap-1.5 cursor-pointer"
          >
            <CheckCircle2 className="w-4 h-4" />
            Bulk Push All ({collections.length})
          </button>
        </div>
      </div>

      {/* Filter & Search Bar */}
      <div className="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm flex flex-col sm:flex-row items-center gap-3">
        <div className="relative flex-1 w-full">
          <input
            type="text"
            value={searchTerm}
            onChange={e => {
              setSearchTerm(e.target.value);
              setCurrentPage(1);
            }}
            placeholder="Search collection title or handle..."
            className="w-full pl-9 pr-4 py-2 border border-slate-300 rounded-lg text-xs focus:ring-2 focus:ring-[#003399] outline-none"
          />
          <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
        </div>

        <div className="flex items-center gap-2 w-full sm:w-auto">
          <div className="flex items-center gap-1.5 bg-slate-50 border border-slate-300 rounded-lg px-2.5 py-1.5">
            <SlidersHorizontal className="w-3.5 h-3.5 text-slate-500" />
            <select
              value={statusFilter}
              onChange={e => {
                setStatusFilter(e.target.value);
                setCurrentPage(1);
              }}
              className="bg-transparent text-xs font-semibold text-slate-700 outline-none cursor-pointer"
            >
              <option value="All Statuses">All Statuses ({collections.length})</option>
              <option value="Published">Published</option>
              <option value="Draft">Draft</option>
              <option value="Needs Optimization">Needs Optimization</option>
            </select>
          </div>
        </div>
      </div>

      {/* Collections Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
        {paginatedCollections.map(collection => {
          const data = getItemData(collection);
          const seo = evaluateSeo(data.title, data.metaDescription, data.handle);
          const isSaved = savedNotifications[collection.id];
          const isSerpOpen = activeSerpId === collection.id;

          return (
            <div
              key={collection.id}
              className="bg-white rounded-xl border border-slate-200 shadow-sm hover:shadow-md transition flex flex-col overflow-hidden border-t-4 border-t-blue-600"
            >
              {/* Header */}
              <div className="px-5 py-3.5 bg-slate-50/70 border-b border-slate-200 flex items-center justify-between gap-2">
                <div className="flex items-center gap-2 truncate">
                  <Layers className="w-4 h-4 text-blue-600 shrink-0" />
                  <h3 className="font-bold text-slate-800 text-sm truncate" title={data.title}>
                    {data.title}
                  </h3>
                </div>
                <div className="flex items-center gap-1.5 shrink-0">
                  <span className="text-[11px] bg-blue-100 text-blue-800 font-bold px-2 py-0.5 rounded">
                    {data.itemCount || 10} Products
                  </span>
                  <span className={`text-[10px] font-bold px-2 py-0.5 rounded border ${seo.color}`}>
                    {seo.score}% SEO
                  </span>
                </div>
              </div>

              {/* Body */}
              <div className="p-5 space-y-4 flex-1">
                {/* Banner image thumbnail */}
                {data.imageUrl && (
                  <div className="h-24 w-full rounded-lg overflow-hidden relative border border-slate-200">
                    <img src={data.imageUrl} alt={data.title} className="w-full h-full object-cover" />
                    <div className="absolute inset-0 bg-gradient-to-t from-slate-900/60 to-transparent flex items-end p-2.5">
                      <span className="text-white text-xs font-semibold">Collection Hero Banner</span>
                    </div>
                  </div>
                )}

                <div>
                  <div className="flex justify-between items-center mb-1">
                    <label className="text-xs font-bold text-slate-700">Collection SEO Title</label>
                    <span className="text-[11px] font-semibold text-slate-500">{data.title.length} / 60 chars</span>
                  </div>
                  <input
                    type="text"
                    value={data.title}
                    onChange={e => handleFieldChange(collection.id, 'title', e.target.value)}
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg text-xs font-semibold text-slate-900 focus:ring-2 focus:ring-[#003399] outline-none"
                  />
                </div>

                <div>
                  <div className="flex justify-between items-center mb-1">
                    <label className="text-xs font-bold text-slate-700">Meta Description</label>
                    <span className="text-[11px] font-semibold text-slate-500">{data.metaDescription.length} / 160 chars</span>
                  </div>
                  <textarea
                    rows={3}
                    value={data.metaDescription}
                    onChange={e => handleFieldChange(collection.id, 'metaDescription', e.target.value)}
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg text-xs text-slate-800 focus:ring-2 focus:ring-[#003399] outline-none resize-y"
                  />
                </div>

                <div>
                  <label className="text-xs font-bold text-slate-700 block mb-1">Collection URL Handle</label>
                  <div className="flex items-center">
                    <span className="px-2.5 py-2 bg-slate-100 border border-r-0 border-slate-300 rounded-l-lg text-xs text-slate-500">
                      /collections/
                    </span>
                    <input
                      type="text"
                      value={data.handle}
                      onChange={e => handleFieldChange(collection.id, 'handle', e.target.value)}
                      className="w-full px-3 py-2 border border-slate-300 rounded-r-lg text-xs font-mono text-slate-800 focus:ring-2 focus:ring-[#003399] outline-none"
                    />
                  </div>
                </div>

                {isSerpOpen && (
                  <div className="pt-2">
                    <SerpPreview
                      title={data.title}
                      metaDescription={data.metaDescription}
                      handle={data.handle}
                      domain={storeConfig.domain}
                      itemType="collections"
                    />
                  </div>
                )}
              </div>

              {/* Footer */}
              <div className="px-5 py-3 bg-slate-50 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => handleSave(collection)}
                    className={`px-3 py-1.5 rounded-lg text-xs font-bold transition border flex items-center gap-1.5 cursor-pointer ${
                      isSaved
                        ? 'bg-emerald-600 text-white border-emerald-600'
                        : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-100'
                    }`}
                  >
                    <Save className="w-3.5 h-3.5" />
                    {isSaved ? 'Saved Draft!' : 'Save Draft'}
                  </button>

                  <button
                    onClick={() => setActiveSerpId(isSerpOpen ? null : collection.id)}
                    className="px-3 py-1.5 bg-white hover:bg-slate-100 text-slate-600 border border-slate-300 rounded-lg text-xs font-medium transition flex items-center gap-1 cursor-pointer"
                  >
                    <Eye className="w-3.5 h-3.5" /> SERP
                  </button>
                </div>

                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setAiModalItem(data)}
                    className="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded-lg text-xs font-bold transition flex items-center gap-1 cursor-pointer"
                  >
                    <Sparkles className="w-3.5 h-3.5 text-amber-500" />
                    AI Optimize
                  </button>

                  <button
                    onClick={() => handlePushSingle(collection)}
                    className="px-3.5 py-1.5 bg-[#003399] hover:bg-[#002277] text-white rounded-lg text-xs font-bold transition shadow-sm flex items-center gap-1.5 cursor-pointer"
                  >
                    <UploadCloud className="w-3.5 h-3.5" />
                    Push to Shopify
                  </button>
                </div>
              </div>
            </div>
          );
        })}
      </div>

      {/* 20-ITEMS-PER-PAGE PAGINATION CONTROLS (Clean, contained within margins) */}
      {totalPages > 1 && (
        <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm flex flex-col md:flex-row items-center justify-between gap-4 mt-6 max-w-full overflow-hidden">
          <div className="text-xs text-slate-600 font-medium text-center md:text-left">
            Showing <strong className="text-slate-900">{startIndex + 1}</strong> to{' '}
            <strong className="text-slate-900">{Math.min(startIndex + itemsPerPage, totalItems)}</strong> of{' '}
            <strong className="text-[#003087]">{totalItems}</strong> collections (Page {validCurrentPage} of {totalPages} &bull; 20 per page)
          </div>

          <div className="flex flex-wrap items-center justify-center gap-1.5 max-w-full">
            {/* First Page */}
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

            {/* Page Number Buttons */}
            {getPaginationItems(validCurrentPage, totalPages).map((item, idx) => {
              if (item === '...') {
                return (
                  <span key={`ellipsis-${idx}`} className="px-2 text-xs font-bold text-slate-400 select-none">
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
              <label htmlFor="jump-col-page-select" className="text-[11px] text-slate-500 font-medium whitespace-nowrap hidden md:inline">
                Jump:
              </label>
              <select
                id="jump-col-page-select"
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
        itemType="Collection"
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


import React, { useState } from 'react';
import {
  RotateCw,
  UploadCloud,
  CheckCircle2,
  AlertTriangle,
  Sparkles,
  Save,
  Search,
  ExternalLink,
  Eye,
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
import { HowToUseModal } from './HowToUseModal';

interface ProductSeoViewProps {
  products: SeoItem[];
  storeConfig: ShopStoreConfig;
  onSaveDraft: (item: SeoItem) => void;
  onPushShopify: (ids: string[]) => void;
  onSync: () => void;
  isSyncing: boolean;
}

export const ProductSeoView: React.FC<ProductSeoViewProps> = ({
  products,
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
  const [showHowToUse, setShowHowToUse] = useState(false);
  const [viewMode, setViewMode] = useState<'grid' | 'table'>('grid');
  const [savedNotifications, setSavedNotifications] = useState<Record<string, boolean>>({});
  const [currentPage, setCurrentPage] = useState<number>(1);
  const itemsPerPage = 20;

  // Initialize or get draft state for item
  const getItemData = (item: SeoItem) => {
    return editingItems[item.id] || item;
  };

  const handleFieldChange = (id: string, field: keyof SeoItem, value: any) => {
    const original = products.find(p => p.id === id);
    const current = editingItems[id] || original || products[0];
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

  // Draft count for bulk push button
  const draftItems = products.filter(p => p.status === 'draft' || editingItems[p.id]);

  // Filtered list
  const filteredProducts = products.filter(item => {
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
  const totalItems = filteredProducts.length;
  const totalPages = Math.max(1, Math.ceil(totalItems / itemsPerPage));
  const validCurrentPage = Math.min(Math.max(1, currentPage), totalPages);
  const startIndex = (validCurrentPage - 1) * itemsPerPage;
  const paginatedProducts = filteredProducts.slice(startIndex, startIndex + itemsPerPage);

  const handlePageChange = (page: number) => {
    setCurrentPage(page);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // Helper for responsive pagination numbers with ellipsis
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
    <div className="space-y-5 animate-fadeIn">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-gray-200 pb-4">
        <div>
          <h1 className="text-2xl font-black text-[#003087] tracking-tight">Product SEO Module</h1>
          <p className="text-xs text-gray-500 mt-0.5">Optimize product titles, meta descriptions, and handles.</p>
          <button
            onClick={() => setShowHowToUse(true)}
            className="text-xs text-[#003087] hover:text-[#002566] font-semibold inline-flex items-center gap-1 mt-1 hover:underline cursor-pointer"
          >
            <Info className="w-3.5 h-3.5" /> How to use this page
          </button>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {/* View toggle */}
          <div className="bg-gray-100 p-1 rounded-lg border border-gray-200 flex items-center gap-1 mr-1">
            <button
              onClick={() => setViewMode('grid')}
              className={`p-1.5 rounded text-xs flex items-center gap-1 font-medium transition cursor-pointer ${
                viewMode === 'grid' ? 'bg-white shadow-xs text-[#003087] font-bold' : 'text-gray-600 hover:text-gray-900'
              }`}
              title="Grid View"
            >
              <LayoutGrid className="w-3.5 h-3.5" />
            </button>
            <button
              onClick={() => setViewMode('table')}
              className={`p-1.5 rounded text-xs flex items-center gap-1 font-medium transition cursor-pointer ${
                viewMode === 'table' ? 'bg-white shadow-xs text-[#003087] font-bold' : 'text-gray-600 hover:text-gray-900'
              }`}
              title="Table View"
            >
              <TableIcon className="w-3.5 h-3.5" />
            </button>
          </div>

          {/* Sync Products Button (Yellow Button matching screenshot) */}
          <button
            onClick={onSync}
            disabled={isSyncing}
            className="px-4 py-2 bg-[#FFCC00] hover:bg-[#f5c200] border border-amber-400 text-gray-900 font-bold rounded-lg text-xs transition shadow-sm flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
            title="Fetch product image, name, URL, title, description, and handle from Shopify API"
          >
            <RotateCw className={`w-3.5 h-3.5 text-gray-900 ${isSyncing ? 'animate-spin' : ''}`} />
            {isSyncing ? 'Syncing...' : 'Sync Products'}
          </button>

          {/* Bulk Approve & Push (Green Button matching screenshot) */}
          <button
            onClick={() => onPushShopify(draftItems.map(p => p.id))}
            disabled={draftItems.length === 0}
            className="px-4 py-2 bg-[#16a34a] hover:bg-[#15803d] disabled:bg-gray-200 disabled:text-gray-400 text-white font-bold rounded-lg text-xs transition shadow-sm flex items-center gap-1.5 cursor-pointer"
          >
            <CheckCircle2 className="w-4 h-4" />
            Bulk Approve & Push ({draftItems.length})
          </button>
        </div>
      </div>

      {/* Filter / Search Bar */}
      <div className="bg-white p-3.5 rounded-xl border border-gray-100 shadow-sm flex flex-col md:flex-row items-center gap-3">
        <div className="relative flex-1 w-full">
          <input
            type="text"
            value={searchTerm}
            onChange={e => {
              setSearchTerm(e.target.value);
              setCurrentPage(1);
            }}
            placeholder="Search title or handle..."
            className="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-[#003087] focus:border-transparent outline-none bg-[#f8fafc]"
          />
          <Search className="w-4 h-4 text-gray-400 absolute left-3 top-2.5" />
        </div>

        <div className="w-full md:w-56">
          <select
            value={statusFilter}
            onChange={e => {
              setStatusFilter(e.target.value);
              setCurrentPage(1);
            }}
            className="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-700 focus:ring-2 focus:ring-[#003087] outline-none"
          >
            <option value="All Statuses">All Statuses</option>
            <option value="Draft">Draft</option>
            <option value="Published">Published</option>
            <option value="Needs Optimization">Needs Optimization</option>
          </select>
        </div>

        <button
          onClick={() => setCurrentPage(1)}
          className="w-full md:w-auto px-5 py-2 bg-[#003087] hover:bg-[#002566] text-white font-bold rounded-lg text-xs transition shadow-xs cursor-pointer"
        >
          Search
        </button>
      </div>

      {/* Pagination Status Top Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between text-xs text-gray-500 px-1 gap-2">
        <div>
          Showing <span className="font-bold text-gray-800">{totalItems > 0 ? startIndex + 1 : 0}</span> to{' '}
          <span className="font-bold text-gray-800">{Math.min(startIndex + itemsPerPage, totalItems)}</span> of{' '}
          <span className="font-bold text-[#003087]">{totalItems}</span> products (
          <span className="font-semibold text-gray-700">20 per page</span>)
        </div>
        <div>
          Page <span className="font-bold text-gray-800">{validCurrentPage}</span> of{' '}
          <span className="font-bold text-gray-800">{totalPages}</span>
        </div>
      </div>

      {/* 2-Column Responsive Card Grid matching screenshot */}
      {viewMode === 'grid' ? (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
          {paginatedProducts.map(product => {
            const data = getItemData(product);
            const seo = evaluateSeo(data.title, data.metaDescription, data.handle);
            const isSaved = savedNotifications[product.id];
            const isSerpOpen = activeSerpId === product.id;

            return (
              <div
                key={product.id}
                className="bg-white rounded-xl border border-slate-200 shadow-sm hover:shadow-md transition flex flex-col overflow-hidden border-t-4 border-t-emerald-600"
              >
                {/* Card Top Title & Status */}
                <div className="px-5 py-3.5 bg-white border-b border-slate-200 flex items-center justify-between gap-2">
                  <h3 className="font-bold text-slate-800 text-sm truncate flex-1" title={data.title}>
                    {data.title}
                  </h3>
                  <div className="flex items-center gap-1.5 shrink-0">
                    <span
                      className={`text-[11px] font-bold px-2.5 py-0.5 rounded-full ${
                        data.status === 'published'
                          ? 'bg-emerald-100 text-emerald-800 border border-emerald-300'
                          : data.status === 'needs_optimization'
                          ? 'bg-amber-100 text-amber-800 border border-amber-300'
                          : 'bg-emerald-600 text-white'
                      }`}
                    >
                      {data.status === 'published' ? 'Published' : data.status === 'needs_optimization' ? 'Needs Fix' : 'Draft'}
                    </span>
                    <span
                      className={`text-[10px] font-bold px-2 py-0.5 rounded border ${seo.color}`}
                      title={`SEO Health Score: ${seo.score}%`}
                    >
                      {seo.score}% Health
                    </span>
                  </div>
                </div>

                {/* Card Body */}
                <div className="p-5 space-y-4 flex-1">
                  {/* READ-ONLY Product Image & Info Box */}
                  <div className="flex items-center gap-3.5 p-2.5 bg-slate-50 rounded-lg border border-slate-200">
                    <img
                      src={data.image || data.imageUrl || 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=200&auto=format&fit=crop&q=80'}
                      alt={data.title}
                      referrerPolicy="no-referrer"
                      loading="lazy"
                      onError={(e) => {
                        (e.target as HTMLImageElement).src = 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=200&auto=format&fit=crop&q=80';
                      }}
                      className="w-16 h-16 rounded-md object-cover border border-slate-300 shrink-0 bg-white"
                    />
                    <div className="text-xs space-y-1 min-w-0 flex-1">
                      <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">PRODUCT IMAGE</div>
                      <div className="font-semibold text-slate-800 truncate">
                        <strong>Name:</strong> {data.imageName || `${data.handle}.jpg`}
                      </div>
                      <div className="text-[11px] text-blue-600 truncate">
                        <strong>URL:</strong>{' '}
                        <a
                          href={data.imageUrl || data.image || `https://uratex.com.ph/products/${data.handle}`}
                          target="_blank"
                          rel="noreferrer"
                          className="hover:underline text-blue-700 font-medium"
                        >
                          {data.imageUrl || data.image || `${data.handle}.jpg`}
                        </a>
                      </div>
                    </div>
                  </div>

                  {/* EDITABLE FIELD 1: Page Title Field with Character Limit Indicator */}
                  <div>
                    <div className="flex justify-between items-center mb-1">
                      <label className="text-xs font-bold text-slate-700">Page Title</label>
                      <div className="flex items-center gap-2">
                        <span
                          className={`text-[11px] font-semibold ${
                            data.title.length >= 50 && data.title.length <= 60
                              ? 'text-emerald-700 font-bold'
                              : data.title.length < 35 || data.title.length > 65
                              ? 'text-amber-700'
                              : 'text-slate-500'
                          }`}
                        >
                          {data.title.length} / 60 chars
                        </span>
                        <div className="w-12 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                          <div
                            className={`h-full ${
                              data.title.length >= 50 && data.title.length <= 60
                                ? 'bg-emerald-500'
                                : data.title.length > 65
                                ? 'bg-rose-500'
                                : 'bg-amber-400'
                            }`}
                            style={{ width: `${Math.min(100, (data.title.length / 60) * 100)}%` }}
                          ></div>
                        </div>
                      </div>
                    </div>
                    <input
                      type="text"
                      value={data.title}
                      onChange={e => handleFieldChange(product.id, 'title', e.target.value)}
                      className="w-full px-3 py-2 border border-slate-300 rounded-lg text-xs font-semibold text-slate-900 focus:ring-2 focus:ring-[#003087] outline-none"
                    />
                    {seo.titleStatus !== 'optimal' && (
                      <p className="text-[11px] text-amber-700 mt-1 flex items-center gap-1">
                        <AlertTriangle className="w-3 h-3 text-amber-600" />
                        {seo.titleRecommendation}
                      </p>
                    )}
                  </div>

                  {/* EDITABLE FIELD 2: Meta Description Field with Character Limit Indicator */}
                  <div>
                    <div className="flex justify-between items-center mb-1">
                      <label className="text-xs font-bold text-slate-700">Meta Description</label>
                      <div className="flex items-center gap-2">
                        <span
                          className={`text-[11px] font-semibold ${
                            data.metaDescription.length >= 135 && data.metaDescription.length <= 155
                              ? 'text-emerald-700 font-bold'
                              : data.metaDescription.length < 90 || data.metaDescription.length > 165
                              ? 'text-amber-700'
                              : 'text-slate-500'
                          }`}
                        >
                          {data.metaDescription.length} / 160 chars
                        </span>
                        <div className="w-12 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                          <div
                            className={`h-full ${
                              data.metaDescription.length >= 135 && data.metaDescription.length <= 155
                                ? 'bg-emerald-500'
                                : data.metaDescription.length > 165
                                ? 'bg-rose-500'
                                : 'bg-amber-400'
                            }`}
                            style={{ width: `${Math.min(100, (data.metaDescription.length / 160) * 100)}%` }}
                          ></div>
                        </div>
                      </div>
                    </div>
                    <textarea
                      rows={3}
                      value={data.metaDescription}
                      onChange={e => handleFieldChange(product.id, 'metaDescription', e.target.value)}
                      className="w-full px-3 py-2 border border-slate-300 rounded-lg text-xs text-slate-800 focus:ring-2 focus:ring-[#003087] outline-none resize-y"
                    />
                    {seo.descStatus !== 'optimal' && (
                      <p className="text-[11px] text-amber-700 mt-1 flex items-center gap-1">
                        <AlertTriangle className="w-3 h-3 text-amber-600" />
                        {seo.descRecommendation}
                      </p>
                    )}
                  </div>

                  {/* EDITABLE FIELD 3: URL Handle Field */}
                  <div>
                    <label className="text-xs font-bold text-slate-700 block mb-1">URL Handle</label>
                    <input
                      type="text"
                      value={data.handle}
                      onChange={e => handleFieldChange(product.id, 'handle', e.target.value)}
                      className="w-full px-3 py-2 border border-slate-300 rounded-lg text-xs font-mono text-slate-800 focus:ring-2 focus:ring-[#003087] outline-none"
                    />
                  </div>

                  {/* SERP Preview Accordion */}
                  {isSerpOpen && (
                    <div className="pt-2">
                      <SerpPreview
                        title={data.title}
                        metaDescription={data.metaDescription}
                        handle={data.handle}
                        domain={storeConfig.domain}
                        itemType="products"
                      />
                    </div>
                  )}
                </div>

                {/* Card Footer Actions */}
                <div className="px-5 py-3 bg-slate-50 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => handleSave(product)}
                      className={`px-3.5 py-1.5 rounded-lg text-xs font-bold transition border flex items-center gap-1.5 cursor-pointer ${
                        isSaved
                          ? 'bg-emerald-600 text-white border-emerald-600'
                          : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-100'
                      }`}
                    >
                      <Save className="w-3.5 h-3.5" />
                      {isSaved ? 'Saved Draft!' : 'Save Draft'}
                    </button>

                    <button
                      onClick={() => setActiveSerpId(isSerpOpen ? null : product.id)}
                      className="px-3 py-1.5 bg-white hover:bg-slate-100 text-slate-600 border border-slate-300 rounded-lg text-xs font-medium transition flex items-center gap-1 cursor-pointer"
                      title="Toggle SERP Preview"
                    >
                      <Eye className="w-3.5 h-3.5" />
                      SERP
                    </button>
                  </div>

                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => setAiModalItem(data)}
                      className="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded-lg text-xs font-bold transition flex items-center gap-1 shadow-xs cursor-pointer"
                      title="Optimize with Gemini 3.7 Flash"
                    >
                      <Sparkles className="w-3.5 h-3.5 text-amber-500" />
                      AI Optimize
                    </button>

                    <button
                      onClick={() => handlePushSingle(product)}
                      className="px-3.5 py-1.5 bg-[#003087] hover:bg-[#002277] text-white rounded-lg text-xs font-bold transition shadow-sm flex items-center gap-1.5 cursor-pointer"
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
      ) : (
        /* Table View */
        <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 text-slate-700 uppercase font-bold border-b border-slate-200">
                <tr>
                  <th className="px-4 py-3">Product</th>
                  <th className="px-4 py-3">Page Title (50-60 optimal)</th>
                  <th className="px-4 py-3">Meta Description (120-160)</th>
                  <th className="px-4 py-3">Handle</th>
                  <th className="px-4 py-3">SEO Health</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 text-slate-700">
                {paginatedProducts.map(p => {
                  const data = getItemData(p);
                  const seo = evaluateSeo(data.title, data.metaDescription, data.handle);
                  return (
                    <tr key={p.id} className="hover:bg-slate-50/80 transition">
                      <td className="px-4 py-3 flex items-center gap-3">
                        <img
                          src={data.image || data.imageUrl || 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=200&auto=format&fit=crop&q=80'}
                          alt={data.title}
                          referrerPolicy="no-referrer"
                          loading="lazy"
                          onError={(e) => {
                            (e.target as HTMLImageElement).src = 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=200&auto=format&fit=crop&q=80';
                          }}
                          className="w-10 h-10 rounded object-cover border border-slate-200 shrink-0 bg-white"
                        />
                        <div className="min-w-0">
                          <div className="font-bold text-slate-900 truncate max-w-[180px]">{data.title}</div>
                          <span className="text-[10px] text-slate-500">{data.category || 'Product'}</span>
                        </div>
                      </td>
                      <td className="px-4 py-3 max-w-xs">
                        <input
                          type="text"
                          value={data.title}
                          onChange={e => handleFieldChange(p.id, 'title', e.target.value)}
                          className="w-full px-2 py-1 border border-slate-300 rounded text-xs font-semibold"
                        />
                        <span className="text-[10px] text-slate-400">{data.title.length}/60 chars</span>
                      </td>
                      <td className="px-4 py-3 max-w-sm">
                        <textarea
                          rows={2}
                          value={data.metaDescription}
                          onChange={e => handleFieldChange(p.id, 'metaDescription', e.target.value)}
                          className="w-full px-2 py-1 border border-slate-300 rounded text-xs"
                        />
                        <span className="text-[10px] text-slate-400">{data.metaDescription.length}/160 chars</span>
                      </td>
                      <td className="px-4 py-3 font-mono text-[11px] text-slate-600">
                        {data.handle}
                      </td>
                      <td className="px-4 py-3">
                        <span className={`px-2 py-0.5 rounded font-bold border text-[11px] ${seo.color}`}>
                          {seo.score}%
                        </span>
                      </td>
                      <td className="px-4 py-3 text-right whitespace-nowrap space-x-1">
                        <button
                          onClick={() => handleSave(p)}
                          className="px-2.5 py-1 bg-white border border-slate-300 hover:bg-slate-100 rounded font-semibold text-slate-700 cursor-pointer"
                        >
                          Save
                        </button>
                        <button
                          onClick={() => setAiModalItem(data)}
                          className="px-2 py-1 bg-indigo-50 border border-indigo-200 text-indigo-700 hover:bg-indigo-100 rounded font-semibold cursor-pointer"
                        >
                          AI
                        </button>
                        <button
                          onClick={() => handlePushSingle(p)}
                          className="px-2.5 py-1 bg-[#003087] text-white hover:bg-[#002277] rounded font-bold cursor-pointer"
                        >
                          Push
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* 20 PRODUCTS PER PAGE PAGINATION COMPONENT */}
      {totalPages > 1 && (
        <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-4 flex flex-col lg:flex-row items-center justify-between gap-3.5">
          {/* Summary Text */}
          <div className="text-xs text-gray-600 flex flex-wrap items-center justify-center sm:justify-start gap-1.5 text-center sm:text-left">
            <span>Showing</span>
            <strong className="text-gray-900 font-bold">{startIndex + 1}</strong>
            <span>to</span>
            <strong className="text-gray-900 font-bold">{Math.min(startIndex + itemsPerPage, totalItems)}</strong>
            <span>of</span>
            <strong className="text-[#003087] font-bold">{totalItems}</strong>
            <span>products</span>
            <span className="inline-block bg-blue-50 text-[#003087] font-bold px-2 py-0.5 rounded text-[11px] ml-1 border border-blue-100">
              Page {validCurrentPage} of {totalPages}
            </span>
          </div>

          {/* Navigation Controls */}
          <div className="flex flex-wrap items-center justify-center sm:justify-end gap-1.5 max-w-full">
            {/* First Page Button */}
            <button
              onClick={() => handlePageChange(1)}
              disabled={validCurrentPage <= 1}
              title="First Page"
              className="px-2.5 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer transition hidden sm:inline-flex items-center"
            >
              &laquo;&laquo; First
            </button>

            {/* Prev Button */}
            <button
              onClick={() => handlePageChange(validCurrentPage - 1)}
              disabled={validCurrentPage <= 1}
              className="px-3 py-1.5 rounded-lg border border-gray-300 text-xs font-semibold text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer transition flex items-center gap-1"
            >
              &laquo; Prev
            </button>

            {/* Page Numbers & Ellipses */}
            {getPaginationItems(validCurrentPage, totalPages).map((item, idx) => {
              if (item === '...') {
                return (
                  <span
                    key={`ellipsis-${idx}`}
                    className="min-w-[28px] h-8 flex items-center justify-center text-xs font-bold text-gray-400 select-none"
                  >
                    …
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
                      : 'bg-white text-gray-700 border border-gray-200 hover:bg-gray-50'
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
              className="px-3 py-1.5 rounded-lg border border-gray-300 text-xs font-semibold text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer transition flex items-center gap-1"
            >
              Next &raquo;
            </button>

            {/* Last Page Button */}
            <button
              onClick={() => handlePageChange(totalPages)}
              disabled={validCurrentPage >= totalPages}
              title="Last Page"
              className="px-2.5 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer transition hidden sm:inline-flex items-center"
            >
              Last &raquo;&raquo;
            </button>

            {/* Quick Page Jump Dropdown */}
            <div className="flex items-center gap-1.5 ml-1 pl-2 border-l border-gray-200">
              <label htmlFor="jump-page-select" className="text-[11px] text-gray-500 font-medium whitespace-nowrap hidden md:inline">
                Jump to:
              </label>
              <select
                id="jump-page-select"
                value={validCurrentPage}
                onChange={e => handlePageChange(Number(e.target.value))}
                className="bg-white border border-gray-300 text-gray-700 text-xs font-semibold rounded-lg px-2 py-1.5 outline-none cursor-pointer hover:border-gray-400"
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

      {/* Modals */}
      <HowToUseModal isOpen={showHowToUse} onClose={() => setShowHowToUse(false)} />

      <AiOptimizeModal
        isOpen={!!aiModalItem}
        onClose={() => setAiModalItem(null)}
        item={aiModalItem}
        itemType="Product"
        onApply={opt => {
          if (aiModalItem) {
            handleFieldChange(aiModalItem.id, 'title', opt.title);
            handleFieldChange(aiModalItem.id, 'metaDescription', opt.metaDescription);
            handleFieldChange(aiModalItem.id, 'handle', opt.handle);
            if (opt.focusKeyword) {
              handleFieldChange(aiModalItem.id, 'focusKeyword', opt.focusKeyword);
            }
          }
        }}
      />
    </div>
  );
};

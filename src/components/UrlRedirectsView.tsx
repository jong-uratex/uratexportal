import React, { useState } from 'react';
import { ArrowRight, Plus, Trash2, CheckCircle2, Search, ExternalLink } from 'lucide-react';
import { RedirectItem, ShopStoreConfig } from '../types';

interface UrlRedirectsViewProps {
  redirects: RedirectItem[];
  storeConfig: ShopStoreConfig;
}

export const UrlRedirectsView: React.FC<UrlRedirectsViewProps> = ({ redirects, storeConfig }) => {
  const [list, setList] = useState<RedirectItem[]>(redirects);
  const [fromUrl, setFromUrl] = useState('');
  const [toUrl, setToUrl] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [added, setAdded] = useState(false);

  const handleAdd = (e: React.FormEvent) => {
    e.preventDefault();
    if (!fromUrl || !toUrl) return;

    const newRed: RedirectItem = {
      id: `red-${Date.now()}`,
      from: fromUrl.startsWith('/') ? fromUrl : `/${fromUrl}`,
      to: toUrl.startsWith('/') ? toUrl : `/${toUrl}`,
      type: '301 Moved Permanently',
      hits: 0,
    };

    setList([newRed, ...list]);
    setFromUrl('');
    setToUrl('');
    setAdded(true);
    setTimeout(() => setAdded(false), 2500);
  };

  const handleDelete = (id: string) => {
    setList(list.filter(r => r.id !== id));
  };

  const filtered = list.filter(
    r => r.from.toLowerCase().includes(searchTerm.toLowerCase()) || r.to.toLowerCase().includes(searchTerm.toLowerCase())
  );

  return (
    <div className="space-y-5 animate-fadeIn">
      <div className="border-b border-slate-200 pb-4">
        <h1 className="text-2xl font-bold text-[#003399] tracking-tight">URL 301 Redirects Manager</h1>
        <p className="text-xs text-slate-500 mt-0.5">
          Safeguard SEO equity and prevent 404 errors when modifying product, collection, or page handles in {storeConfig.name}.
        </p>
      </div>

      {/* Add New Redirect Form */}
      <div className="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
        <h3 className="text-sm font-bold text-slate-800 mb-3 flex items-center gap-2">
          <Plus className="w-4 h-4 text-blue-600" />
          Create New 301 Permanent Redirect
        </h3>
        <form onSubmit={handleAdd} className="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
          <div className="sm:col-span-5">
            <label className="text-xs font-semibold text-slate-700 block mb-1">Old Path (Redirect From):</label>
            <input
              type="text"
              placeholder="/products/old-mattress-handle"
              value={fromUrl}
              onChange={e => setFromUrl(e.target.value)}
              className="w-full px-3 py-2 border border-slate-300 rounded-lg text-xs font-mono focus:ring-2 focus:ring-[#003399] outline-none"
              required
            />
          </div>

          <div className="sm:col-span-5">
            <label className="text-xs font-semibold text-slate-700 block mb-1">Target Path (Redirect To):</label>
            <input
              type="text"
              placeholder="/products/uratex-premium-viscoluxe"
              value={toUrl}
              onChange={e => setToUrl(e.target.value)}
              className="w-full px-3 py-2 border border-slate-300 rounded-lg text-xs font-mono focus:ring-2 focus:ring-[#003399] outline-none"
              required
            />
          </div>

          <div className="sm:col-span-2">
            <button
              type="submit"
              className="w-full py-2 bg-[#003399] hover:bg-[#002277] text-white font-bold rounded-lg text-xs transition shadow flex items-center justify-center gap-1"
            >
              <Plus className="w-4 h-4" />
              Add 301
            </button>
          </div>
        </form>

        {added && (
          <div className="mt-3 text-xs text-emerald-700 bg-emerald-50 p-2.5 rounded-lg border border-emerald-200 flex items-center gap-2">
            <CheckCircle2 className="w-4 h-4 text-emerald-600" />
            301 Redirect deployed to Shopify router.
          </div>
        )}
      </div>

      {/* Redirects Table */}
      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="p-3.5 border-b border-slate-200 flex items-center gap-3">
          <div className="relative flex-1">
            <input
              type="text"
              placeholder="Search redirect routes..."
              value={searchTerm}
              onChange={e => setSearchTerm(e.target.value)}
              className="w-full pl-9 pr-4 py-1.5 border border-slate-300 rounded-lg text-xs outline-none"
            />
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2" />
          </div>
          <span className="text-xs text-slate-500 font-semibold">{filtered.length} active redirects</span>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 text-slate-700 uppercase font-bold border-b border-slate-200">
              <tr>
                <th className="px-4 py-3">Old URL Route</th>
                <th className="px-4 py-3"></th>
                <th className="px-4 py-3">Destination URL Route</th>
                <th className="px-4 py-3">Type</th>
                <th className="px-4 py-3">Traffic Hits</th>
                <th className="px-4 py-3 text-right">Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200 text-slate-700">
              {filtered.map(item => (
                <tr key={item.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3 font-mono text-slate-800 font-semibold">{item.from}</td>
                  <td className="px-2 py-3 text-slate-400">
                    <ArrowRight className="w-4 h-4" />
                  </td>
                  <td className="px-4 py-3 font-mono text-blue-700 font-semibold">{item.to}</td>
                  <td className="px-4 py-3">
                    <span className="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded font-bold text-[10px]">
                      {item.type}
                    </span>
                  </td>
                  <td className="px-4 py-3 font-bold text-slate-700">{item.hits} hits</td>
                  <td className="px-4 py-3 text-right">
                    <button
                      onClick={() => handleDelete(item.id)}
                      className="p-1.5 text-slate-400 hover:text-rose-600 rounded transition"
                      title="Remove redirect"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};

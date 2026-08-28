import React, { useState } from 'react';
import { Smartphone, Monitor, Globe } from 'lucide-react';

interface SerpPreviewProps {
  title: string;
  metaDescription: string;
  handle: string;
  domain?: string;
  itemType?: string;
}

export const SerpPreview: React.FC<SerpPreviewProps> = ({
  title,
  metaDescription,
  handle,
  domain = 'uratex.com.ph',
  itemType = 'products',
}) => {
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');

  const displayTitle = title || 'Untitled Page - Uratex Philippines';
  const displayDesc = metaDescription || 'No meta description set. Search engines will extract text from page content which might be unformatted or cut off.';
  const displaySlug = handle || 'page-slug';

  return (
    <div className="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs">
      <div className="flex items-center justify-between pb-2 mb-2 border-b border-slate-200">
        <div className="font-bold text-slate-700 uppercase flex items-center gap-1.5 text-[11px]">
          <Globe className="w-3.5 h-3.5 text-blue-600" />
          <span>Live Google SERP Snippet Simulator</span>
        </div>
        <div className="flex items-center gap-1 bg-white p-0.5 rounded border border-slate-200">
          <button
            onClick={() => setDevice('desktop')}
            className={`px-2 py-1 rounded flex items-center gap-1 transition ${
              device === 'desktop' ? 'bg-[#003399] text-white font-bold' : 'text-slate-600 hover:text-slate-900'
            }`}
            title="Desktop Google Preview"
          >
            <Monitor className="w-3 h-3" />
            <span className="text-[10px]">Desktop</span>
          </button>
          <button
            onClick={() => setDevice('mobile')}
            className={`px-2 py-1 rounded flex items-center gap-1 transition ${
              device === 'mobile' ? 'bg-[#003399] text-white font-bold' : 'text-slate-600 hover:text-slate-900'
            }`}
            title="Mobile Google Preview"
          >
            <Smartphone className="w-3 h-3" />
            <span className="text-[10px]">Mobile</span>
          </button>
        </div>
      </div>

      <div className={`bg-white p-3 rounded-lg border border-slate-200 shadow-sm ${device === 'mobile' ? 'max-w-[340px] mx-auto' : 'w-full'}`}>
        <div className="text-[11px] text-slate-600 flex items-center gap-1.5 mb-0.5 truncate">
          <div className="w-4 h-4 rounded-full bg-[#003399] text-yellow-300 flex items-center justify-center font-bold text-[9px] shrink-0">
            U
          </div>
          <span className="text-slate-800 font-semibold">{domain}</span>
          <span className="text-slate-400">› {itemType} › {displaySlug}</span>
        </div>
        <h4 className="text-[#1a0dab] hover:underline text-sm font-medium leading-snug cursor-pointer line-clamp-2 mt-0.5 mb-1">
          {displayTitle}
        </h4>
        <p className="text-[11px] text-[#4d5156] leading-relaxed line-clamp-3">
          {displayDesc}
        </p>
      </div>
    </div>
  );
};

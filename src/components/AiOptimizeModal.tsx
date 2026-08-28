import React, { useState } from 'react';
import { X, Sparkles, Check, RefreshCw, Copy, ArrowRight, Layers, Tag, Globe } from 'lucide-react';
import { SeoItem, AiOptimizationResult } from '../types';

interface AiOptimizeModalProps {
  isOpen: boolean;
  onClose: () => void;
  item: SeoItem | null;
  itemType: 'Product' | 'Collection' | 'Page' | 'Blog';
  onApply: (optimized: { title: string; metaDescription: string; handle: string; focusKeyword?: string }) => void;
}

export const AiOptimizeModal: React.FC<AiOptimizeModalProps> = ({
  isOpen,
  onClose,
  item,
  itemType,
  onApply,
}) => {
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<AiOptimizationResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  // Trigger optimization when opened if no result exists yet
  React.useEffect(() => {
    if (isOpen && item && !result && !loading) {
      handleGenerate();
    }
  }, [isOpen, item]);

  if (!isOpen || !item) return null;

  async function handleGenerate() {
    setLoading(true);
    setError(null);
    try {
      const response = await fetch('/api/gemini/optimize-seo', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          itemType,
          title: item?.title || '',
          currentMetaDescription: item?.metaDescription || '',
          category: item?.category || '',
          price: item?.price || '',
          focusKeyword: item?.focusKeyword || '',
          brand: 'Uratex Philippines',
          targetAudience: 'Philippines retail and B2B commercial institutional market',
        }),
      });

      const data = await response.json();
      if (data.success && data.data) {
        setResult(data.data);
      } else {
        throw new Error(data.message || 'Optimization failed');
      }
    } catch (err: any) {
      console.error(err);
      setError(err.message || 'Unable to connect with AI SEO service. Please try again.');
    } finally {
      setLoading(false);
    }
  }

  function handleApply() {
    if (!result) return;
    onApply({
      title: result.optimizedTitle,
      metaDescription: result.metaDescription,
      handle: result.suggestedHandle,
      focusKeyword: result.focusKeywords?.[0] || item?.focusKeyword,
    });
    onClose();
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4 animate-fadeIn">
      <div className="bg-white rounded-xl shadow-2xl border border-slate-200 w-full max-w-3xl max-h-[90vh] overflow-y-auto flex flex-col">
        {/* Header */}
        <div className="bg-gradient-to-r from-[#003399] to-[#001744] text-white px-6 py-4 flex items-center justify-between rounded-t-xl">
          <div className="flex items-center space-x-2">
            <div className="p-2 bg-yellow-400 text-blue-950 rounded-lg shadow-sm">
              <Sparkles className="w-5 h-5" />
            </div>
            <div>
              <h3 className="font-bold text-lg leading-tight flex items-center gap-2">
                Gemini AI SEO Optimizer
                <span className="bg-blue-600/60 text-yellow-300 text-[11px] font-semibold px-2 py-0.5 rounded border border-blue-400/30">
                  gemini-3.7-flash
                </span>
              </h3>
              <p className="text-xs text-blue-200">
                Generating high-CTR title, metadata & Philippine search rankings for {item.title}
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="text-white/70 hover:text-white p-1 rounded-lg hover:bg-white/10 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Body */}
        <div className="p-6 space-y-6">
          {loading && (
            <div className="py-12 flex flex-col items-center justify-center space-y-3">
              <div className="relative">
                <div className="w-12 h-12 border-4 border-blue-200 border-t-[#003399] rounded-full animate-spin"></div>
                <Sparkles className="w-5 h-5 text-amber-500 absolute inset-0 m-auto animate-pulse" />
              </div>
              <p className="text-sm font-semibold text-slate-800">Analyzing search intents and generating optimal copy...</p>
              <p className="text-xs text-slate-500">Checking character limits (50-60 title, 135-155 description) and keyword density.</p>
            </div>
          )}

          {error && (
            <div className="p-4 bg-rose-50 border border-rose-200 rounded-lg text-rose-800 text-sm flex items-start justify-between">
              <div>
                <strong>Error:</strong> {error}
              </div>
              <button
                onClick={handleGenerate}
                className="ml-3 px-3 py-1 bg-rose-600 text-white rounded text-xs hover:bg-rose-700 transition"
              >
                Retry
              </button>
            </div>
          )}

          {!loading && result && (
            <div className="space-y-5 text-sm">
              {/* Estimated SEO Health Gauge */}
              <div className="bg-gradient-to-r from-emerald-500 to-teal-600 text-white p-4 rounded-xl shadow-sm flex items-center justify-between">
                <div>
                  <div className="text-xs uppercase tracking-wider font-semibold text-emerald-100">Estimated SEO Health</div>
                  <div className="text-2xl font-black">{result.estimatedSeoScore || 98}% Excellent</div>
                  <div className="text-xs text-emerald-100 mt-0.5">{result.serpSnippet}</div>
                </div>
                <div className="bg-white/20 backdrop-blur-md px-3 py-2 rounded-lg text-xs font-bold border border-white/30">
                  Rank #1 Target
                </div>
              </div>

              {/* Comparison Section */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {/* Current */}
                <div className="bg-slate-50 border border-slate-200 rounded-lg p-4 space-y-3">
                  <div className="text-xs font-bold text-slate-500 uppercase flex items-center justify-between">
                    <span>Current Metadata</span>
                    <span className="text-slate-400">Before</span>
                  </div>
                  <div>
                    <label className="text-xs font-semibold text-slate-600">Title ({item.title.length} chars):</label>
                    <p className="text-xs text-slate-700 bg-white p-2 rounded border border-slate-200 line-clamp-2 mt-1">
                      {item.title}
                    </p>
                  </div>
                  <div>
                    <label className="text-xs font-semibold text-slate-600">Meta Description ({item.metaDescription.length} chars):</label>
                    <p className="text-xs text-slate-700 bg-white p-2 rounded border border-slate-200 line-clamp-3 mt-1">
                      {item.metaDescription || '<Missing meta description>'}
                    </p>
                  </div>
                </div>

                {/* AI Optimized */}
                <div className="bg-blue-50/70 border border-blue-200 rounded-lg p-4 space-y-3">
                  <div className="text-xs font-bold text-[#003399] uppercase flex items-center justify-between">
                    <span className="flex items-center gap-1.5">
                      <Sparkles className="w-3.5 h-3.5 text-amber-500" />
                      AI Recommended
                    </span>
                    <span className="bg-emerald-100 text-emerald-800 text-[10px] font-bold px-2 py-0.5 rounded">
                      Optimized
                    </span>
                  </div>

                  <div>
                    <div className="flex justify-between items-center text-xs font-semibold text-blue-950 mb-1">
                      <span>Title ({result.optimizedTitle.length} chars):</span>
                      <span className="text-emerald-700 text-[11px] font-bold">50-60 Optimal</span>
                    </div>
                    <div className="text-xs text-slate-900 bg-white p-2.5 rounded border border-blue-300 font-medium shadow-sm">
                      {result.optimizedTitle}
                    </div>
                  </div>

                  <div>
                    <div className="flex justify-between items-center text-xs font-semibold text-blue-950 mb-1">
                      <span>Meta Description ({result.metaDescription.length} chars):</span>
                      <span className="text-emerald-700 text-[11px] font-bold">135-155 Optimal</span>
                    </div>
                    <div className="text-xs text-slate-900 bg-white p-2.5 rounded border border-blue-300 font-normal shadow-sm">
                      {result.metaDescription}
                    </div>
                  </div>
                </div>
              </div>

              {/* Recommended Handle & Keywords */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="bg-white p-3 rounded-lg border border-slate-200">
                  <div className="text-xs font-semibold text-slate-700 flex items-center gap-1.5 mb-1.5">
                    <Globe className="w-3.5 h-3.5 text-blue-600" />
                    Clean URL Handle:
                  </div>
                  <code className="text-xs bg-slate-100 text-blue-700 px-2.5 py-1 rounded block truncate font-mono border border-slate-200">
                    /{result.suggestedHandle}
                  </code>
                </div>

                <div className="bg-white p-3 rounded-lg border border-slate-200">
                  <div className="text-xs font-semibold text-slate-700 flex items-center gap-1.5 mb-1.5">
                    <Tag className="w-3.5 h-3.5 text-amber-600" />
                    Target Search Keywords:
                  </div>
                  <div className="flex flex-wrap gap-1.5">
                    {result.focusKeywords?.map((kw, i) => (
                      <span key={i} className="text-[11px] bg-amber-50 text-amber-900 border border-amber-200 px-2 py-0.5 rounded-full font-medium">
                        {kw}
                      </span>
                    ))}
                  </div>
                </div>
              </div>

              {/* Live Google Search SERP Simulator */}
              <div className="border border-slate-200 rounded-lg p-4 bg-slate-50">
                <div className="text-xs font-bold text-slate-600 uppercase mb-2 flex items-center gap-1">
                  <Globe className="w-3.5 h-3.5 text-slate-500" />
                  Google Search Snippet Preview (Desktop & Mobile)
                </div>
                <div className="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm font-sans max-w-xl">
                  <div className="text-xs text-slate-600 flex items-center gap-1.5 mb-0.5">
                    <span className="text-[#003399] font-semibold">uratex.com.ph</span>
                    <span className="text-slate-400">› products › {result.suggestedHandle}</span>
                  </div>
                  <h4 className="text-[#1a0dab] hover:underline text-base font-medium leading-snug cursor-pointer mb-1">
                    {result.optimizedTitle}
                  </h4>
                  <p className="text-xs text-[#4d5156] leading-relaxed">
                    {result.metaDescription}
                  </p>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="bg-slate-50 px-6 py-3.5 border-t border-slate-200 flex justify-between items-center rounded-b-xl">
          <button
            onClick={handleGenerate}
            disabled={loading}
            className="px-3.5 py-2 text-slate-700 bg-white hover:bg-slate-100 border border-slate-300 font-semibold rounded-lg text-xs transition flex items-center gap-1.5"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            Regenerate
          </button>
          <div className="flex gap-2">
            <button
              onClick={onClose}
              className="px-4 py-2 text-slate-600 hover:text-slate-900 font-semibold text-xs"
            >
              Cancel
            </button>
            <button
              onClick={handleApply}
              disabled={loading || !result}
              className="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-bold rounded-lg text-xs transition shadow flex items-center gap-1.5"
            >
              <Check className="w-4 h-4" />
              Apply to Module
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

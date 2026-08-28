import React from 'react';
import { X, CheckCircle2, AlertTriangle, Sparkles, Search, Layers, RefreshCw, UploadCloud } from 'lucide-react';

interface HowToUseModalProps {
  isOpen: boolean;
  onClose: () => void;
}

export const HowToUseModal: React.FC<HowToUseModalProps> = ({ isOpen, onClose }) => {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4 animate-fadeIn">
      <div className="bg-white rounded-xl shadow-2xl border border-slate-200 w-full max-w-2xl max-h-[90vh] overflow-y-auto flex flex-col">
        {/* Header */}
        <div className="bg-[#003399] text-white px-6 py-4 flex items-center justify-between rounded-t-xl">
          <div className="flex items-center space-x-2">
            <span className="text-[#FFCC00] text-xl font-black">★</span>
            <div>
              <h3 className="font-bold text-lg leading-tight">Partner Agent SEO Guideline & Manual</h3>
              <p className="text-xs text-blue-100">Uratex Philippines Shopify Metadata Standards</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="text-white/80 hover:text-white p-1 rounded-lg hover:bg-white/10 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Content */}
        <div className="p-6 space-y-6 text-sm text-slate-700">
          {/* Section 1: Core Objectives */}
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h4 className="font-bold text-[#003399] flex items-center gap-2 mb-2">
              <Search className="w-4 h-4 text-[#003399]" />
              SEO Title & Meta Tag Criteria
            </h4>
            <p className="text-slate-600 mb-3 leading-relaxed">
              As a partner agent, your goal is to maximize click-through rate (CTR) and organic Google search rank for Uratex products and collections.
            </p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div className="bg-white p-3 rounded border border-blue-100">
                <div className="font-semibold text-slate-900 text-xs uppercase text-blue-800 mb-1">Page Title Tag</div>
                <div className="text-xs space-y-1 text-slate-600">
                  <div>• <strong>Optimal Length:</strong> 50 to 60 characters</div>
                  <div>• <strong>Format:</strong> [Product Name] - [Benefit/Keyword] | Uratex</div>
                  <div>• <strong>Rule:</strong> Never leave internal testing tags (e.g. [Test 360&5]) in live titles.</div>
                </div>
              </div>
              <div className="bg-white p-3 rounded border border-blue-100">
                <div className="font-semibold text-slate-900 text-xs uppercase text-blue-800 mb-1">Meta Description</div>
                <div className="text-xs space-y-1 text-slate-600">
                  <div>• <strong>Optimal Length:</strong> 135 to 155 characters</div>
                  <div>• <strong>Content:</strong> High intent, dimensions/features, Sanitized® protection, warranty, CTA.</div>
                  <div>• <strong>Rule:</strong> Avoid truncation below 120 or above 160 characters.</div>
                </div>
              </div>
            </div>
          </div>

          {/* Section 2: Step by Step Workflow */}
          <div>
            <h4 className="font-bold text-slate-900 mb-3 flex items-center gap-2">
              <Layers className="w-4 h-4 text-amber-600" />
              Standard Optimization Workflow
            </h4>
            <ol className="space-y-3 pl-4 list-decimal marker:text-[#003399] marker:font-bold">
              <li className="pl-1">
                <strong>Select Active Store:</strong> Switch between <em>Uratex Business (B2B)</em> and <em>Uratex Retail</em> using the top or sidebar dropdown.
              </li>
              <li className="pl-1">
                <strong>Sync Products / Collections:</strong> Click <span className="bg-[#FFCC00] text-slate-900 font-semibold px-2 py-0.5 rounded text-xs">Sync Products</span> to pull live metadata from Shopify API 2025-10.
              </li>
              <li className="pl-1">
                <strong>Edit or AI Optimize:</strong> Make manual edits to the Page Title, Meta Description, and URL Handle, or click <span className="bg-indigo-600 text-white font-semibold px-2 py-0.5 rounded text-xs inline-flex items-center gap-1"><Sparkles className="w-3 h-3" /> AI Optimize</span> to let Gemini generate ranked copy.
              </li>
              <li className="pl-1">
                <strong>Save Draft:</strong> Click <strong>Save Draft</strong> to store your working progress without altering the live customer-facing store.
              </li>
              <li className="pl-1">
                <strong>Bulk Approve & Push:</strong> When ready, click <span className="bg-emerald-600 text-white font-semibold px-2 py-0.5 rounded text-xs">Bulk Approve & Push</span> to deploy all drafted changes to Shopify in one click.
              </li>
            </ol>
          </div>

          {/* Section 3: URL Handles & Redirects */}
          <div className="border-t border-slate-200 pt-4">
            <h4 className="font-bold text-slate-900 mb-2 flex items-center gap-2">
              <AlertTriangle className="w-4 h-4 text-amber-500" />
              Changing URL Handles & 301 Redirects
            </h4>
            <p className="text-xs text-slate-600 leading-relaxed">
              If you edit a product’s URL handle, our portal automatically creates a 301 redirect entry in the <strong>URL Redirects</strong> module to safeguard existing backlinks and Google indexing value.
            </p>
          </div>
        </div>

        {/* Footer */}
        <div className="bg-slate-50 px-6 py-3 border-t border-slate-200 flex justify-between items-center rounded-b-xl">
          <span className="text-xs text-slate-500">Uratex Partner Portal v2.5.0</span>
          <button
            onClick={onClose}
            className="px-4 py-2 bg-[#003399] hover:bg-[#002277] text-white font-semibold rounded-lg text-xs transition shadow"
          >
            Understood & Close
          </button>
        </div>
      </div>
    </div>
  );
};

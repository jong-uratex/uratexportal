import React, { useState } from 'react';
import { Code, Check, Copy, Sparkles, Layers, ShieldCheck } from 'lucide-react';
import { ShopStoreConfig } from '../types';

interface ScriptManagerViewProps {
  storeConfig: ShopStoreConfig;
}

export const ScriptManagerView: React.FC<ScriptManagerViewProps> = ({ storeConfig }) => {
  const [copiedId, setCopiedId] = useState<string | null>(null);

  const productSchema = {
    '@context': 'https://schema.org/',
    '@type': 'Product',
    name: 'Uratex High-Density Institutional Hotel Orthocare Mattress',
    image: ['https://cdn.shopify.com/s/files/1/0569/8486/2816/files/Hotel_Orthocare.jpg'],
    description: 'Engineered for commercial hospitality, resorts, and dormitories with Sanitized® foam protection.',
    brand: {
      '@type': 'Brand',
      name: 'Uratex Philippines',
    },
    offers: {
      '@type': 'Offer',
      url: `https://${storeConfig.domain}/products/uratex-hotel-orthocare-mattress-bulk`,
      priceCurrency: 'PHP',
      price: '12400',
      availability: 'https://schema.org/InStock',
    },
  };

  const organizationSchema = {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    name: 'Uratex Philippines (RGC Group of Companies)',
    url: `https://${storeConfig.domain}`,
    logo: 'https://uratex.com.ph/logo.png',
    contactPoint: {
      '@type': 'ContactPoint',
      telephone: '+63-2-8888-6800',
      contactType: 'Customer Care & B2B Sales',
      areaServed: 'PH',
      availableLanguage: ['English', 'Filipino'],
    },
  };

  const handleCopy = (id: string, text: string) => {
    navigator.clipboard.writeText(text);
    setCopiedId(id);
    setTimeout(() => setCopiedId(null), 2000);
  };

  return (
    <div className="space-y-5 animate-fadeIn">
      <div className="border-b border-slate-200 pb-4">
        <h1 className="text-2xl font-bold text-[#003399] tracking-tight">Structured JSON-LD & Script Manager</h1>
        <p className="text-xs text-slate-500 mt-0.5">
          Generate and verify Google Rich Result schemas (Product Schema, Organization Schema, FAQ Schema) for {storeConfig.name}.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
        {/* Product Schema Card */}
        <div className="bg-white p-5 rounded-xl border border-slate-200 shadow-sm space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="font-bold text-slate-800 text-sm flex items-center gap-2">
              <Code className="w-4 h-4 text-blue-600" />
              Dynamic Product JSON-LD Schema
            </h3>
            <button
              onClick={() => handleCopy('prod', JSON.stringify(productSchema, null, 2))}
              className="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded flex items-center gap-1 transition"
            >
              {copiedId === 'prod' ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
              {copiedId === 'prod' ? 'Copied' : 'Copy JSON'}
            </button>
          </div>
          <p className="text-xs text-slate-500">
            Automatically injected into Shopify <code className="text-blue-700 font-mono">theme.liquid</code> product templates.
          </p>
          <pre className="p-3 bg-slate-900 text-emerald-400 rounded-lg text-xs font-mono overflow-x-auto max-h-64">
            {JSON.stringify(productSchema, null, 2)}
          </pre>
        </div>

        {/* Organization Schema Card */}
        <div className="bg-white p-5 rounded-xl border border-slate-200 shadow-sm space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="font-bold text-slate-800 text-sm flex items-center gap-2">
              <ShieldCheck className="w-4 h-4 text-emerald-600" />
              Brand & Organization Schema
            </h3>
            <button
              onClick={() => handleCopy('org', JSON.stringify(organizationSchema, null, 2))}
              className="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded flex items-center gap-1 transition"
            >
              {copiedId === 'org' ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
              {copiedId === 'org' ? 'Copied' : 'Copy JSON'}
            </button>
          </div>
          <p className="text-xs text-slate-500">
            Establishes Google Knowledge Graph entity trust for Uratex Philippines.
          </p>
          <pre className="p-3 bg-slate-900 text-yellow-300 rounded-lg text-xs font-mono overflow-x-auto max-h-64">
            {JSON.stringify(organizationSchema, null, 2)}
          </pre>
        </div>
      </div>
    </div>
  );
};

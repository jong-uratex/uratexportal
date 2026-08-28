import React from 'react';
import {
  LayoutDashboard,
  Tag,
  Layers,
  FileText,
  Newspaper,
  ArrowRightLeft,
  FolderOpen,
  Code,
  Activity,
  LogOut,
  ChevronRight,
  Sparkles,
  Store,
  SlidersHorizontal,
  Users,
  Shield,
} from 'lucide-react';
import { ShopStoreConfig, UserInfo } from '../types';

interface SidebarProps {
  activeTab: string;
  onSelectTab: (tab: string) => void;
  isOpen: boolean;
  activeStore: 'retail' | 'business';
  storeConfig: ShopStoreConfig;
  user: UserInfo;
  onLogout: () => void;
}

export const Sidebar: React.FC<SidebarProps> = ({
  activeTab,
  onSelectTab,
  isOpen,
  activeStore,
  storeConfig,
  user,
  onLogout,
}) => {
  const managementItems = [
    { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { id: 'pages', label: 'Pages', icon: FileText },
    { id: 'collections', label: 'Collections', icon: Layers },
    { id: 'products', label: 'Products', icon: Tag, badge: 'High Traffic' },
    { id: 'blogs', label: 'Blogs', icon: Newspaper },
    { id: 'redirects', label: 'Redirects (301)', icon: ArrowRightLeft },
  ];

  const systemItems = [
    { id: 'logs', label: 'User Logs', icon: Activity },
    ...(user.role === 'admin'
      ? [{ id: 'user-management', label: 'User Management', icon: Users, badge: 'Admin' }]
      : []),
    { id: 'scripts', label: 'Script & Schema', icon: Code },
    { id: 'php-source', label: 'PHP Package', icon: FolderOpen, highlight: true },
  ];

  return (
    <aside
      className={`fixed top-0 bottom-0 left-0 z-40 w-64 bg-[#003087] text-white flex flex-col transition-transform duration-300 ease-in-out border-r border-[#0044b3] shadow-lg ${
        isOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
      }`}
    >
      {/* Bento Header */}
      <div className="p-4 border-b border-[#0044b3] flex items-center justify-between shrink-0">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-md">
            <span className="text-[#003087] font-black text-lg tracking-tighter">UX</span>
          </div>
          <div className="flex flex-col">
            <div className="flex items-center gap-1.5">
              <span className="font-extrabold text-sm tracking-wide text-white leading-none">SEO MANAGER</span>
            </div>
            <span className="text-[10px] text-white/70 mt-1 uppercase tracking-wider font-semibold">
              Partner Agent Portal
            </span>
          </div>
        </div>
      </div>

      {/* Navigation Groups */}
      <nav className="flex-1 py-4 overflow-y-auto space-y-4 text-sm font-medium">
        <div>
          <div className="px-5 mb-2 text-[10px] font-bold text-white/50 uppercase tracking-wider">
            Management
          </div>
          <div className="space-y-0.5">
            {managementItems.map(item => {
              const Icon = item.icon;
              const isActive = activeTab === item.id;
              return (
                <button
                  key={item.id}
                  onClick={() => onSelectTab(item.id)}
                  className={`w-full flex items-center justify-between px-5 py-2.5 text-left text-sm transition-all cursor-pointer ${
                    isActive
                      ? 'bg-[#C8102E] border-l-4 border-white font-bold text-white shadow-inner'
                      : 'text-white/80 hover:bg-white/10 hover:text-white'
                  }`}
                >
                  <div className="flex items-center gap-3">
                    <Icon className={`w-4 h-4 ${isActive ? 'text-white' : 'opacity-80'}`} />
                    <span>{item.label}</span>
                  </div>
                  {item.badge && (
                    <span className="text-[9px] bg-white/20 text-white font-bold px-1.5 py-0.5 rounded">
                      {item.badge}
                    </span>
                  )}
                </button>
              );
            })}
          </div>
        </div>

        <div>
          <div className="px-5 mb-2 text-[10px] font-bold text-white/50 uppercase tracking-wider">
            System & Infrastructure
          </div>
          <div className="space-y-0.5">
            {systemItems.map(item => {
              const Icon = item.icon;
              const isActive = activeTab === item.id;
              return (
                <button
                  key={item.id}
                  onClick={() => onSelectTab(item.id)}
                  className={`w-full flex items-center justify-between px-5 py-2.5 text-left text-sm transition-all cursor-pointer ${
                    isActive
                      ? 'bg-[#C8102E] border-l-4 border-white font-bold text-white shadow-inner'
                      : 'text-white/80 hover:bg-white/10 hover:text-white'
                  }`}
                >
                  <div className="flex items-center gap-3">
                    <Icon className={`w-4 h-4 ${isActive ? 'text-white' : 'opacity-80'}`} />
                    <span>{item.label}</span>
                  </div>
                  {item.highlight && !isActive && (
                    <span className="text-[9px] bg-[#FFCC00] text-[#003087] font-extrabold px-1.5 py-0.5 rounded">
                      PHP
                    </span>
                  )}
                </button>
              );
            })}
          </div>
        </div>
      </nav>

      {/* Bento Bottom Status Panel */}
      <div className="p-4 bg-[#002a75] text-[11px] border-t border-white/10 shrink-0">
        <div className="flex items-center justify-between mb-1.5">
          <div className="flex items-center gap-2">
            <div className="w-2 h-2 rounded-full bg-green-400 animate-pulse"></div>
            <span className="text-white/90 font-medium">Shopify REST API</span>
          </div>
          <span className="text-[10px] bg-white/10 text-white/80 px-1.5 py-0.5 rounded font-mono">v2025-10</span>
        </div>
        <div className="text-white/60 font-mono text-[10px] truncate flex items-center justify-between">
          <span>db: u390249810_seomini</span>
          <button
            onClick={onLogout}
            className="text-red-300 hover:text-white underline cursor-pointer"
            title="Sign Out"
          >
            Logout
          </button>
        </div>
      </div>
    </aside>
  );
};

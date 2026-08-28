import React, { useState } from 'react';
import {
  Menu,
  RotateCw,
  Store,
  ChevronDown,
  Bell,
  LogOut,
  User,
  ShieldCheck,
  CheckCircle2,
  ExternalLink,
  Code2,
  HelpCircle,
} from 'lucide-react';
import { ShopStoreConfig, UserInfo } from '../types';

interface NavbarProps {
  onToggleSidebar: () => void;
  activeStore: 'retail' | 'business';
  onSwitchStore: (store: 'retail' | 'business') => void;
  storeConfig: ShopStoreConfig;
  user: UserInfo;
  onLogout: () => void;
  onSync: () => void;
  isSyncing: boolean;
  onOpenHowTo: () => void;
}

export const Navbar: React.FC<NavbarProps> = ({
  onToggleSidebar,
  activeStore,
  onSwitchStore,
  storeConfig,
  user,
  onLogout,
  onSync,
  isSyncing,
  onOpenHowTo,
}) => {
  const [showStoreDropdown, setShowStoreDropdown] = useState(false);
  const [showUserDropdown, setShowUserDropdown] = useState(false);

  return (
    <header className="h-16 bg-white border-b border-gray-200 px-4 sm:px-8 flex items-center justify-between sticky top-0 z-30 shadow-xs">
      {/* Left side: Hamburger & Store View Selector */}
      <div className="flex items-center gap-4">
        <button
          onClick={onToggleSidebar}
          className="p-2 text-gray-500 hover:text-gray-900 rounded-lg hover:bg-gray-100 transition lg:hidden"
          title="Toggle Sidebar"
        >
          <Menu className="w-5 h-5" />
        </button>

        {/* Bento Store View */}
        <div className="flex items-center gap-2 sm:gap-3">
          <span className="text-gray-400 text-sm font-medium hidden sm:inline">Store View:</span>
          <div className="relative">
            <button
              onClick={() => setShowStoreDropdown(!showStoreDropdown)}
              className="flex items-center gap-2 bg-[#f8fafc] hover:bg-gray-100 border border-gray-200 rounded-md px-3 py-1.5 text-sm font-semibold text-gray-800 transition cursor-pointer shadow-xs"
            >
              <Store className="w-4 h-4 text-[#003087]" />
              <span>{activeStore === 'retail' ? 'Uratex Retail (Consumer)' : 'Uratex Business (B2B)'}</span>
              <ChevronDown className="w-3.5 h-3.5 text-gray-400 ml-1" />
            </button>

            {showStoreDropdown && (
              <div className="absolute left-0 mt-2 w-72 bg-white rounded-xl shadow-xl border border-gray-100 py-2 z-50 text-xs animate-fadeIn">
                <div className="px-3 py-1 text-[10px] font-bold text-gray-400 uppercase tracking-wider">
                  Select Shopify Store View
                </div>

                <button
                  onClick={() => {
                    onSwitchStore('business');
                    setShowStoreDropdown(false);
                  }}
                  className={`w-full text-left px-3 py-2.5 flex items-start gap-2.5 hover:bg-blue-50/50 transition cursor-pointer ${
                    activeStore === 'business' ? 'bg-blue-50/80 border-l-4 border-[#003087]' : ''
                  }`}
                >
                  <div className="p-1.5 bg-[#003087]/10 rounded-md text-[#003087] mt-0.5">
                    <Store className="w-4 h-4" />
                  </div>
                  <div>
                    <div className="font-bold text-gray-900">Uratex Business (B2B)</div>
                    <div className="text-[11px] text-gray-500 font-mono">uratex-business.myshopify.com</div>
                  </div>
                </button>

                <button
                  onClick={() => {
                    onSwitchStore('retail');
                    setShowStoreDropdown(false);
                  }}
                  className={`w-full text-left px-3 py-2.5 flex items-start gap-2.5 hover:bg-blue-50/50 transition cursor-pointer ${
                    activeStore === 'retail' ? 'bg-blue-50/80 border-l-4 border-[#003087]' : ''
                  }`}
                >
                  <div className="p-1.5 bg-blue-100 rounded-md text-[#003087] mt-0.5">
                    <Store className="w-4 h-4" />
                  </div>
                  <div>
                    <div className="font-bold text-gray-900">Uratex Retail (Consumer)</div>
                    <div className="text-[11px] text-gray-500 font-mono">uratex.com.ph</div>
                  </div>
                </button>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Right side: Sync, Guide, Agent User Profile */}
      <div className="flex items-center gap-3 sm:gap-6">
        <button
          onClick={onOpenHowTo}
          className="hidden sm:flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-gray-600 hover:text-[#003087] hover:bg-gray-100 rounded-md transition"
        >
          <HelpCircle className="w-4 h-4" />
          <span>SEO Playbook</span>
        </button>

        <button
          onClick={onSync}
          disabled={isSyncing}
          className="px-3 py-1.5 bg-[#003087] hover:bg-[#002566] text-white font-bold rounded-md text-xs transition shadow-xs flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
        >
          <RotateCw className={`w-3.5 h-3.5 ${isSyncing ? 'animate-spin' : ''}`} />
          <span className="hidden sm:inline">{isSyncing ? 'Syncing...' : 'Sync Store'}</span>
        </button>

          {/* Bento User Profile Header Area */}
          <div className="relative">
            <button
              onClick={() => setShowUserDropdown(!showUserDropdown)}
              className="flex items-center gap-3 p-1 rounded-lg hover:bg-gray-50 transition cursor-pointer"
            >
              <div className="flex flex-col items-end text-right">
                <span className="text-sm font-bold text-gray-800 leading-tight">{user.name}</span>
                <span className={`text-[11px] font-bold uppercase tracking-wider ${user.role === 'admin' ? 'text-[#C8102E]' : 'text-purple-700'}`}>
                  {user.role === 'admin' ? 'Administrator' : 'SEO Editor'}
                </span>
              </div>
              <div className={`w-10 h-10 rounded-full border-2 overflow-hidden flex items-center justify-center font-extrabold text-sm shadow-inner ${
                user.role === 'admin' ? 'bg-blue-50 border-[#003087] text-[#003087]' : 'bg-purple-50 border-purple-600 text-purple-700'
              }`}>
                {user.name.charAt(0)}
              </div>
            </button>

            {showUserDropdown && (
              <div className="absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-xl border border-gray-100 py-2 z-50 text-xs animate-fadeIn">
                <div className="px-4 py-2.5 border-b border-gray-100">
                  <div className="font-bold text-gray-900">{user.name}</div>
                  <div className="text-gray-500 text-[11px] truncate">{user.email}</div>
                  <div className="flex items-center gap-1.5 mt-1.5">
                    <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full uppercase tracking-wider ${
                      user.role === 'admin'
                        ? 'bg-red-50 text-[#C8102E] border border-red-200'
                        : 'bg-purple-50 text-purple-700 border border-purple-200'
                    }`}>
                      Role: {user.role === 'admin' ? 'Super Admin (Full Access)' : 'SEO Editor (Drafts Only)'}
                    </span>
                  </div>
                </div>

                <div className="px-4 py-2 border-b border-gray-100 text-[11px] text-gray-500">
                  <div className="font-semibold text-gray-700">MySQL Database Auth</div>
                  <div className="font-mono text-gray-500 text-[10px] mt-0.5">u390249810_seomini.users</div>
                  <div className="text-[10px] text-emerald-600 font-semibold mt-0.5">
                    Store Access: {user.storeAccess?.join(', ') || 'business'}
                  </div>
                </div>

                <div className="pt-1">
                  <button
                    onClick={onLogout}
                    className="w-full text-left px-4 py-2 text-rose-600 hover:bg-rose-50 flex items-center gap-2 font-semibold transition cursor-pointer"
                  >
                    <LogOut className="w-4 h-4" />
                    Sign Out (Switch User)
                  </button>
                </div>
              </div>
            )}
          </div>
      </div>
    </header>
  );
};

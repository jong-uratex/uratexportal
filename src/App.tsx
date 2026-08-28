import React, { useState, useEffect } from 'react';
import { Navbar } from './components/Navbar';
import { Sidebar } from './components/Sidebar';
import { DashboardView } from './components/DashboardView';
import { ProductSeoView } from './components/ProductSeoView';
import { CollectionsSeoView } from './components/CollectionsSeoView';
import { PagesSeoView } from './components/PagesSeoView';
import { BlogsSeoView } from './components/BlogsSeoView';
import { UrlRedirectsView } from './components/UrlRedirectsView';
import { ScriptManagerView } from './components/ScriptManagerView';
import { UserLogsView } from './components/UserLogsView';
import { UserManagementView } from './components/UserManagementView';
import { PhpSourceView } from './components/PhpSourceView';
import { LoginView } from './components/LoginView';
import { HowToUseModal } from './components/HowToUseModal';
import { StoreDataResponse, UserInfo, SeoItem, ManagedUser } from './types';
import { CheckCircle2, AlertCircle, Sparkles, UploadCloud } from 'lucide-react';

export function App() {
  const [user, setUser] = useState<UserInfo | null>({
    id: 'usr-1',
    name: 'Jenor Ricafort',
    email: 'jenor.ricafort@uratex.com.ph',
    role: 'admin',
    storeAccess: ['retail', 'business'],
  });

  const [users, setUsers] = useState<ManagedUser[]>([
    {
      id: 'usr-1',
      username: 'admin',
      email: 'jenor.ricafort@uratex.com.ph',
      full_name: 'Jenor Ricafort',
      role: 'admin',
      status: 'active',
      store_access: 'retail,business',
      last_login_at: '2026-08-25 14:30:00',
      created_at: '2026-08-01 09:00:00',
    },
    {
      id: 'usr-2',
      username: 'editor',
      email: 'maria.santos@uratex.com.ph',
      full_name: 'Maria Santos',
      role: 'editor',
      status: 'active',
      store_access: 'business',
      last_login_at: '2026-08-25 11:15:00',
      created_at: '2026-08-10 10:30:00',
    },
    {
      id: 'usr-3',
      username: 'partner.agent',
      email: 'partner.agent@uratex.com.ph',
      full_name: 'Partner SEO Agent',
      role: 'editor',
      status: 'active',
      store_access: 'retail,business',
      last_login_at: '2026-08-25 14:22:04',
      created_at: '2026-08-15 14:00:00',
    },
  ]);

  const [activeStore, setActiveStore] = useState<'retail' | 'business'>('business');
  const [activeTab, setActiveTab] = useState<string>('products');
  const [sidebarOpen, setSidebarOpen] = useState<boolean>(true);
  const [storeData, setStoreData] = useState<StoreDataResponse | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [isSyncing, setIsSyncing] = useState<boolean>(false);
  const [showHowTo, setShowHowTo] = useState<boolean>(false);
  const [toastMessage, setToastMessage] = useState<{ text: string; type: 'success' | 'info' | 'error' } | null>(null);

  const showToast = (text: string, type: 'success' | 'info' | 'error' = 'success') => {
    setToastMessage({ text, type });
    setTimeout(() => {
      setToastMessage(null);
    }, 3500);
  };

  // Fetch store data
  const fetchStoreData = async (storeKey: 'retail' | 'business') => {
    setLoading(true);
    try {
      const res = await fetch(`/api/shopify/data?store=${storeKey}`);
      if (!res.ok) {
        throw new Error(`Server returned status ${res.status}`);
      }
      const contentType = res.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        throw new Error('Response is not valid JSON');
      }
      const data: StoreDataResponse = await res.json();
      setStoreData(data);
    } catch (err) {
      console.error('Failed to load store data:', err);
      showToast('Failed to fetch store data. Check connection.', 'error');
    } finally {
      setLoading(false);
    }
  };

  // Fetch users list
  const fetchUsers = async () => {
    try {
      const res = await fetch('/api/users');
      if (res.ok) {
        const data = await res.json();
        if (data.users && Array.isArray(data.users)) {
          setUsers(data.users);
        }
      }
    } catch (err) {
      console.error('Failed to fetch users list:', err);
    }
  };

  useEffect(() => {
    fetchStoreData(activeStore);
    fetchUsers();
  }, [activeStore]);

  // User CRUD Handlers
  const handleAddUser = async (newUser: Omit<ManagedUser, 'id'> & { password?: string }) => {
    try {
      const res = await fetch('/api/users', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...newUser, author: user?.name || 'Jenor Ricafort' }),
      });
      const data = await res.json();
      if (res.ok && data.success) {
        showToast(data.message || `User ${newUser.full_name} created successfully`, 'success');
        await fetchUsers();
        await fetchStoreData(activeStore);
      } else {
        showToast(data.error || 'Failed to create user account.', 'error');
      }
    } catch (err) {
      showToast('Network error while creating user.', 'error');
    }
  };

  const handleEditUser = async (id: string | number, updates: Partial<ManagedUser> & { password?: string }) => {
    try {
      const res = await fetch(`/api/users/${id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...updates, author: user?.name || 'Jenor Ricafort' }),
      });
      const data = await res.json();
      if (res.ok && data.success) {
        showToast(data.message || `User updated successfully`, 'success');
        await fetchUsers();
        await fetchStoreData(activeStore);
      } else {
        showToast(data.error || 'Failed to update user account.', 'error');
      }
    } catch (err) {
      showToast('Network error while updating user.', 'error');
    }
  };

  const handleDeleteUser = async (id: string | number) => {
    try {
      const res = await fetch(`/api/users/${id}?author=${encodeURIComponent(user?.name || 'Jenor Ricafort')}`, {
        method: 'DELETE',
      });
      const data = await res.json();
      if (res.ok && data.success) {
        showToast(data.message || `User permanently deleted.`, 'success');
        await fetchUsers();
        await fetchStoreData(activeStore);
      } else {
        showToast(data.error || 'Failed to delete user account.', 'error');
      }
    } catch (err) {
      showToast('Network error while deleting user.', 'error');
    }
  };

  // Sync handler
  const handleSync = async () => {
    setIsSyncing(true);
    try {
      const res = await fetch('/api/shopify/sync', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ store: activeStore, storeId: activeStore }),
      });
      if (!res.ok) {
        throw new Error(`Server error ${res.status}`);
      }
      const data = await res.json();
      if (data.success) {
        await fetchStoreData(activeStore);
        showToast(data.message || 'Synced successfully with Shopify API v2025-10!', 'success');
      } else {
        showToast(data.message || 'Sync failed.', 'error');
      }
    } catch (err) {
      showToast('Sync error with Shopify.', 'error');
    } finally {
      setIsSyncing(false);
    }
  };

  // Save draft
  const handleSaveDraft = async (item: SeoItem) => {
    try {
      const res = await fetch('/api/shopify/save-draft', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ store: activeStore, storeId: activeStore, item }),
      });
      if (!res.ok) {
        throw new Error(`Server error ${res.status}`);
      }
      const data = await res.json();
      if (data.success) {
        showToast(`Draft saved for "${item.title.substring(0, 30)}..."`, 'info');
      }
    } catch (err) {
      console.error('Save draft error:', err);
      showToast('Error saving draft.', 'error');
    }
  };

  // Push to Shopify
  const handlePushShopify = async (itemIds: string[]) => {
    try {
      const res = await fetch('/api/shopify/push', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ store: activeStore, storeId: activeStore, itemIds, ids: itemIds }),
      });
      if (!res.ok) {
        throw new Error(`Server error ${res.status}`);
      }
      const data = await res.json();
      if (data.success) {
        await fetchStoreData(activeStore);
        showToast(
          `Successfully deployed ${itemIds.length} item(s) to ${storeData?.config.name || 'Shopify'}!`,
          'success'
        );
      } else {
        showToast(data.message || 'Push to Shopify failed.', 'error');
      }
    } catch (err) {
      showToast('Push to Shopify failed.', 'error');
    }
  };

  if (!user) {
    return <LoginView onLogin={setUser} />;
  }

  return (
    <div className="min-h-screen bg-[#f4f6f9] text-slate-800 flex font-sans antialiased">
      {/* Sidebar */}
      <Sidebar
        activeTab={activeTab}
        onSelectTab={tab => {
          setActiveTab(tab);
          if (window.innerWidth < 1024) {
            setSidebarOpen(false);
          }
        }}
        isOpen={sidebarOpen}
        activeStore={activeStore}
        storeConfig={
          storeData?.config || {
            name: 'Uratex Business (B2B)',
            url: 'https://uratex-business.myshopify.com',
            domain: 'uratex.com.ph',
            version: '2025-10',
          }
        }
        user={user}
        onLogout={() => setUser(null)}
      />

      {/* Main Content Area */}
      <div className={`flex-1 flex flex-col min-w-0 transition-all duration-300 ${sidebarOpen ? 'lg:pl-64' : 'pl-0'}`}>
        {/* Top Navbar */}
        <Navbar
          onToggleSidebar={() => setSidebarOpen(!sidebarOpen)}
          activeStore={activeStore}
          onSwitchStore={store => {
            setActiveStore(store);
            showToast(`Switched active store context to ${store.toUpperCase()}`, 'info');
          }}
          storeConfig={
            storeData?.config || {
              name: 'Uratex Business (B2B)',
              url: 'https://uratex-business.myshopify.com',
              domain: 'uratex.com.ph',
              version: '2025-10',
            }
          }
          user={user}
          onLogout={() => setUser(null)}
          onSync={handleSync}
          isSyncing={isSyncing}
          onOpenHowTo={() => setShowHowTo(true)}
        />

        {/* Page Content Container */}
        <main className="flex-1 p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto">
          {loading && !storeData ? (
            <div className="py-24 flex flex-col items-center justify-center space-y-4">
              <div className="w-10 h-10 border-4 border-blue-200 border-t-[#003399] rounded-full animate-spin"></div>
              <p className="text-xs font-bold text-slate-600 uppercase tracking-wider">
                Connecting to Shopify API 2025-10 & Uratex Database...
              </p>
            </div>
          ) : (
            <>
              {activeTab === 'dashboard' && storeData && (
                <DashboardView
                  data={storeData}
                  onNavigate={setActiveTab}
                  onSync={handleSync}
                  isSyncing={isSyncing}
                  onPushPending={() => {
                    const drafts = storeData.products.filter(p => p.status === 'draft').map(p => p.id);
                    handlePushShopify(drafts);
                  }}
                />
              )}

              {activeTab === 'products' && storeData && (
                <ProductSeoView
                  products={storeData.products}
                  storeConfig={storeData.config}
                  onSaveDraft={handleSaveDraft}
                  onPushShopify={handlePushShopify}
                  onSync={handleSync}
                  isSyncing={isSyncing}
                />
              )}

              {activeTab === 'collections' && storeData && (
                <CollectionsSeoView
                  collections={storeData.collections}
                  storeConfig={storeData.config}
                  onSaveDraft={handleSaveDraft}
                  onPushShopify={handlePushShopify}
                  onSync={handleSync}
                  isSyncing={isSyncing}
                />
              )}

              {activeTab === 'pages' && storeData && (
                <PagesSeoView
                  pages={storeData.pages}
                  storeConfig={storeData.config}
                  onSaveDraft={handleSaveDraft}
                  onPushShopify={handlePushShopify}
                  onSync={handleSync}
                  isSyncing={isSyncing}
                />
              )}

              {activeTab === 'blogs' && storeData && (
                <BlogsSeoView
                  blogs={storeData.blogs}
                  storeConfig={storeData.config}
                  onSaveDraft={handleSaveDraft}
                  onPushShopify={handlePushShopify}
                  onSync={handleSync}
                  isSyncing={isSyncing}
                />
              )}

              {activeTab === 'redirects' && storeData && (
                <UrlRedirectsView redirects={storeData.redirects} storeConfig={storeData.config} />
              )}

              {activeTab === 'scripts' && storeData && (
                <ScriptManagerView storeConfig={storeData.config} />
              )}

              {activeTab === 'logs' && storeData && (
                <UserLogsView logs={storeData.logs} />
              )}

              {activeTab === 'user-management' && user && (
                <UserManagementView
                  currentUser={user}
                  users={users}
                  onAddUser={handleAddUser}
                  onEditUser={handleEditUser}
                  onDeleteUser={handleDeleteUser}
                />
              )}

              {activeTab === 'php-source' && (
                <PhpSourceView />
              )}
            </>
          )}
        </main>

        {/* Global Footer (AdminLTE Style) */}
        <footer className="bg-white border-t border-slate-200 px-6 py-3.5 text-xs text-slate-500 flex flex-col sm:flex-row items-center justify-between gap-2">
          <div>
            <strong>Copyright &copy; 2026{' '}
              <a href="https://uratex.com.ph" target="_blank" rel="noreferrer" className="text-[#003399] font-bold hover:underline">
                Uratex Philippines
              </a>.
            </strong> All rights reserved.
          </div>
          <div className="flex items-center gap-3">
            <span>Shopify REST API 2025-10</span>
            <span>•</span>
            <span>DB: <code className="text-slate-700 font-mono">u390249810_seomini</code></span>
            <span>•</span>
            <button
              onClick={() => setActiveTab('php-source')}
              className="text-[#003399] font-bold hover:underline"
            >
              PHP Architecture
            </button>
          </div>
        </footer>
      </div>

      {/* Global Toast Notification */}
      {toastMessage && (
        <div className="fixed bottom-5 right-5 z-50 animate-fadeIn">
          <div
            className={`px-4 py-3 rounded-xl shadow-xl flex items-center gap-3 text-xs font-semibold border ${
              toastMessage.type === 'success'
                ? 'bg-emerald-600 text-white border-emerald-700'
                : toastMessage.type === 'error'
                ? 'bg-rose-600 text-white border-rose-700'
                : 'bg-slate-900 text-white border-slate-800'
            }`}
          >
            {toastMessage.type === 'success' ? (
              <CheckCircle2 className="w-4 h-4 text-emerald-200" />
            ) : (
              <AlertCircle className="w-4 h-4 text-rose-200" />
            )}
            <span>{toastMessage.text}</span>
          </div>
        </div>
      )}

      {/* How to use modal */}
      <HowToUseModal isOpen={showHowTo} onClose={() => setShowHowTo(false)} />
    </div>
  );
}

export default App;

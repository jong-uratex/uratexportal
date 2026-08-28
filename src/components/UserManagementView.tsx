import React, { useState, useMemo } from 'react';
import {
  Users,
  UserPlus,
  Shield,
  Edit,
  Trash2,
  Search,
  CheckCircle2,
  AlertTriangle,
  Lock,
  Eye,
  EyeOff,
  Store,
  UserCheck,
  UserX,
  Clock,
  Mail,
  ShieldAlert,
} from 'lucide-react';
import { UserInfo, ManagedUser } from '../types';

interface UserManagementViewProps {
  currentUser: UserInfo;
  users: ManagedUser[];
  onAddUser: (user: Omit<ManagedUser, 'id'> & { password?: string }) => void;
  onEditUser: (id: string | number, updates: Partial<ManagedUser> & { password?: string }) => void;
  onDeleteUser: (id: string | number) => void;
}

export const UserManagementView: React.FC<UserManagementViewProps> = ({
  currentUser,
  users,
  onAddUser,
  onEditUser,
  onDeleteUser,
}) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [roleFilter, setRoleFilter] = useState<'all' | 'admin' | 'editor'>('all');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive' | 'suspended'>('all');

  // Modals state
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [editingUser, setEditingUser] = useState<ManagedUser | null>(null);
  const [deletingUser, setDeletingUser] = useState<ManagedUser | null>(null);

  // Add Form State
  const [addForm, setAddForm] = useState({
    username: '',
    email: '',
    full_name: '',
    password: '',
    role: 'editor' as 'admin' | 'editor',
    status: 'active' as 'active' | 'inactive' | 'suspended',
    store_access: ['retail', 'business'] as string[],
  });
  const [showAddPassword, setShowAddPassword] = useState(false);

  // Edit Form State
  const [editForm, setEditForm] = useState({
    email: '',
    full_name: '',
    password: '',
    role: 'editor' as 'admin' | 'editor',
    status: 'active' as 'active' | 'inactive' | 'suspended',
    store_access: ['retail', 'business'] as string[],
  });
  const [showEditPassword, setShowEditPassword] = useState(false);

  // Filtered Users
  const filteredUsers = useMemo(() => {
    return users.filter(u => {
      const matchSearch =
        u.full_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        u.email.toLowerCase().includes(searchTerm.toLowerCase()) ||
        u.username.toLowerCase().includes(searchTerm.toLowerCase());

      const matchRole = roleFilter === 'all' || u.role === roleFilter;
      const matchStatus = statusFilter === 'all' || u.status === statusFilter;

      return matchSearch && matchRole && matchStatus;
    });
  }, [users, searchTerm, roleFilter, statusFilter]);

  // Statistics
  const stats = useMemo(() => {
    return {
      total: users.length,
      admins: users.filter(u => u.role === 'admin').length,
      editors: users.filter(u => u.role === 'editor').length,
      active: users.filter(u => u.status === 'active').length,
    };
  }, [users]);

  // If user is not admin, show access restricted screen
  if (currentUser.role !== 'admin') {
    return (
      <div className="bg-white rounded-2xl border border-slate-200 p-12 text-center shadow-sm max-w-2xl mx-auto my-8">
        <div className="w-16 h-16 bg-red-50 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4 border border-red-100">
          <ShieldAlert className="w-8 h-8" />
        </div>
        <h2 className="text-xl font-bold text-slate-800">Access Restricted</h2>
        <p className="text-sm text-slate-500 mt-2 max-w-md mx-auto leading-relaxed">
          The User Management module is exclusively restricted to users with the <span className="font-bold text-red-600">Admin</span> role. Your current account role is <span className="font-semibold text-slate-700 capitalize">{currentUser.role}</span>.
        </p>
      </div>
    );
  }

  const handleOpenEdit = (u: ManagedUser) => {
    const stores = Array.isArray(u.store_access)
      ? u.store_access
      : (u.store_access || 'business').split(',').map(s => s.trim());

    setEditingUser(u);
    setEditForm({
      email: u.email,
      full_name: u.full_name,
      password: '',
      role: u.role,
      status: u.status,
      store_access: stores,
    });
    setShowEditPassword(false);
  };

  const handleSaveAdd = (e: React.FormEvent) => {
    e.preventDefault();
    if (!addForm.username || !addForm.email || !addForm.full_name || !addForm.password) {
      alert('Please fill in all required fields.');
      return;
    }
    onAddUser({
      username: addForm.username,
      email: addForm.email,
      full_name: addForm.full_name,
      password: addForm.password,
      role: addForm.role,
      status: addForm.status,
      store_access: addForm.store_access.join(','),
    });
    setIsAddModalOpen(false);
    setAddForm({
      username: '',
      email: '',
      full_name: '',
      password: '',
      role: 'editor',
      status: 'active',
      store_access: ['retail', 'business'],
    });
  };

  const handleSaveEdit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingUser) return;
    if (!editForm.email || !editForm.full_name) {
      alert('Email and Full Name are required.');
      return;
    }
    onEditUser(editingUser.id, {
      email: editForm.email,
      full_name: editForm.full_name,
      role: editForm.role,
      status: editForm.status,
      store_access: editForm.store_access.join(','),
      ...(editForm.password ? { password: editForm.password } : {}),
    });
    setEditingUser(null);
  };

  const handleConfirmDelete = () => {
    if (!deletingUser) return;
    onDeleteUser(deletingUser.id);
    setDeletingUser(null);
  };

  return (
    <div className="space-y-6 animate-fadeIn">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-2xl font-bold text-[#003399] tracking-tight">User Management</h1>
            <span className="px-2 py-0.5 bg-red-50 text-[#C8102E] border border-red-200 text-[10px] font-bold rounded uppercase tracking-wider flex items-center gap-1">
              <Shield className="w-3 h-3" /> Admin Console
            </span>
          </div>
          <p className="text-xs text-slate-500 mt-1">
            Manage partner portal user accounts, role-based permissions (Admin &amp; Editor), and Shopify store access privileges.
          </p>
        </div>

        <button
          onClick={() => setIsAddModalOpen(true)}
          className="px-4 py-2 bg-[#003399] hover:bg-[#002266] text-white rounded-lg text-xs font-bold shadow-sm flex items-center gap-2 transition-all cursor-pointer w-fit"
        >
          <UserPlus className="w-4 h-4" />
          Add New User
        </button>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div className="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Total Accounts</div>
            <div className="text-xl font-black text-slate-800 mt-0.5">{stats.total}</div>
          </div>
          <div className="w-9 h-9 rounded-lg bg-blue-50 text-[#003399] flex items-center justify-center">
            <Users className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Administrators</div>
            <div className="text-xl font-black text-[#C8102E] mt-0.5">{stats.admins}</div>
          </div>
          <div className="w-9 h-9 rounded-lg bg-red-50 text-[#C8102E] flex items-center justify-center">
            <Shield className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">SEO Editors</div>
            <div className="text-xl font-black text-sky-700 mt-0.5">{stats.editors}</div>
          </div>
          <div className="w-9 h-9 rounded-lg bg-sky-50 text-sky-700 flex items-center justify-center">
            <Edit className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Active Status</div>
            <div className="text-xl font-black text-emerald-700 mt-0.5">{stats.active}</div>
          </div>
          <div className="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center">
            <UserCheck className="w-5 h-5" />
          </div>
        </div>
      </div>

      {/* Main Table Card */}
      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        {/* Search & Filter Toolbar */}
        <div className="p-3.5 border-b border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-50/50">
          <div className="flex items-center gap-2 w-full sm:w-auto flex-1 max-w-lg">
            <div className="relative flex-1">
              <input
                type="text"
                placeholder="Search by name, email, or username..."
                value={searchTerm}
                onChange={e => setSearchTerm(e.target.value)}
                className="w-full pl-9 pr-4 py-1.5 bg-white border border-slate-300 rounded-lg text-xs outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
              />
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2" />
            </div>

            <select
              value={roleFilter}
              onChange={e => setRoleFilter(e.target.value as any)}
              className="bg-white border border-slate-300 text-slate-700 rounded-lg px-2.5 py-1.5 text-xs outline-none focus:ring-2 focus:ring-blue-500/20"
            >
              <option value="all">All Roles</option>
              <option value="admin">Admin (Full Access)</option>
              <option value="editor">Editor (SEO Only)</option>
            </select>

            <select
              value={statusFilter}
              onChange={e => setStatusFilter(e.target.value as any)}
              className="bg-white border border-slate-300 text-slate-700 rounded-lg px-2.5 py-1.5 text-xs outline-none focus:ring-2 focus:ring-blue-500/20"
            >
              <option value="all">All Statuses</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>

          <div className="text-xs text-slate-500 font-semibold shrink-0">
            {filteredUsers.length} Users Registered
          </div>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 text-slate-700 uppercase font-bold border-b border-slate-200">
              <tr>
                <th className="px-4 py-3.5 w-64">USER DETAILS</th>
                <th className="px-4 py-3.5 w-32">ROLE</th>
                <th className="px-4 py-3.5 w-48">STORE ACCESS</th>
                <th className="px-4 py-3.5 w-28">STATUS</th>
                <th className="px-4 py-3.5 w-44">LAST LOGIN</th>
                <th className="px-4 py-3.5 w-28 text-right">ACTIONS</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200 text-slate-700">
              {filteredUsers.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-400">
                    <Users className="w-8 h-8 mx-auto mb-2 text-slate-300" />
                    <p className="font-semibold">No user accounts found matching your filter criteria.</p>
                  </td>
                </tr>
              ) : (
                filteredUsers.map(u => {
                  const isSelf = u.email === currentUser.email || String(u.id) === String(currentUser.id);
                  const storeList = Array.isArray(u.store_access)
                    ? u.store_access
                    : (u.store_access || 'business').split(',').map(s => s.trim());

                  return (
                    <tr key={u.id} className="hover:bg-slate-50 transition-colors">
                      {/* User Info */}
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-3">
                          <div
                            className={`w-8 h-8 rounded-full flex items-center justify-center font-bold text-white shadow-xs shrink-0 ${
                              u.role === 'admin' ? 'bg-[#C8102E]' : 'bg-[#003399]'
                            }`}
                          >
                            {u.full_name ? u.full_name.charAt(0).toUpperCase() : 'U'}
                          </div>
                          <div>
                            <div className="font-bold text-slate-900 flex items-center gap-1.5">
                              {u.full_name}
                              {isSelf && (
                                <span className="px-1.5 py-0.2 bg-blue-100 text-[#003399] font-bold text-[9px] rounded">
                                  YOU
                                </span>
                              )}
                            </div>
                            <div className="text-slate-500 font-mono text-[11px]">
                              {u.email} <span className="text-slate-400">(@{u.username})</span>
                            </div>
                          </div>
                        </div>
                      </td>

                      {/* Role */}
                      <td className="px-4 py-3 whitespace-nowrap">
                        {u.role === 'admin' ? (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded font-bold text-[10px] bg-red-50 text-[#C8102E] border border-red-200">
                            <Shield className="w-3 h-3" /> Admin
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded font-bold text-[10px] bg-sky-50 text-sky-700 border border-sky-200">
                            <Edit className="w-3 h-3" /> Editor
                          </span>
                        )}
                      </td>

                      {/* Store Access */}
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-1">
                          {storeList.includes('retail') && (
                            <span className="px-1.5 py-0.5 bg-slate-100 text-slate-700 border border-slate-200 rounded text-[10px] font-semibold">
                              Retail
                            </span>
                          )}
                          {storeList.includes('business') && (
                            <span className="px-1.5 py-0.5 bg-blue-50 text-[#003399] border border-blue-200 rounded text-[10px] font-semibold">
                              Business (B2B)
                            </span>
                          )}
                        </div>
                      </td>

                      {/* Status */}
                      <td className="px-4 py-3 whitespace-nowrap">
                        {u.status === 'active' ? (
                          <span className="inline-block px-2 py-0.5 rounded font-bold text-[10px] bg-emerald-50 text-emerald-800 border border-emerald-200">
                            Active
                          </span>
                        ) : u.status === 'inactive' ? (
                          <span className="inline-block px-2 py-0.5 rounded font-bold text-[10px] bg-slate-100 text-slate-600 border border-slate-200">
                            Inactive
                          </span>
                        ) : (
                          <span className="inline-block px-2 py-0.5 rounded font-bold text-[10px] bg-rose-50 text-rose-700 border border-rose-200">
                            Suspended
                          </span>
                        )}
                      </td>

                      {/* Last Login */}
                      <td className="px-4 py-3 text-slate-500 whitespace-nowrap font-mono text-[11px]">
                        {u.last_login_at ? (
                          <div className="flex items-center gap-1">
                            <Clock className="w-3 h-3 text-slate-400" />
                            {u.last_login_at}
                          </div>
                        ) : (
                          <span className="text-slate-400 italic">Never</span>
                        )}
                      </td>

                      {/* Actions */}
                      <td className="px-4 py-3 text-right whitespace-nowrap">
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            onClick={() => handleOpenEdit(u)}
                            className="p-1.5 text-blue-700 hover:bg-blue-50 rounded border border-blue-200 font-semibold transition-colors cursor-pointer"
                            title="Edit User"
                          >
                            <Edit className="w-3.5 h-3.5" />
                          </button>

                          {!isSelf ? (
                            <button
                              onClick={() => setDeletingUser(u)}
                              className="p-1.5 text-red-600 hover:bg-red-50 rounded border border-red-200 font-semibold transition-colors cursor-pointer"
                              title="Delete User"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          ) : (
                            <button
                              disabled
                              className="p-1.5 text-slate-300 rounded border border-slate-200 cursor-not-allowed"
                              title="Cannot delete your active account"
                            >
                              <Lock className="w-3.5 h-3.5" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* ===================================================================== */}
      {/* MODAL: ADD USER */}
      {/* ===================================================================== */}
      {isAddModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-fadeIn">
          <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden border border-slate-200">
            <div className="bg-[#003399] text-white p-4 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <UserPlus className="w-5 h-5" />
                <h3 className="font-bold text-sm">Create New Portal User</h3>
              </div>
              <button
                onClick={() => setIsAddModalOpen(false)}
                className="text-white/80 hover:text-white text-lg font-bold cursor-pointer"
              >
                &times;
              </button>
            </div>

            <form onSubmit={handleSaveAdd} className="p-5 space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1">
                  Full Name <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Juan Dela Cruz"
                  value={addForm.full_name}
                  onChange={e => setAddForm({ ...addForm, full_name: e.target.value })}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500/20"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1">
                    Username <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    placeholder="e.g. jdelacruz"
                    value={addForm.username}
                    onChange={e => setAddForm({ ...addForm, username: e.target.value.toLowerCase() })}
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500/20"
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1">
                    Email <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="email"
                    required
                    placeholder="e.g. juan@uratex.com.ph"
                    value={addForm.email}
                    onChange={e => setAddForm({ ...addForm, email: e.target.value.toLowerCase() })}
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500/20"
                  />
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1">
                  Password <span className="text-red-500">*</span>
                </label>
                <div className="relative">
                  <input
                    type={showAddPassword ? 'text' : 'password'}
                    required
                    placeholder="Min. 6 characters"
                    value={addForm.password}
                    onChange={e => setAddForm({ ...addForm, password: e.target.value })}
                    className="w-full px-3 py-2 pr-9 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500/20"
                  />
                  <button
                    type="button"
                    onClick={() => setShowAddPassword(!showAddPassword)}
                    className="absolute right-2.5 top-2 text-slate-400 hover:text-slate-600 cursor-pointer"
                  >
                    {showAddPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1">
                    Role <span className="text-red-500">*</span>
                  </label>
                  <select
                    value={addForm.role}
                    onChange={e => setAddForm({ ...addForm, role: e.target.value as any })}
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500/20"
                  >
                    <option value="editor">Editor (SEO Only)</option>
                    <option value="admin">Admin (Full Access)</option>
                  </select>
                </div>
                <div>
                  <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1">
                    Status
                  </label>
                  <select
                    value={addForm.status}
                    onChange={e => setAddForm({ ...addForm, status: e.target.value as any })}
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500/20"
                  >
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                  Assigned Store Access
                </label>
                <div className="flex gap-4">
                  <label className="flex items-center gap-1.5 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={addForm.store_access.includes('retail')}
                      onChange={e => {
                        const next = e.target.checked
                          ? [...addForm.store_access, 'retail']
                          : addForm.store_access.filter(s => s !== 'retail');
                        setAddForm({ ...addForm, store_access: next });
                      }}
                      className="rounded text-[#003399]"
                    />
                    <span>Retail Store</span>
                  </label>
                  <label className="flex items-center gap-1.5 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={addForm.store_access.includes('business')}
                      onChange={e => {
                        const next = e.target.checked
                          ? [...addForm.store_access, 'business']
                          : addForm.store_access.filter(s => s !== 'business');
                        setAddForm({ ...addForm, store_access: next });
                      }}
                      className="rounded text-[#003399]"
                    />
                    <span>Business Store (B2B)</span>
                  </label>
                </div>
              </div>

              <div className="pt-3 border-t border-slate-200 flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setIsAddModalOpen(false)}
                  className="px-4 py-2 border border-slate-300 rounded-lg text-slate-700 hover:bg-slate-50 font-semibold cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-[#003399] hover:bg-[#002266] text-white rounded-lg font-bold shadow-sm cursor-pointer"
                >
                  Create User
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ===================================================================== */}
      {/* MODAL: EDIT USER */}
      {/* ===================================================================== */}
      {editingUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-fadeIn">
          <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden border border-slate-200">
            <div className="bg-slate-900 text-white p-4 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Edit className="w-5 h-5 text-amber-400" />
                <h3 className="font-bold text-sm">Edit User: {editingUser.username}</h3>
              </div>
              <button
                onClick={() => setEditingUser(null)}
                className="text-white/80 hover:text-white text-lg font-bold cursor-pointer"
              >
                &times;
              </button>
            </div>

            <form onSubmit={handleSaveEdit} className="p-5 space-y-4 text-xs">
              <div>
                <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1">
                  Full Name <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  required
                  value={editForm.full_name}
                  onChange={e => setEditForm({ ...editForm, full_name: e.target.value })}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500/20"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1">
                  Email <span className="text-red-500">*</span>
                </label>
                <input
                  type="email"
                  required
                  value={editForm.email}
                  onChange={e => setEditForm({ ...editForm, email: e.target.value.toLowerCase() })}
                  className="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500/20"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1">
                  New Password <span className="text-slate-400 font-normal italic">(Leave blank to keep unchanged)</span>
                </label>
                <div className="relative">
                  <input
                    type={showEditPassword ? 'text' : 'password'}
                    placeholder="Enter new password if changing"
                    value={editForm.password}
                    onChange={e => setEditForm({ ...editForm, password: e.target.value })}
                    className="w-full px-3 py-2 pr-9 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500/20"
                  />
                  <button
                    type="button"
                    onClick={() => setShowEditPassword(!showEditPassword)}
                    className="absolute right-2.5 top-2 text-slate-400 hover:text-slate-600 cursor-pointer"
                  >
                    {showEditPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1">
                    Role <span className="text-red-500">*</span>
                  </label>
                  <select
                    value={editForm.role}
                    onChange={e => setEditForm({ ...editForm, role: e.target.value as any })}
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500/20"
                  >
                    <option value="editor">Editor (SEO Only)</option>
                    <option value="admin">Admin (Full Access)</option>
                  </select>
                </div>
                <div>
                  <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1">
                    Status
                  </label>
                  <select
                    value={editForm.status}
                    onChange={e => setEditForm({ ...editForm, status: e.target.value as any })}
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500/20"
                  >
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                  Assigned Store Access
                </label>
                <div className="flex gap-4">
                  <label className="flex items-center gap-1.5 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={editForm.store_access.includes('retail')}
                      onChange={e => {
                        const next = e.target.checked
                          ? [...editForm.store_access, 'retail']
                          : editForm.store_access.filter(s => s !== 'retail');
                        setEditForm({ ...editForm, store_access: next });
                      }}
                      className="rounded text-[#003399]"
                    />
                    <span>Retail Store</span>
                  </label>
                  <label className="flex items-center gap-1.5 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={editForm.store_access.includes('business')}
                      onChange={e => {
                        const next = e.target.checked
                          ? [...editForm.store_access, 'business']
                          : editForm.store_access.filter(s => s !== 'business');
                        setEditForm({ ...editForm, store_access: next });
                      }}
                      className="rounded text-[#003399]"
                    />
                    <span>Business Store (B2B)</span>
                  </label>
                </div>
              </div>

              <div className="pt-3 border-t border-slate-200 flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setEditingUser(null)}
                  className="px-4 py-2 border border-slate-300 rounded-lg text-slate-700 hover:bg-slate-50 font-semibold cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-[#003399] hover:bg-[#002266] text-white rounded-lg font-bold shadow-sm cursor-pointer"
                >
                  Save Changes
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ===================================================================== */}
      {/* MODAL: DELETE CONFIRMATION */}
      {/* ===================================================================== */}
      {deletingUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-fadeIn">
          <div className="bg-white rounded-2xl shadow-2xl max-w-sm w-full overflow-hidden border border-slate-200">
            <div className="bg-rose-600 text-white p-4 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Trash2 className="w-5 h-5" />
                <h3 className="font-bold text-sm">Confirm Delete User</h3>
              </div>
              <button
                onClick={() => setDeletingUser(null)}
                className="text-white/80 hover:text-white text-lg font-bold cursor-pointer"
              >
                &times;
              </button>
            </div>

            <div className="p-5 text-center space-y-3 text-xs">
              <div className="w-12 h-12 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center mx-auto">
                <AlertTriangle className="w-6 h-6" />
              </div>
              <p className="font-bold text-slate-800 text-sm">
                Permanently delete {deletingUser.full_name}?
              </p>
              <p className="text-slate-500 leading-relaxed">
                This will remove the user account <code className="text-slate-700">({deletingUser.email})</code> and revoke all store permissions. This action cannot be undone.
              </p>

              <div className="pt-3 border-t border-slate-200 flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setDeletingUser(null)}
                  className="px-4 py-2 border border-slate-300 rounded-lg text-slate-700 hover:bg-slate-50 font-semibold cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={handleConfirmDelete}
                  className="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-lg font-bold shadow-sm cursor-pointer"
                >
                  Confirm Delete
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

import React, { useState, useMemo } from 'react';
import {
  Activity,
  Search,
  User,
  Clock,
  Download,
  Filter,
  RefreshCw,
  CheckCircle2,
  AlertCircle,
  FileEdit,
  CloudUpload,
  LogIn,
  LogOut,
  Sparkles,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';
import { UserLog } from '../types';

interface UserLogsViewProps {
  logs: UserLog[];
}

export const UserLogsView: React.FC<UserLogsViewProps> = ({ logs }) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedAction, setSelectedAction] = useState<string>('all');
  const [currentPage, setCurrentPage] = useState<number>(1);
  const rowsPerPage = 100; // Strict 100 rows per page

  // Filter logs based on search and action dropdown
  const filtered = useMemo(() => {
    return logs.filter(l => {
      const matchesSearch =
        l.action.toLowerCase().includes(searchTerm.toLowerCase()) ||
        l.item.toLowerCase().includes(searchTerm.toLowerCase()) ||
        l.user.toLowerCase().includes(searchTerm.toLowerCase()) ||
        l.details.toLowerCase().includes(searchTerm.toLowerCase());

      const matchesAction =
        selectedAction === 'all' ||
        l.action.toLowerCase().replace(/\s+/g, '') === selectedAction.toLowerCase().replace(/\s+/g, '');

      return matchesSearch && matchesAction;
    });
  }, [logs, searchTerm, selectedAction]);

  // Pagination calculation
  const totalPages = Math.ceil(filtered.length / rowsPerPage) || 1;
  const paginatedLogs = useMemo(() => {
    const start = (currentPage - 1) * rowsPerPage;
    return filtered.slice(start, start + rowsPerPage);
  }, [filtered, currentPage, rowsPerPage]);

  // Statistics
  const stats = useMemo(() => {
    return {
      total: logs.length,
      logins: logs.filter(l => l.action.toLowerCase().includes('log')).length,
      drafts: logs.filter(l => l.action.toLowerCase().includes('draft')).length,
      pushes: logs.filter(l => l.action.toLowerCase().includes('push') || l.action.toLowerCase().includes('sync')).length,
    };
  }, [logs]);

  // CSV Export handler
  const handleExportCsv = () => {
    const headers = ['Timestamp', 'Partner Agent', 'Action', 'Target Resource', 'Change Details'];
    const rows = filtered.map(l => [
      `"${l.timestamp}"`,
      `"${l.user}"`,
      `"${l.action}"`,
      `"${l.item.replace(/"/g, '""')}"`,
      `"${l.details.replace(/"/g, '""')}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map(e => e.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `uratex_partner_logs_${new Date().toISOString().substring(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  // Badge styling helper
  const getBadgeStyle = (action: string) => {
    const act = action.toLowerCase();
    if (act.includes('push')) {
      return 'bg-emerald-50 text-emerald-800 border-emerald-200';
    }
    if (act.includes('sync')) {
      return 'bg-blue-50 text-blue-800 border-blue-200';
    }
    if (act.includes('draft')) {
      return 'bg-amber-50 text-amber-800 border-amber-200';
    }
    if (act.includes('login')) {
      return 'bg-purple-50 text-purple-800 border-purple-200';
    }
    if (act.includes('logout')) {
      return 'bg-slate-100 text-slate-700 border-slate-300';
    }
    if (act.includes('ai')) {
      return 'bg-teal-50 text-teal-800 border-teal-200';
    }
    return 'bg-slate-100 text-slate-700 border-slate-200';
  };

  return (
    <div className="space-y-5 animate-fadeIn">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-200 pb-4">
        <div>
          <h1 className="text-2xl font-bold text-[#003399] tracking-tight">
            Partner Agent Audit Trail &amp; Activity Logs
          </h1>
          <p className="text-xs text-slate-500 mt-0.5">
            Detailed changelog of all metadata modifications, draft revisions, logins, logouts, and Shopify deployment pushes.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={handleExportCsv}
            className="px-3 py-1.5 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-lg text-xs font-semibold shadow-sm flex items-center gap-1.5 transition-colors cursor-pointer"
          >
            <Download className="w-3.5 h-3.5 text-emerald-600" />
            Export CSV
          </button>
        </div>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div className="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Total Recorded</div>
            <div className="text-xl font-black text-slate-800 mt-0.5">{stats.total}</div>
          </div>
          <div className="w-9 h-9 rounded-lg bg-blue-50 text-[#003399] flex items-center justify-center">
            <Activity className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Auth Events</div>
            <div className="text-xl font-black text-purple-700 mt-0.5">{stats.logins}</div>
          </div>
          <div className="w-9 h-9 rounded-lg bg-purple-50 text-purple-700 flex items-center justify-center">
            <LogIn className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Draft Saves</div>
            <div className="text-xl font-black text-amber-700 mt-0.5">{stats.drafts}</div>
          </div>
          <div className="w-9 h-9 rounded-lg bg-amber-50 text-amber-700 flex items-center justify-center">
            <FileEdit className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Shopify Operations</div>
            <div className="text-xl font-black text-emerald-700 mt-0.5">{stats.pushes}</div>
          </div>
          <div className="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center">
            <CloudUpload className="w-5 h-5" />
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
                placeholder="Search user, action, or item..."
                value={searchTerm}
                onChange={e => {
                  setSearchTerm(e.target.value);
                  setCurrentPage(1);
                }}
                className="w-full pl-9 pr-4 py-1.5 bg-white border border-slate-300 rounded-lg text-xs outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
              />
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2" />
            </div>

            <select
              value={selectedAction}
              onChange={e => {
                setSelectedAction(e.target.value);
                setCurrentPage(1);
              }}
              className="bg-white border border-slate-300 text-slate-700 rounded-lg px-2.5 py-1.5 text-xs outline-none focus:ring-2 focus:ring-blue-500/20"
            >
              <option value="all">All Actions</option>
              <option value="draftsaved">Draft Saved</option>
              <option value="shopifypush">Shopify Push</option>
              <option value="shopifysync">Shopify Sync</option>
              <option value="login">Login</option>
              <option value="logout">Logout</option>
              <option value="ai">AI Optimize</option>
            </select>
          </div>

          <div className="text-xs text-slate-500 font-semibold shrink-0">
            {filtered.length} Recorded Entries (100 rows/page)
          </div>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 text-slate-700 uppercase font-bold border-b border-slate-200">
              <tr>
                <th className="px-4 py-3.5 w-48">TIMESTAMP</th>
                <th className="px-4 py-3.5 w-64">PARTNER AGENT</th>
                <th className="px-4 py-3.5 w-36">ACTION</th>
                <th className="px-4 py-3.5 w-72">TARGET RESOURCE</th>
                <th className="px-4 py-3.5">CHANGE DETAILS</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200 text-slate-700">
              {paginatedLogs.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-12 text-center text-slate-400">
                    <Activity className="w-8 h-8 mx-auto mb-2 text-slate-300 animate-pulse" />
                    <p className="font-semibold">No activity logs match your filter criteria.</p>
                  </td>
                </tr>
              ) : (
                paginatedLogs.map(log => (
                  <tr key={log.id} className="hover:bg-slate-50 transition-colors">
                    <td className="px-4 py-3 text-slate-500 whitespace-nowrap font-mono">
                      <div className="flex items-center gap-1.5">
                        <Clock className="w-3.5 h-3.5 text-slate-400" />
                        {log.timestamp}
                      </div>
                    </td>
                    <td className="px-4 py-3 font-semibold text-slate-900 whitespace-nowrap">
                      <span className="flex items-center gap-1.5">
                        <div className="w-5 h-5 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center">
                          <User className="w-3 h-3" />
                        </div>
                        {log.user}
                      </span>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <span
                        className={`inline-block px-2.5 py-0.5 rounded font-bold text-[10px] border shadow-xs ${getBadgeStyle(
                          log.action
                        )}`}
                      >
                        {log.action}
                      </span>
                    </td>
                    <td className="px-4 py-3 font-medium text-slate-800 max-w-xs truncate" title={log.item}>
                      {log.item}
                    </td>
                    <td className="px-4 py-3 text-slate-600 text-[11px] max-w-md leading-relaxed">
                      {log.details}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* 100 Rows Per Page Pagination Bar */}
        {totalPages > 1 && (
          <div className="px-4 py-3 border-t border-slate-200 bg-white flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
            <div className="text-slate-500 font-medium">
              Showing <span className="font-bold text-slate-800">{(currentPage - 1) * rowsPerPage + 1}</span> to{' '}
              <span className="font-bold text-slate-800">
                {Math.min(currentPage * rowsPerPage, filtered.length)}
              </span>{' '}
              of <span className="font-bold text-slate-800">{filtered.length}</span> entries
            </div>

            <div className="flex items-center gap-1">
              <button
                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                disabled={currentPage === 1}
                className="px-2.5 py-1 border border-slate-300 rounded text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1"
              >
                <ChevronLeft className="w-3.5 h-3.5" />
                Prev
              </button>

              <div className="px-3 py-1 font-bold text-[#003399]">
                Page {currentPage} of {totalPages}
              </div>

              <button
                onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                disabled={currentPage === totalPages}
                className="px-2.5 py-1 border border-slate-300 rounded text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1"
              >
                Next
                <ChevronRight className="w-3.5 h-3.5" />
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

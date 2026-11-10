import React, { useState, useEffect } from 'react';
import { ShieldCheck, FileSpreadsheet, RefreshCw, FileText, ExternalLink } from 'lucide-react';
import api, { API_BASE } from '../services/api';
import type { StagingItem } from '../types';

interface AdminStagingPageProps {
  token: string | null;
}

export const AdminStagingPage: React.FC<AdminStagingPageProps> = ({ token }) => {
  const [stagingList, setStagingList] = useState<StagingItem[]>([]);
  const [stagingFilter, setStagingFilter] = useState<'en_attente' | 'valide' | 'rejete'>('en_attente');
  const [loading, setLoading] = useState(false);
  const [actionMsg, setActionMsg] = useState('');

  const fetchStaging = async () => {
    setLoading(true);
    setActionMsg('');
    try {
      const res = await api.get(`/admin/staging?statut=${stagingFilter}`);
      setStagingList(res.data.data);
    } catch {
      setActionMsg('Erreur lors du chargement des données');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchStaging();
  }, [stagingFilter]);

  const handleValider = async (id: number) => {
    if (!confirm('Confirmer la validation et la mise à jour officielle de cette référence ?')) return;
    try {
      const res = await api.post(`/admin/staging/${id}/valider`);
      setActionMsg(res.data.message);
      fetchStaging();
    } catch (err: any) {
      alert(err.response?.data?.message || 'Erreur lors de la validation');
    }
  };

  const handleRejeter = async (id: number) => {
    if (!confirm('Confirmer le rejet de cette saisie ?')) return;
    try {
      const res = await api.post(`/admin/staging/${id}/rejeter`);
      setActionMsg(res.data.message);
      fetchStaging();
    } catch (err: any) {
      alert(err.response?.data?.message || 'Erreur lors du rejet');
    }
  };

  const handleDownloadExport = () => {
    window.open(`${API_BASE}/admin/export?token=${token}`, '_blank');
  };

  return (
    <div className="w-full space-y-4 sm:space-y-6">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white p-4 sm:p-6 rounded-xl shadow-sm border border-slate-200">
        <div>
          <h2 className="text-lg sm:text-xl font-bold text-[#0b2b48] flex items-center space-x-2">
            <ShieldCheck className="w-5 h-5 sm:w-6 sm:h-6 text-[#005ea2] flex-shrink-0" />
            <span>Système de Staging & Validation </span>
          </h2>
          <p className="text-xs text-slate-500 mt-1">
            Les fiches validées sont automatiquement intégrées dans la table officielle des abonnés.
          </p>
        </div>

        <div className="w-full sm:w-auto flex items-center justify-between sm:justify-end gap-2 sm:gap-3">
          <button
            onClick={handleDownloadExport}
            className="flex-1 sm:flex-none justify-center bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 sm:px-4 py-2 sm:py-2.5 rounded-lg text-xs font-semibold flex items-center space-x-2 shadow transition"
          >
            <FileSpreadsheet className="w-4 h-4" />
            <span>Exporter CSV</span>
          </button>
          <button
            onClick={fetchStaging}
            className="p-2 sm:p-2.5 border border-slate-300 rounded-lg text-slate-600 hover:bg-slate-50"
            title="Rafraîchir"
          >
            <RefreshCw className="w-4 h-4" />
          </button>
        </div>
      </div>

      {actionMsg && (
        <div className="p-3 bg-blue-50 text-blue-700 border border-blue-200 rounded-lg text-xs sm:text-sm font-medium">
          {actionMsg}
        </div>
      )}

      {/* Filtres Staging */}
      <div className="flex flex-wrap gap-2">
        {(['en_attente', 'valide', 'rejete'] as const).map((st) => (
          <button
            key={st}
            onClick={() => setStagingFilter(st)}
            className={`flex-1 sm:flex-none text-center px-3 sm:px-4 py-2 rounded-lg text-[11px] sm:text-xs font-bold uppercase tracking-wider transition ${
              stagingFilter === st
                ? 'bg-[#0b2b48] text-white shadow'
                : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'
            }`}
          >
            {st === 'en_attente' ? 'En attente' : st === 'valide' ? 'Validées' : 'Rejetées'}
          </button>
        ))}
      </div>

      {/* Contenu : Cartes sur mobile, Tableau sur Desktop */}
      {loading ? (
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center text-slate-400 text-sm">
          Chargement...
        </div>
      ) : stagingList.length === 0 ? (
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center text-slate-400 text-sm">
          Aucune saisie dans cette catégorie.
        </div>
      ) : (
        <>
          {/* Vue Mobile (< 768px) : Cartes tactiles responsives */}
          <div className="block md:hidden space-y-3">
            {stagingList.map((item) => (
              <div key={item.id} className="bg-white border border-slate-200 rounded-xl p-4 shadow-sm space-y-3">
                <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                  <span className="font-mono font-bold text-[#0b2b48] text-sm">{item.reference}</span>
                  <span className="text-[11px] text-slate-400">{item.datesais}</span>
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs">
                  <div>
                    <span className="text-slate-400 block text-[10px] uppercase">CIP</span>
                    <span className="font-mono font-semibold text-slate-800">{item.cip}</span>
                  </div>
                  <div>
                    <span className="text-slate-400 block text-[10px] uppercase">Téléphone</span>
                    <span className="font-mono font-semibold text-slate-800">{item.telephone}</span>
                  </div>
                  {item.cip_document_path && (
                    <div className="col-span-2">
                      <span className="text-slate-400 block text-[10px] uppercase">Pièce justificative CIP</span>
                      <a
                        href={`${API_BASE}/admin/staging/${item.id}/document?token=${token}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1.5 text-xs text-[#005ea2] hover:text-[#0b2b48] font-semibold bg-blue-50 hover:bg-blue-100 border border-blue-200 px-2.5 py-1 rounded-md mt-0.5 transition"
                      >
                        <FileText className="w-3.5 h-3.5" />
                        <span>Voir la pièce scannée</span>
                        <ExternalLink className="w-3 h-3" />
                      </a>
                    </div>
                  )}
                  <div className="col-span-2">
                    <span className="text-slate-400 block text-[10px] uppercase">Email de contact saisi</span>
                    <span className="text-slate-800 break-all">{item.email || '—'}</span>
                  </div>
                  <div className="col-span-2">
                    <span className="text-slate-400 block text-[10px] uppercase">Compte ayant effectué la saisie</span>
                    <span className="font-semibold text-[#0b2b48] text-xs block">{item.user?.email || item.nomop}</span>
                  </div>
                </div>

                {item.statut === 'en_attente' ? (
                  <div className="flex items-center gap-2 pt-2 border-t border-slate-100">
                    <button
                      onClick={() => handleValider(item.id)}
                      className="flex-1 bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg text-xs font-semibold transition text-center shadow-sm"
                    >
                      Valider
                    </button>
                    <button
                      onClick={() => handleRejeter(item.id)}
                      className="flex-1 bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 py-2 rounded-lg text-xs font-semibold transition text-center"
                    >
                      Rejeter
                    </button>
                  </div>
                ) : (
                  <div className="pt-2 border-t border-slate-100 text-center">
                    <span
                      className={`inline-block w-full py-1.5 rounded-lg text-xs font-bold ${
                        item.statut === 'valide'
                          ? 'bg-green-100 text-green-700'
                          : 'bg-red-100 text-red-700'
                      }`}
                    >
                      {item.statut === 'valide' ? 'Validé' : 'Rejeté'}
                    </span>
                  </div>
                )}
              </div>
            ))}
          </div>

          {/* Vue Desktop / Tablette (>= 768px) : Tableau classique */}
          <div className="hidden md:block bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase text-slate-500">
                <tr>
                  <th className="p-3.5">Référence</th>
                  <th className="p-3.5">CIP</th>
                  <th className="p-3.5">Téléphone</th>
                  <th className="p-3.5">Pièce CIP</th>
                  <th className="p-3.5">Email Contact</th>
                  <th className="p-3.5">Compte auteur (Email)</th>
                  <th className="p-3.5">Date</th>
                  <th className="p-3.5 text-center">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {stagingList.map((item) => (
                  <tr key={item.id} className="hover:bg-slate-50/60">
                    <td className="p-3.5 font-mono font-bold text-[#0b2b48]">{item.reference}</td>
                    <td className="p-3.5 font-mono">{item.cip}</td>
                    <td className="p-3.5 font-mono">{item.telephone}</td>
                    <td className="p-3.5">
                      {item.cip_document_path ? (
                        <a
                          href={`${API_BASE}/admin/staging/${item.id}/document?token=${token}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center gap-1 text-xs text-[#005ea2] hover:underline font-semibold bg-blue-50 border border-blue-200 px-2 py-0.5 rounded"
                          title="Visualiser le scan"
                        >
                          <FileText className="w-3.5 h-3.5" />
                          <span>Voir scan</span>
                          <ExternalLink className="w-2.5 h-2.5" />
                        </a>
                      ) : (
                        <span className="text-xs text-slate-400 italic">Non fourni</span>
                      )}
                    </td>
                    <td className="p-3.5">{item.email || '—'}</td>
                    <td className="p-3.5">
                      <span className="font-semibold block text-xs text-[#0b2b48]">{item.user?.email || item.nomop}</span>
                      {item.user?.email && item.nomop !== item.user?.email 
                      }
                    </td>
                    <td className="p-3.5 text-xs text-slate-500">{item.datesais}</td>
                    <td className="p-3.5 text-center">
                      {item.statut === 'en_attente' ? (
                        <div className="flex justify-center space-x-2">
                          <button
                            onClick={() => handleValider(item.id)}
                            className="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-xs font-semibold transition"
                          >
                            Valider
                          </button>
                          <button
                            onClick={() => handleRejeter(item.id)}
                            className="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 px-3 py-1 rounded text-xs font-semibold transition"
                          >
                            Rejeter
                          </button>
                        </div>
                      ) : (
                        <span
                          className={`inline-block px-2.5 py-1 rounded-full text-xs font-bold ${
                            item.statut === 'valide'
                              ? 'bg-green-100 text-green-700'
                              : 'bg-red-100 text-red-700'
                          }`}
                        >
                          {item.statut === 'valide' ? 'Validé' : 'Rejeté'}
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
};

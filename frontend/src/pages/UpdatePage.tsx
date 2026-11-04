import React, { useState } from 'react';
import { 
  CheckCircle2, 
  AlertTriangle, 
  RefreshCw, 
  ChevronRight, 
  Send 
} from 'lucide-react';
import api from '../services/api';
import type { AbonneInfo, CurrentUser } from '../types';

interface UpdatePageProps {
  currentUser?: CurrentUser | null;
}

export const UpdatePage: React.FC<UpdatePageProps> = ({ currentUser }) => {
  // Stepper : 1 (Information) | 2 (Fiche & Coordonnées)
  const [currentStep, setCurrentStep] = useState<1 | 2>(1);

  // Étape 1 : Recherche
  const [searchRef, setSearchRef] = useState('');
  const [searchLoading, setSearchLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');
  const [abonne, setAbonne] = useState<AbonneInfo | null>(null);

  // Étape 2 : Coordonnées (non préremplies)
  const [inputCip, setInputCip] = useState('');
  const [inputTel, setInputTel] = useState('');
  const [inputEmail, setInputEmail] = useState('');
  const [saisieLoading, setSaisieLoading] = useState(false);
  const [saisieError, setSaisieError] = useState('');
  const [saisieSuccess, setSaisieSuccess] = useState(false);
  const [notifiedEmail, setNotifiedEmail] = useState<string | null>(null);

  const handleSearch = async (e: React.FormEvent) => {
    e.preventDefault();
    const cleanRef = searchRef.trim().toUpperCase();

    if (cleanRef.length !== 14) {
      setErrorMessage('La référence doit comporter exactement 14 caractères (ex : 14000010060BFA).');
      return;
    }

    setSearchLoading(true);
    setErrorMessage('');
    setAbonne(null);
    setSaisieSuccess(false);

    try {
      const res = await api.get(`/abonnes/${cleanRef}`);
      setAbonne(res.data);
      // Champs coordonnées : CIP et Tel vierges, Email pré-renseigné avec l'email vérifié mais modifiable
      setInputCip('');
      setInputTel('');
      setInputEmail(currentUser?.email || '');
      setCurrentStep(2);
    } catch (err: any) {
      if (err.response?.status === 404) {
        setErrorMessage(err.response.data?.message || 'Abonné inexistant. Vérifiez votre référence.');
      } else {
        setErrorMessage(err.response?.data?.message || 'Une erreur est survenue lors de la communication avec le serveur.');
      }
    } finally {
      setSearchLoading(false);
    }
  };

  const handleStoreSaisie = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!abonne) return;

    setSaisieLoading(true);
    setSaisieError('');
    setSaisieSuccess(false);

    try {
      const res = await api.post('/saisies', {
        reference: abonne.reference,
        cip: inputCip,
        telephone: inputTel,
        email: inputEmail || null,
      });

      setSaisieSuccess(true);
      setNotifiedEmail(res.data.email_notified ? res.data.notified_email : null);
      setInputCip('');
      setInputTel('');
      setInputEmail('');
    } catch (err: any) {
      setSaisieError(err.response?.data?.message || 'Erreur lors de l\'enregistrement des coordonnées.');
    } finally {
      setSaisieLoading(false);
    }
  };

  return (
    <div className="max-w-2xl w-full mx-auto bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8 space-y-6">
      {/* Titre centré */}
      <div className="text-center space-y-1">
        <h1 className="text-xl sm:text-2xl font-bold text-[#0b2b48]">
          Formulaire de mise à jour
        </h1>
        <p className="text-xs text-slate-500">
          Mise à jour sécurisée des coordonnées d'abonné SONEB
        </p>
      </div>

      {/* Stepper centré : 2 étapes */}
      <div className="flex items-center justify-center space-x-6 sm:space-x-10 text-xs sm:text-sm font-semibold border-b border-slate-200 pb-3">
        <button
          onClick={() => setCurrentStep(1)}
          className={`flex items-center space-x-1.5 transition cursor-pointer ${
            currentStep === 1
              ? 'text-[#005ea2] font-bold border-b-2 border-[#005ea2] pb-2 -mb-3'
              : 'text-slate-400 hover:text-slate-600'
          }`}
        >
          <span>1 - Recherche</span>
        </button>

        <div
          className={`flex items-center space-x-1.5 transition ${
            currentStep === 2
              ? 'text-[#005ea2] font-bold border-b-2 border-[#005ea2] pb-2 -mb-3'
              : 'text-slate-400'
          }`}
        >
          <span>2 - Abonné & Coordonnées</span>
        </div>
      </div>

      {/* ÉTAPE 1 : Saisie de la référence centrée */}
      {currentStep === 1 && (
        <div className="max-w-md w-full mx-auto space-y-5 pt-2">
          <div className="text-center">
            <h2 className="text-sm sm:text-base font-semibold text-slate-800">
              Veuillez saisir votre numéro de référence abonné
            </h2>
            <p className="text-xs text-slate-500 mt-1">
              Référence à 14 caractères figurant sur vos quittances SONEB
            </p>
          </div>

          <form onSubmit={handleSearch} className="space-y-4">
            <div>
              <input
                type="text"
                maxLength={14}
                value={searchRef}
                onChange={(e) => setSearchRef(e.target.value.toUpperCase())}
                placeholder=""
                className="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl text-center text-base font-mono tracking-widest text-[#0b2b48] placeholder:text-slate-400 placeholder:tracking-normal focus:ring-2 focus:ring-[#005ea2] focus:border-[#005ea2] focus:outline-none transition shadow-sm"
              />
            </div>

            {errorMessage && (
              <div className="p-3 bg-red-50 text-red-700 text-xs sm:text-sm rounded-xl border border-red-200 flex items-center justify-center space-x-2 text-center">
                <AlertTriangle className="w-4 h-4 flex-shrink-0" />
                <span>{errorMessage}</span>
              </div>
            )}

            <button
              type="submit"
              disabled={searchLoading || searchRef.trim().length !== 14}
              className="w-full bg-[#005ea2] hover:bg-[#0b2b48] text-white font-semibold py-3 px-6 rounded-xl text-sm shadow transition disabled:opacity-50 flex items-center justify-center space-x-2 cursor-pointer"
            >
              {searchLoading && <RefreshCw className="w-4 h-4 animate-spin" />}
              <span>Continuer</span>
            </button>
          </form>
        </div>
      )}

      {/* ÉTAPE 2 : Fiche & Coordonnées */}
      {currentStep === 2 && abonne && (
        <div className="space-y-6 pt-2">
          {/* Notification de succès */}
          {saisieSuccess && (
            <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex flex-col sm:flex-row items-center justify-between gap-3 text-emerald-800 text-center sm:text-left">
              <div className="flex items-center space-x-3">
                <CheckCircle2 className="w-5 h-5 text-emerald-600 flex-shrink-0" />
                <div>
                  <p className="font-bold text-sm">Vos coordonnées ont été enregistrées avec succès.</p>
                  
                  {notifiedEmail && (
                    <p className="text-xs text-emerald-900 font-semibold mt-1.5 flex items-center gap-1.5 justify-center sm:justify-start bg-emerald-100/80 px-2.5 py-1 rounded-md">
                      <span>📧</span>
                      <span>Un e-mail de confirmation a été envoyé à <u>{notifiedEmail}</u>.</span>
                    </p>
                  )}
                </div>
              </div>
              <button
                onClick={() => {
                  setSaisieSuccess(false);
                  setNotifiedEmail(null);
                  setCurrentStep(1);
                  setSearchRef('');
                  setAbonne(null);
                }}
                className="w-full sm:w-auto bg-emerald-700 hover:bg-emerald-800 text-white text-xs px-4 py-2 rounded-lg font-medium transition cursor-pointer"
              >
                Autre recherche
              </button>
            </div>
          )}

          {/* Fiche d'information abonné */}
          <div className="bg-slate-50 border border-slate-200 rounded-xl p-4 sm:p-5 space-y-3">
            <div className="border-b border-slate-200 pb-2 text-center sm:text-left">
              <span className="text-xs text-slate-500 font-medium">Référence abonné :</span>
              <div className="font-mono font-bold text-[#005ea2] text-lg">{abonne.reference}</div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs sm:text-sm">
              <div>
                <span className="text-slate-500 block">Titulaire :</span>
                <span className="font-bold text-slate-900">{abonne.nom_abonne || 'Client SONEB'}</span>
              </div>
              <div>
                <span className="text-slate-500 block">Ville :</span>
                <span className="font-semibold text-slate-800">{abonne.ville || '—'}</span>
              </div>
              <div>
                <span className="text-slate-500 block">Département :</span>
                <span className="font-semibold text-slate-800">{abonne.departement}</span>
              </div>
              <div>
                <span className="text-slate-500 block">Agence :</span>
                <span className="font-semibold text-slate-800">{abonne.agence}</span>
              </div>
              <div>
                <span className="text-slate-500 block">Police :</span>
                <span className="font-mono font-bold text-slate-800">{abonne.police || '—'}</span>
              </div>
              <div>
                <span className="text-slate-500 block">État abonnement :</span>
                <span className="font-semibold text-slate-800">{abonne.etat_abo || '—'}</span>
              </div>
            </div>
          </div>

          {/* Formulaire de saisie coordonnées */}
          <form onSubmit={handleStoreSaisie} className="space-y-4">
            <div className="border-b border-slate-200 pb-2 flex items-center justify-center sm:justify-start space-x-2">
              <Send className="w-4 h-4 text-[#005ea2]" />
              <h3 className="text-sm sm:text-base font-bold text-[#0b2b48]">
                Mise à jour de vos coordonnées
              </h3>
            </div>

            {saisieError && (
              <div className="p-3 bg-red-50 text-red-700 text-xs sm:text-sm rounded-lg border border-red-200 flex items-center justify-center space-x-2">
                <AlertTriangle className="w-4 h-4 flex-shrink-0" />
                <span>{saisieError}</span>
              </div>
            )}

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Numéro CIP (15 chiffres max) *
                </label>
                <input
                  type="text"
                  required
                  maxLength={15}
                  value={inputCip}
                  onChange={(e) => setInputCip(e.target.value.replace(/\D/g, ''))}
                  placeholder="Ex : 123456789012345"
                  className="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-[#005ea2] focus:outline-none"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Numéro de Téléphone *
                </label>
                <input
                  type="tel"
                  required
                  maxLength={15}
                  value={inputTel}
                  onChange={(e) => setInputTel(e.target.value.replace(/\D/g, ''))}
                  placeholder="Ex : 0197000000"
                  className="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-[#005ea2] focus:outline-none"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Adresse E-mail
              </label>
              <input
                type="email"
                maxLength={100}
                value={inputEmail}
                onChange={(e) => setInputEmail(e.target.value)}
                placeholder="Ex : abonne@gmail.com"
                className="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-[#005ea2] focus:outline-none"
              />
            </div>

            <div className="flex flex-col-reverse sm:flex-row items-center justify-between gap-3 pt-3">
              <button
                type="button"
                onClick={() => {
                  setCurrentStep(1);
                  setSaisieSuccess(false);
                }}
                className="text-xs text-slate-500 hover:text-slate-800 font-semibold py-2 cursor-pointer"
              >
                ← Retour à la recherche
              </button>

              <button
                type="submit"
                disabled={saisieLoading}
                className="w-full sm:w-auto bg-[#005ea2] hover:bg-[#0b2b48] text-white font-semibold px-8 py-3 rounded-xl text-sm shadow transition disabled:opacity-50 flex items-center justify-center space-x-2 cursor-pointer"
              >
                {saisieLoading ? (
                  <span>Enregistrement...</span>
                ) : (
                  <>
                    <span>Enregistrer les coordonnées</span>
                    <ChevronRight className="w-4 h-4" />
                  </>
                )}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
};

import React, { useState } from 'react';
import { 
  CheckCircle2, 
  AlertTriangle, 
  RefreshCw, 
  ChevronRight, 
  Send,
  UploadCloud,
  FileText,
  Image as ImageIcon,
  X,
  Lock,
  Info
} from 'lucide-react';
import api from '../services/api';
import type { AbonneInfo, CurrentUser } from '../types';
import { COUNTRY_DIAL_CODES } from '../constants/countryCodes';

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

  // Étape 2 : Coordonnées
  const [inputCip, setInputCip] = useState('');
  const [countryCode, setCountryCode] = useState('+229');
  const [inputTel, setInputTel] = useState('');
  const [inputEmail, setInputEmail] = useState('');
  const [cipFile, setCipFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState('');

  const [saisieLoading, setSaisieLoading] = useState(false);
  const [saisieError, setSaisieError] = useState('');
  const [saisieSuccess, setSaisieSuccess] = useState(false);
  const [notifiedEmail, setNotifiedEmail] = useState<string | null>(null);

  // Filtre strict : Bloque catégoriquement les espaces et les caractères spéciaux à la frappe
  const handleDigitsKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    const allowedControlKeys = [
      'Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 
      'ArrowLeft', 'ArrowRight', 'Home', 'End'
    ];
    if (allowedControlKeys.includes(e.key) || e.ctrlKey || e.metaKey) {
      return;
    }
    // Si la touche n'est pas un chiffre de 0 à 9 (incluant l'espace ' '), bloquer l'événement
    if (!/^[0-9]$/.test(e.key)) {
      e.preventDefault();
    }
  };

  // Filtre au copier-coller : élimine immédiatement espaces, tirets et caractères non numériques
  const handlePasteDigitsOnly = (setter: React.Dispatch<React.SetStateAction<string>>, maxLen: number) => 
    (e: React.ClipboardEvent<HTMLInputElement>) => {
      e.preventDefault();
      const text = e.clipboardData.getData('text');
      const clean = text.replace(/\D/g, '').slice(0, maxLen);
      setter(clean);
    };

  // Gestion de l'import de fichier scan CIP (PDF ou Image, max 10 Mo)
  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFileError('');
    const file = e.target.files?.[0];
    if (!file) return;

    const validTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    if (!validTypes.includes(file.type)) {
      setFileError('Format non supporté. Veuillez choisir un document PDF ou une image (JPG, PNG).');
      return;
    }

    if (file.size > 10 * 1024 * 1024) {
      setFileError('Le fichier sélectionné dépasse la taille maximale autorisée (10 Mo).');
      return;
    }

    setCipFile(file);
  };

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
      setInputCip('');
      setInputTel('');
      setInputEmail(currentUser?.email || '');
      setCipFile(null);
      setFileError('');
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

    // Nettoyage strict supplémentaire de sécurité
    const cleanTel = inputTel.replace(/\D/g, '');
    const cleanCip = inputCip.replace(/\D/g, '');

    if (!cleanCip || cleanCip.length !== 15) {
      setSaisieError('Le numéro CIP doit comporter exactement 15 chiffres (ex : 123456789012345).');
      return;
    }

    if (countryCode === '+229') {
      if (!cleanTel || cleanTel.length !== 10) {
        setSaisieError('Pour le Bénin (+229), le numéro de téléphone doit comporter exactement 10 chiffres (ex : 0197000000).');
        return;
      }
      if (!cleanTel.startsWith('01')) {
        setSaisieError('Pour le Bénin (+229), les deux premiers chiffres après l\'indicatif doivent obligatoirement être 01 (ex : 0197000000).');
        return;
      }
    } else {
      if (!cleanTel || cleanTel.length < 6 || cleanTel.length > 15) {
        setSaisieError('Veuillez saisir un numéro de téléphone valide pour l\'indicatif sélectionné.');
        return;
      }
    }

    const fullTel = `${countryCode}${cleanTel}`;
    const formattedTelDisplay = `${countryCode} ${cleanTel}`;

    const emailTrimmed = inputEmail.trim();
    if (emailTrimmed) {
      const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
      if (!emailRegex.test(emailTrimmed)) {
        setSaisieError('Veuillez saisir une adresse e-mail valide avec un domaine (ex : exemple@domaine.com, .fr, .bj, etc.).');
        return;
      }
    }

    setSaisieLoading(true);
    setSaisieError('');
    setSaisieSuccess(false);

    try {
      const formData = new FormData();
      formData.append('reference', abonne.reference);
      formData.append('cip', cleanCip);
      formData.append('telephone', fullTel);
      formData.append('indicatif', countryCode);
      formData.append('telephone_local', cleanTel);
      if (inputEmail?.trim()) {
        formData.append('email', inputEmail.trim());
      }
      if (cipFile) {
        formData.append('cip_document', cipFile);
      }

      const res = await api.post('/saisies', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      });

      setSaisieSuccess(true);
      setNotifiedEmail(res.data.email_notified ? res.data.notified_email : null);
      setAbonne(prev => prev ? {
        ...prev,
        already_submitted: true,
        cip: cleanCip,
        telephone: formattedTelDisplay,
        email: emailTrimmed || null,
        datesais: new Date().toLocaleDateString('fr-FR'),
      } : null);
      setInputCip('');
      setInputTel('');
      setInputEmail('');
      setCountryCode('+229');
      setCipFile(null);
      setFileError('');
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
            <h2 className="text-sm sm:base font-semibold text-slate-800">
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
                <div>
                  <p className="font-bold text-sm">Vos coordonnées ont été enregistrées avec succès.</p>
                  
                  {notifiedEmail && (
                    <p className="text-xs text-emerald-900 font-semibold mt-1.5 flex items-center gap-1.5 justify-center sm:justify-start bg-emerald-100/80 px-2.5 py-1 rounded-md">
                    
                      <span>Un e-mail a été envoyé à <u>{notifiedEmail}</u>.</span>
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
                  setCipFile(null);
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

          {/* Masquer complètement le formulaire si déjà enregistré OU si la saisie vient de réussir */}
          {abonne.already_submitted || saisieSuccess ? (
            <div className="space-y-4 pt-1">
              {/* Message d'information doublon UNIQUEMENT si ce n'est pas la saisie qui vient de réussir */}
              {!saisieSuccess && (
                <div className="p-4 rounded-xl border border-blue-200 bg-blue-50/80 text-[#0b2b48] flex items-start space-x-3.5 shadow-xs">
                  <CheckCircle2 className="w-5 h-5 text-[#005ea2] flex-shrink-0 mt-0.5" />
                  <div className="space-y-1">
                    <h4 className="font-bold text-sm sm:text-base text-[#0b2b48]">
                      Coordonnées déjà enregistrées
                    </h4>
                    <p className="text-xs sm:text-sm leading-relaxed text-slate-700">
                      Les coordonnées de cet abonné sont déjà enregistrées pour cette référence. Une nouvelle saisie n'est pas autorisée.
                    </p>
                  </div>
                </div>
              )}

              {/* Récapitulatif des coordonnées enregistrées (lecture seule) */}
              <div className="bg-white border border-slate-200 rounded-xl p-4 sm:p-5 shadow-xs space-y-3">
                <div className="border-b border-slate-100 pb-2.5">
                  <span className="text-xs font-bold text-slate-700 uppercase tracking-wide flex items-center gap-1.5">
                    <Lock className="w-3.5 h-3.5 text-slate-400" />
                    <span>Coordonnées enregistrées</span>
                  </span>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5 text-xs sm:text-sm">
                  <div>
                    <span className="text-slate-400 block text-[11px] mb-0.5">Numéro CIP</span>
                    <span className="font-mono font-bold text-slate-800">{abonne.cip || '—'}</span>
                  </div>
                  <div>
                    <span className="text-slate-400 block text-[11px] mb-0.5">Téléphone</span>
                    <span className="font-mono font-bold text-slate-800">{abonne.telephone || '—'}</span>
                  </div>
                  <div className="sm:col-span-2">
                    <span className="text-slate-400 block text-[11px] mb-0.5">Adresse E-mail</span>
                    <span className="font-medium text-slate-800">{abonne.email || 'Non renseignée'}</span>
                  </div>
                  {abonne.datesais && (
                    <div>
                      <span className="text-slate-400 block text-[11px] mb-0.5">Date d'enregistrement</span>
                      <span className="font-medium text-slate-600">{abonne.datesais}</span>
                    </div>
                  )}
                </div>
              </div>

              <div className="pt-2 flex justify-center">
                <button
                  type="button"
                  onClick={() => {
                    setCurrentStep(1);
                    setSearchRef('');
                    setAbonne(null);
                    setSaisieSuccess(false);
                  }}
                  className="w-full sm:w-auto bg-[#005ea2] hover:bg-[#0b2b48] text-white font-semibold text-xs sm:text-sm px-6 py-2.5 rounded-xl shadow transition cursor-pointer"
                >
                  ← Effectuer une autre recherche
                </button>
              </div>
            </div>
          ) : (
            /* Formulaire de saisie coordonnées (uniquement si non encore renseigné) */
            <form onSubmit={handleStoreSaisie} className="space-y-4">
              <div className="border-b border-slate-200 pb-2 flex items-center justify-center sm:justify-start space-x-2">
                <Send className="w-4 h-4 text-[#005ea2]" />
                <h3 className="text-sm sm:text-base font-bold text-[#0b2b48]">
                  Mise à jour de vos coordonnées
                </h3>
              </div>

              {/* Alerte si une précédente saisie a été rejetée */}
              {abonne.submission_status === 'rejete' && abonne.motif_rejet && (
                <div className="p-3 bg-amber-50 text-amber-900 text-xs rounded-xl border border-amber-200 flex items-start space-x-2">
                  <Info className="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" />
                  <div>
                    <span className="font-bold">Information : </span>
                    <span>Une précédente saisie pour cette référence a été rejetée (Motif : <u>{abonne.motif_rejet}</u>). Vous pouvez soumettre vos coordonnées corrigées.</span>
                  </div>
                </div>
              )}

              {saisieError && (
                <div className="p-3 bg-red-50 text-red-700 text-xs sm:text-sm rounded-lg border border-red-200 flex items-center justify-center space-x-2">
                  <AlertTriangle className="w-4 h-4 flex-shrink-0" />
                  <span>{saisieError}</span>
                </div>
              )}

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <div className="flex items-center justify-between mb-1">
                    <label className="block text-xs font-semibold text-slate-700">
                      Numéro CIP *
                    </label>
                    <span className={`text-[10px] font-mono font-semibold px-1.5 py-0.5 rounded ${
                      inputCip.length === 15
                        ? 'bg-emerald-100 text-emerald-700'
                        : 'bg-slate-100 text-slate-500'
                    }`}>
                    </span>
                  </div>
                  <input
                    type="text"
                    required
                    inputMode="numeric"
                    pattern="[0-9]{15}"
                    maxLength={15}
                    value={inputCip}
                    onKeyDown={handleDigitsKeyDown}
                    onPaste={handlePasteDigitsOnly(setInputCip, 15)}
                    onChange={(e) => setInputCip(e.target.value.replace(/\D/g, '').slice(0, 15))}
                    placeholder="Ex : 123456789012345"
                    className={`w-full px-3.5 py-2.5 bg-white border rounded-lg text-sm font-mono focus:ring-2 focus:outline-none transition ${
                      inputCip.length === 15
                        ? 'border-emerald-400 focus:ring-emerald-500'
                        : 'border-slate-300 focus:ring-[#005ea2]'
                    }`}
                  />
                  <p className="text-[11px] text-slate-400 mt-1">
                    15 chiffres sans espace 
                  </p>
                </div>

                <div>
                  <div className="flex items-center justify-between mb-1">
                    <label className="block text-xs font-semibold text-slate-700">
                      Numéro de Téléphone *
                    </label>
                    {countryCode === '+229' && inputTel.length >= 2 && !inputTel.startsWith('01') && (
                      <span className="text-[10px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded">
                        Doit commencer par 01
                      </span>
                    )}
                  </div>
                  <div className="flex rounded-lg shadow-xs">
                    <select
                      value={countryCode}
                      onChange={(e) => setCountryCode(e.target.value)}
                      className="px-2.5 py-2.5 bg-slate-100 hover:bg-slate-200/70 border border-r-0 border-slate-300 rounded-l-lg text-xs font-semibold text-slate-700 focus:ring-2 focus:ring-[#005ea2] focus:outline-none transition cursor-pointer shrink-0 max-w-[145px] sm:max-w-[180px] truncate"
                    >
                      {COUNTRY_DIAL_CODES.map((item, index) => (
                        <option key={`${item.code}-${index}`} value={item.code}>
                          {item.label}
                        </option>
                      ))}
                    </select>
                    <input
                      type="tel"
                      required
                      inputMode="numeric"
                      pattern={countryCode === '+229' ? "01[0-9]{8}" : "[0-9]{6,15}"}
                      maxLength={countryCode === '+229' ? 10 : 15}
                      value={inputTel}
                      onKeyDown={handleDigitsKeyDown}
                      onPaste={handlePasteDigitsOnly(setInputTel, countryCode === '+229' ? 10 : 15)}
                      onChange={(e) => setInputTel(e.target.value.replace(/\D/g, '').slice(0, countryCode === '+229' ? 10 : 15))}
                      placeholder={countryCode === '+229' ? "Ex : 0197000000" : "Ex : 90000000"}
                      className={`w-full px-3.5 py-2.5 bg-white border rounded-r-lg text-sm font-mono focus:ring-2 focus:outline-none transition ${
                        countryCode === '+229'
                          ? inputTel.length === 10 && inputTel.startsWith('01')
                            ? 'border-emerald-400 focus:ring-emerald-500'
                            : inputTel.length > 0
                            ? 'border-amber-300 focus:ring-amber-400'
                            : 'border-slate-300 focus:ring-[#005ea2]'
                          : inputTel.length >= 8
                          ? 'border-emerald-400 focus:ring-emerald-500'
                          : 'border-slate-300 focus:ring-[#005ea2]'
                      }`}
                    />
                  </div>
                  <p className="text-[11px] text-slate-400 mt-1">
                    {countryCode === '+229'
                      ? "10 chiffres commençant obligatoirement par 01"
                      : "Chiffres sans espace après l'indicatif"}
                  </p>
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="block text-xs font-semibold text-slate-700">
                    Adresse E-mail *
                  </label>
                  {inputEmail.trim() && (
                    <span className={`text-[10px] font-medium px-1.5 py-0.5 rounded ${
                      /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(inputEmail.trim())
                        ? 'bg-emerald-100 text-emerald-700'
                        : 'bg-amber-100 text-amber-700'
                    }`}>
                      {/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(inputEmail.trim())
                        ? ''
                        : 'Extension manquante (@, .com, .fr...)'}
                    </span>
                  )}
                </div>
                <input
                  type="email"
                  maxLength={100}
                  value={inputEmail}
                  onChange={(e) => setInputEmail(e.target.value)}
                  placeholder="Ex : abonne@gmail.com (ou .fr, .bj, etc.)"
                  className={`w-full px-3.5 py-2.5 bg-white border rounded-lg text-sm focus:ring-2 focus:outline-none transition ${
                    inputEmail.trim()
                      ? /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(inputEmail.trim())
                        ? 'border-emerald-400 focus:ring-emerald-500'
                        : 'border-amber-300 focus:ring-amber-400'
                      : 'border-slate-300 focus:ring-[#005ea2]'
                  }`}
                />
                
              </div>

              {/* Champ ajout scan carte CIP (Facultatif) */}
              <div className="pt-1">
                <div className="flex items-center justify-between mb-1.5">
                  <label className="block text-xs font-semibold text-slate-700">
                    Scan ou photo de la carte CIP
                  </label>
                  <span className="text-[10px] font-semibold text-slate-500 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-full">
                    Facultatif
                  </span>
                </div>

                {!cipFile ? (
                  <label className="border-2 border-dashed border-slate-300 hover:border-[#005ea2] bg-slate-50/60 hover:bg-blue-50/30 rounded-xl p-4 flex flex-col items-center justify-center cursor-pointer transition group">
                    <UploadCloud className="w-7 h-7 text-slate-400 group-hover:text-[#005ea2] transition mb-1" />
                    <span className="text-xs font-semibold text-slate-700 group-hover:text-[#005ea2] text-center">
                      Cliquez ici pour joindre une copie scannée de votre carte CIP
                    </span>
                    <span className="text-[11px] text-slate-400 mt-0.5 text-center">
                      Formats acceptés : PDF, JPG, PNG (10 Mo max)
                    </span>
                    <input
                      type="file"
                      accept=".pdf,image/png,image/jpeg,image/jpg"
                      onChange={handleFileChange}
                      className="hidden"
                    />
                  </label>
                ) : (
                  <div className="border border-slate-200 bg-slate-50 rounded-xl p-3 flex items-center justify-between shadow-xs">
                    <div className="flex items-center space-x-3 overflow-hidden">
                      <div className="w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center text-[#005ea2] flex-shrink-0">
                        {cipFile.type === 'application/pdf' ? (
                          <FileText className="w-5 h-5" />
                        ) : (
                          <ImageIcon className="w-5 h-5" />
                        )}
                      </div>
                      <div className="min-w-0">
                        <p className="text-xs font-semibold text-slate-800 truncate">
                          {cipFile.name}
                        </p>
                        <p className="text-[11px] text-slate-400">
                          {(cipFile.size / (1024 * 1024)).toFixed(2)} Mo • Fichier prêt à être envoyé
                        </p>
                      </div>
                    </div>
                    <button
                      type="button"
                      onClick={() => {
                        setCipFile(null);
                        setFileError('');
                      }}
                      className="text-slate-400 hover:text-red-600 p-1.5 rounded-lg hover:bg-red-50 transition cursor-pointer"
                      title="Supprimer ce fichier"
                    >
                      <X className="w-4 h-4" />
                    </button>
                  </div>
                )}

                {fileError && (
                  <p className="text-xs text-red-600 mt-1.5 flex items-center gap-1">
                    <AlertTriangle className="w-3.5 h-3.5 flex-shrink-0" />
                    <span>{fileError}</span>
                  </p>
                )}
              </div>

              <div className="flex flex-col-reverse sm:flex-row items-center justify-between gap-3 pt-3">
                <button
                  type="button"
                  onClick={() => {
                    setCurrentStep(1);
                    setSaisieSuccess(false);
                    setCipFile(null);
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
          )}
        </div>
      )}
    </div>
  );
};

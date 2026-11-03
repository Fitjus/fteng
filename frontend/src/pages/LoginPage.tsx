import React, { useState, useEffect, useRef } from 'react';
import { AlertTriangle, RefreshCw, ArrowRight } from 'lucide-react';
import api from '../services/api';
import type { CurrentUser } from '../types';
import sonebLogo from '../assets/soneb-h80.jpg';

interface LoginPageProps {
  onLoginSuccess: (user: CurrentUser, token: string) => void;
}

export const LoginPage: React.FC<LoginPageProps> = ({ onLoginSuccess }) => {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [error, setError] = useState('');
  const callbackRan = useRef(false);

  // Vérification automatique au retour de Google (?code=...) ou d'un token (?token=...)
  useEffect(() => {
    if (callbackRan.current) return;

    const params = new URLSearchParams(window.location.search);
    const googleCode = params.get('code');
    const magicToken = params.get('token');

    if (googleCode) {
      callbackRan.current = true;
      // Nettoyer l'URL immédiatement pour éviter toute réutilisation du code unique
      window.history.replaceState({}, document.title, window.location.pathname);
      handleGoogleCallback(googleCode);
    } else if (magicToken) {
      callbackRan.current = true;
      window.history.replaceState({}, document.title, window.location.pathname);
      handleMagicToken(magicToken);
    }
  }, []);

  const handleGoogleCallback = async (code: string) => {
    setVerifying(true);
    setError('');

    try {
      const res = await api.post('/auth/google/callback', { 
        code,
        redirect_uri: window.location.origin,
      });
      onLoginSuccess(res.data.user, res.data.token);
    } catch (err: any) {
      const message = err.response?.data?.message || err.message || 'La validation Google a échoué.';
      if (message.includes('SQLSTATE') || message.includes('2002') || message.includes('refusée')) {
        setError('Impossible de se connecter à MySQL. Veuillez démarrer MySQL dans XAMPP Control Panel puis réessayer.');
      } else if (message.includes('cURL') || message.includes('timeout') || message.includes('SSL')) {
        setError('Délai d\'attente dépassé avec Google. Veuillez vérifier votre connexion Internet et cliquer sur "Suivant" pour réessayer.');
      } else {
        setError(message);
      }
    } finally {
      setVerifying(false);
    }
  };

  const handleMagicToken = async (token: string) => {
    setVerifying(true);
    setError('');

    try {
      const res = await api.post('/auth/verify-magic-link', { token });
      onLoginSuccess(res.data.user, res.data.token);
    } catch (err: any) {
      setError(
        err.response?.data?.message || 
        'Ce lien d\'accès a expiré. Veuillez vous reconnecter.'
      );
    } finally {
      setVerifying(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const cleanEmail = email.trim().toLowerCase();

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!cleanEmail || !emailRegex.test(cleanEmail)) {
      setError('Veuillez inscrire une adresse e-mail valide avant de continuer.');
      return;
    }

    setLoading(true);
    setError('');

    try {
      // Obtenir l'URL de redirection directe vers Google avec login_hint et redirect_uri exact
      const res = await api.get(
        `/auth/google/url?email=${encodeURIComponent(cleanEmail)}&redirect_uri=${encodeURIComponent(window.location.origin)}`
      );
      if (res.data.url) {
        // Redirection directe vers la page de mot de passe Google
        window.location.href = res.data.url;
        return;
      }
    } catch (err: any) {
      const msg = err.response?.data?.message;
      if (msg && msg.includes('GOOGLE_CLIENT_ID')) {
        setError(msg);
      } else {
        setError('Impossible d\'ouvrir la page Google. Veuillez vérifier votre connexion.');
      }
    } finally {
      setLoading(false);
    }
  };

  if (verifying) {
    return (
      <main className="flex-1 flex items-center justify-center px-4 py-12">
        <div className="max-w-md w-full bg-white rounded-2xl shadow-sm border border-slate-200 p-8 text-center space-y-4">
          <div className="w-14 h-14 bg-blue-50 text-[#005ea2] rounded-full flex items-center justify-center mx-auto border border-blue-200">
            <RefreshCw className="w-7 h-7 animate-spin" />
          </div>
          <h2 className="text-xl font-bold text-[#0b2b48]">Validation en cours...</h2>
          <p className="text-xs text-slate-500">
            Connexion sécurisée en cours avec Google. Veuillez patienter un instant.
          </p>
        </div>
      </main>
    );
  }

  return (
    <main className="flex-1 flex items-center justify-center px-3 sm:px-4 py-6 sm:py-12">
      <div className="max-w-md w-full bg-white rounded-xl sm:rounded-2xl shadow-sm border border-slate-200 p-5 sm:p-8">
        <div className="text-center mb-6">
          <img 
            src={sonebLogo} 
            alt="Logo SONEB" 
            className="h-12 sm:h-14 mx-auto mb-3 object-contain" 
          />
          <h2 className="text-lg sm:text-xl font-bold text-[#0b2b48]">Authentification</h2>
          <p className="text-xs text-slate-500 mt-1">
            Inscrivez votre adresse e-mail pour confirmer votre identité.
          </p>
        </div>

        {error && (
          <div className="mb-4 p-3.5 bg-red-50 text-red-700 text-xs rounded-lg border border-red-200 flex items-start space-x-2">
            <AlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" />
            <span className="leading-relaxed">{error}</span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1">
              Adresse e-mail <span className="text-red-500">*</span>
            </label>
            <input
              type="email"
              required
              value={email}
              onChange={(e) => {
                setEmail(e.target.value);
                if (error) setError('');
              }}
              placeholder="Inscrivez votre adresse e-mail"
              className="w-full px-3.5 py-2.5 sm:py-3 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-[#005ea2] focus:outline-none"
            />
          </div>

          <button
            type="submit"
            disabled={loading || !email.trim()}
            className="w-full bg-[#005ea2] hover:bg-[#0b2b48] text-white font-semibold py-2.5 sm:py-3 rounded-lg text-sm shadow transition disabled:opacity-50 mt-2 flex items-center justify-center space-x-2 cursor-pointer"
          >
            {loading ? (
              <>
                <RefreshCw className="w-4 h-4 animate-spin" />
                <span>Redirection vers Google...</span>
              </>
            ) : (
              <>
                <span>Suivant</span>
                <ArrowRight className="w-4 h-4" />
              </>
            )}
          </button>
        </form>
      </div>
    </main>
  );
};

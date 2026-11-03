import React, { useState, useEffect } from 'react';
import { 
  BrowserRouter, 
  Routes, 
  Route, 
  Navigate, 
  NavLink, 
  useNavigate, 
  useLocation 
} from 'react-router-dom';
import { LogOut } from 'lucide-react';
import api, { setAuthToken } from './services/api';
import type { CurrentUser } from './types';
import sonebLogo from './assets/soneb-h80.jpg';
import { LoginPage } from './pages/LoginPage';
import { UpdatePage } from './pages/UpdatePage';
import { AdminStagingPage } from './pages/AdminStagingPage';

interface AuthenticatedLayoutProps {
  user: CurrentUser;
  onLogout: () => void;
  children: React.ReactNode;
}

function AuthenticatedLayout({ user, onLogout, children }: AuthenticatedLayoutProps) {
  return (
    <div className="min-h-screen bg-slate-50/40 text-[#333333] flex flex-col font-sans">
      <main className="flex-1 max-w-5xl w-full mx-auto px-4 sm:px-6 py-6 sm:py-10 space-y-6 sm:space-y-8 flex flex-col">
        {/* En-tête directement sur la page : Logo + Navigation par routes (sans navbar) */}
        <div className="flex flex-col sm:flex-row justify-between items-center gap-4 pb-4 border-b border-slate-200">
          <div className="flex items-center">
            <img 
              src={sonebLogo} 
              alt="Logo SONEB" 
              className="h-10 sm:h-12 w-auto object-contain" 
            />
          </div>

          <div className="flex items-center space-x-3 sm:space-x-4">
            {/* Navigation par onglets (Routes) réservée à l'Administrateur */}
            {user.is_admin && (
              <div className="flex bg-slate-100 p-1 rounded-lg text-xs font-semibold">
                <NavLink
                  to="/update"
                  className={({ isActive }) =>
                    `px-3 py-1.5 rounded-md transition cursor-pointer ${
                      isActive
                        ? 'bg-[#005ea2] text-white shadow-sm'
                        : 'text-slate-600 hover:text-slate-900'
                    }`
                  }
                >
                  Mise à jour
                </NavLink>
                <NavLink
                  to="/admin"
                  className={({ isActive }) =>
                    `px-3 py-1.5 rounded-md transition cursor-pointer ${
                      isActive
                        ? 'bg-[#005ea2] text-white shadow-sm'
                        : 'text-slate-600 hover:text-slate-900'
                    }`
                  }
                >
                  Validation (Staging)
                </NavLink>
              </div>
            )}

            <div className="flex items-center space-x-3 pl-2 border-l border-slate-200">
              <span className="text-xs font-bold text-[#0b2b48] hidden sm:inline">{user.name}</span>
              <button
                onClick={onLogout}
                className="flex items-center space-x-1.5 text-xs text-slate-500 hover:text-red-600 font-semibold p-1.5 rounded-lg hover:bg-red-50 transition cursor-pointer"
                title="Se déconnecter"
              >
                <LogOut className="w-4 h-4" />
                <span>Déconnexion</span>
              </button>
            </div>
          </div>
        </div>

        {/* Contenu de la page courante parfaitement centré */}
        <div className="flex-1 flex flex-col items-center justify-start w-full">
          {children}
        </div>
      </main>
    </div>
  );
}

function AppRoutes() {
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [token, setToken] = useState<string | null>(() => {
    // Purger définitivement tout ancien token persistant dans localStorage
    localStorage.removeItem('soneb_token');
    return sessionStorage.getItem('soneb_session_token');
  });
  const [loadingInitial, setLoadingInitial] = useState(true);
  const navigate = useNavigate();
  const location = useLocation();

  // Synchronisation du token avec l'API
  useEffect(() => {
    if (token) {
      setAuthToken(token);
      fetchCurrentUser();
    } else {
      setAuthToken(null);
      setUser(null);
      setLoadingInitial(false);
    }
  }, [token]);

  const fetchCurrentUser = async () => {
    try {
      const res = await api.get('/me');
      setUser(res.data.user);
    } catch {
      handleLogout();
    } finally {
      setLoadingInitial(false);
    }
  };

  const handleLoginSuccess = (loggedInUser: CurrentUser, receivedToken: string) => {
    sessionStorage.setItem('soneb_session_token', receivedToken);
    setToken(receivedToken);
    setUser(loggedInUser);
    if (loggedInUser.is_admin) {
      navigate('/admin');
    } else {
      navigate('/update');
    }
  };

  const handleLogout = async () => {
    try {
      if (token) await api.post('/logout');
    } catch (_) {}
    localStorage.removeItem('soneb_token');
    sessionStorage.removeItem('soneb_session_token');
    setToken(null);
    setUser(null);
    navigate('/login');
  };

  // Pendant la vérification du token au chargement
  if (loadingInitial) {
    return (
      <div className="min-h-screen bg-white flex items-center justify-center">
        <div className="text-xs text-slate-400 font-medium animate-pulse">
          Vérification de la session...
        </div>
      </div>
    );
  }

  // Si l'URL contient un retour Google (?code=...) ou un lien magique (?token=...)
  const hasAuthParams = location.search.includes('code=') || location.search.includes('token=');

  return (
    <Routes>
      {/* Route /login */}
      <Route
        path="/login"
        element={
          user && !hasAuthParams ? (
            <Navigate to={user.is_admin ? "/admin" : "/update"} replace />
          ) : (
            <div className="min-h-screen bg-white text-[#333333] flex flex-col font-sans">
              <LoginPage onLoginSuccess={handleLoginSuccess} />
            </div>
          )
        }
      />

      {/* Route /update */}
      <Route
        path="/update"
        element={
          hasAuthParams ? (
            <Navigate to={`/login${location.search}`} replace />
          ) : user ? (
            <AuthenticatedLayout user={user} onLogout={handleLogout}>
              <UpdatePage currentUser={user} />
            </AuthenticatedLayout>
          ) : (
            <Navigate to="/login" replace />
          )
        }
      />

      {/* Route /admin (Staging & Validation) */}
      <Route
        path="/admin"
        element={
          hasAuthParams ? (
            <Navigate to={`/login${location.search}`} replace />
          ) : !user ? (
            <Navigate to="/login" replace />
          ) : user.is_admin ? (
            <AuthenticatedLayout user={user} onLogout={handleLogout}>
              <AdminStagingPage token={token} />
            </AuthenticatedLayout>
          ) : (
            <Navigate to="/update" replace />
          )
        }
      />

      {/* Route racine (/) */}
      <Route
        path="/"
        element={
          hasAuthParams ? (
            <Navigate to={`/login${location.search}`} replace />
          ) : user ? (
            <Navigate to={user.is_admin ? "/admin" : "/update"} replace />
          ) : (
            <Navigate to="/login" replace />
          )
        }
      />

      {/* Redirection 404 */}
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <AppRoutes />
    </BrowserRouter>
  );
}

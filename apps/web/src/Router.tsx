import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { I18nProvider } from '@sanara/i18n';
import { ToastProvider } from '@sanara/ui';
import { Home } from './App';
import { DashboardPage } from './pages/DashboardPage';
import { SetupPage } from './pages/SetupPage';
import { TeamPage } from './pages/TeamPage';
import { LoginPage } from './pages/LoginPage';
import { ApplyPage } from './pages/ApplyPage';
import { ActivatePage, SentPage, TrackPage } from './pages/TrackPages';
import { AdminApplicationPage, AdminApplicationsPage } from './pages/AdminPages';
import { AdminLayout, PharmacyLayout, PublicLayout } from './app/layouts';
import { RequireArea } from './app/guards';
import { SessionProvider } from './app/session';

const Placeholder = ({ name }: { name: string }) => <h1>{name}</h1>;

export function Router() {
  return (
    <I18nProvider>
      <SessionProvider>
        <ToastProvider>
          <BrowserRouter>
            <Routes>
              <Route element={<PublicLayout />}>
                <Route index element={<Home />} />
                <Route path="connexion" element={<LoginPage />} />
                <Route path="inscription" element={<ApplyPage />} />
                <Route path="inscription/envoyee" element={<SentPage />} />
                <Route path="inscription/suivi/:code" element={<TrackPage />} />
                <Route path="activer/:token" element={<ActivatePage />} />
              </Route>
              <Route path="app" element={<RequireArea area="pharmacy" />}>
                <Route element={<PharmacyLayout />}>
                  <Route index element={<DashboardPage />} />
                  <Route path="configuration" element={<SetupPage />} />
                  <Route path="equipe" element={<TeamPage />} />
                </Route>
              </Route>
              <Route path="admin" element={<RequireArea area="admin" />}>
                <Route element={<AdminLayout />}>
                  <Route index element={<AdminApplicationsPage />} />
                  <Route path="demandes/:id" element={<AdminApplicationPage />} />
                </Route>
              </Route>
            </Routes>
          </BrowserRouter>
        </ToastProvider>
      </SessionProvider>
    </I18nProvider>
  );
}

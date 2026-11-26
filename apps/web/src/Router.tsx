import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { I18nProvider } from '@sanara/i18n';
import { ToastProvider } from '@sanara/ui';
import { Home } from './App';
import { DashboardPage } from './pages/DashboardPage';
import { CatalogPage } from './pages/CatalogPage';
import { StockPage } from './pages/StockPage';
import { InventoryPage } from './pages/InventoryPage';
import { PosPage } from './pages/PosPage';
import { SalesPage } from './pages/SalesPage';
import { SetupPage } from './pages/SetupPage';
import { TeamPage } from './pages/TeamPage';
import { ForgotPage, ResetPage, SecurityPage } from './pages/SecurityPages';
import { LoginPage } from './pages/LoginPage';
import { ApplyPage } from './pages/ApplyPage';
import { ActivatePage, SentPage, TrackPage } from './pages/TrackPages';
import { AdminApplicationPage, AdminApplicationsPage } from './pages/AdminPages';
import {
  AdminAuditPage,
  AdminBillingPage,
  AdminCountriesPage,
  AdminDashboardPage,
  AdminPharmaciesPage,
  AdminPharmacyPage,
  AdminPlansPage,
  AdminReportsPage,
  AdminSettingsPage,
  AdminTemplatesPage,
} from './pages/AdminConsole';
import { AdminLayout, PharmacyLayout, PublicLayout } from './app/layouts';
import { RequireArea, RequireSession } from './app/guards';
import { SessionProvider } from './app/session';
import { PharmaciesPage } from './pages/PharmaciesPage';

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
                <Route path="pharmacies" element={<PharmaciesPage />} />
                <Route path="inscription" element={<ApplyPage />} />
                <Route path="inscription/envoyee" element={<SentPage />} />
                <Route path="inscription/suivi/:code" element={<TrackPage />} />
                <Route path="activer/:token" element={<ActivatePage />} />
                <Route path="mot-de-passe-oublie" element={<ForgotPage />} />
                <Route path="reinitialiser/:token" element={<ResetPage />} />
                <Route element={<RequireSession />}>
                  <Route path="securite" element={<SecurityPage />} />
                </Route>
              </Route>
              <Route path="app" element={<RequireArea area="pharmacy" />}>
                <Route element={<PharmacyLayout />}>
                  <Route index element={<DashboardPage />} />
                  <Route path="configuration" element={<SetupPage />} />
                  <Route path="equipe" element={<TeamPage />} />
                  <Route path="produits" element={<CatalogPage />} />
                  <Route path="stock" element={<StockPage />} />
                  <Route path="stock/inventaire" element={<InventoryPage />} />
                  <Route path="caisse" element={<PosPage />} />
                  <Route path="ventes" element={<SalesPage />} />
                </Route>
              </Route>
              <Route path="admin" element={<RequireArea area="admin" />}>
                <Route element={<AdminLayout />}>
                  <Route index element={<AdminDashboardPage />} />
                  <Route path="demandes" element={<AdminApplicationsPage />} />
                  <Route path="demandes/:id" element={<AdminApplicationPage />} />
                  <Route path="pharmacies" element={<AdminPharmaciesPage />} />
                  <Route path="pharmacies/:id" element={<AdminPharmacyPage />} />
                  <Route path="abonnements" element={<AdminPlansPage />} />
                  <Route path="facturation" element={<AdminBillingPage />} />
                  <Route path="signalements" element={<AdminReportsPage />} />
                  <Route path="modeles" element={<AdminTemplatesPage />} />
                  <Route path="audit" element={<AdminAuditPage />} />
                  <Route path="pays" element={<AdminCountriesPage />} />
                  <Route path="parametres" element={<AdminSettingsPage />} />
                </Route>
              </Route>
            </Routes>
          </BrowserRouter>
        </ToastProvider>
      </SessionProvider>
    </I18nProvider>
  );
}

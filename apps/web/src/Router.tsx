import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { I18nProvider } from '@sanara/i18n';
import { ToastProvider } from '@sanara/ui';
import { Home } from './App';
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
                <Route path="connexion" element={<Placeholder name="Connexion" />} />
              </Route>
              <Route path="app" element={<RequireArea area="pharmacy" />}>
                <Route element={<PharmacyLayout />}>
                  <Route index element={<Placeholder name="Tableau de bord" />} />
                </Route>
              </Route>
              <Route path="admin" element={<RequireArea area="admin" />}>
                <Route element={<AdminLayout />}>
                  <Route index element={<Placeholder name="Console d’administration" />} />
                </Route>
              </Route>
            </Routes>
          </BrowserRouter>
        </ToastProvider>
      </SessionProvider>
    </I18nProvider>
  );
}

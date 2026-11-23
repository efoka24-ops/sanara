import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '@sanara/ui/tokens.css';
import './styles.css';
import { Router } from './Router';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Router />
  </StrictMode>,
);

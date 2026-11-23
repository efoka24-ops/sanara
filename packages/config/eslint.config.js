import tseslint from 'typescript-eslint';

// Règle i18n : aucun texte en dur dans le JSX (voir T032).
export default tseslint.config(...tseslint.configs.recommended, {
  files: ['**/*.{ts,tsx}'],
  rules: {
    'no-restricted-syntax': [
      'warn',
      {
        selector: 'JSXText[value=/[A-Za-zÀ-ÿ]{3,}/]',
        message: 'Texte en dur interdit : utiliser useT() (packages/i18n).',
      },
    ],
  },
  ignores: ['**/dist/**', '**/node_modules/**', '**/vendor/**'],
});

import { describe, expect, it } from 'vitest';
import { MESSAGES, translate } from './index';

describe('i18n', () => {
  it('fr et en ont exactement les mêmes clés', () => {
    expect(Object.keys(MESSAGES.en).sort()).toEqual(Object.keys(MESSAGES.fr).sort());
  });
  it('aucune traduction vide', () => {
    for (const l of ['fr', 'en'] as const)
      for (const [k, v] of Object.entries(MESSAGES[l])) expect(v.trim(), `${l}:${k}`).not.toBe('');
  });
  it('remplace les variables et retombe sur la clé', () => {
    expect(translate('fr', 'inconnue')).toBe('inconnue');
    expect(translate('en', 'common.save')).toBe('Save');
  });
});

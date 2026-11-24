import { describe, expect, it } from 'vitest';
import { isTeamPermissionChecked } from './index';

describe('isTeamPermissionChecked', () => {
  it('reflects API effective permissions for toggle state', () => {
    const member = { permissions: ['sales.discount', 'finance.read'] };
    expect(isTeamPermissionChecked(member, 'sales.discount')).toBe(true);
    expect(isTeamPermissionChecked(member, 'sales.cancel')).toBe(false);
  });

  it('defaults to false when permissions are missing', () => {
    expect(isTeamPermissionChecked({}, 'finance.read')).toBe(false);
  });
});

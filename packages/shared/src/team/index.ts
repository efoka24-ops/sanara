export interface TeamMemberPermissionView {
  permissions?: string[];
}

export function isTeamPermissionChecked(
  member: TeamMemberPermissionView,
  permission: string,
): boolean {
  return (member.permissions ?? []).includes(permission);
}

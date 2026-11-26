export function getDisplayName(user) {
  const roleName = user?.role?.role_name;
  const roleSuffix = roleName ? ` (${roleName})` : '';

  if (roleName && user?.full_name?.endsWith(roleSuffix)) {
    return user.full_name.slice(0, -roleSuffix.length);
  }

  return user?.full_name ?? '';
}

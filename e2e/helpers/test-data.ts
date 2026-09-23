let counter = 0;

export function uniqueName(prefix: string) {
  return `${prefix} ${Date.now()}-${++counter}`;
}

export function uniqueEmail(prefix: string) {
  return `${prefix}-${Date.now()}-${++counter}@b2b-crm.loc`;
}

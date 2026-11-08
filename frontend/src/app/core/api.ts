/** Formats communs définis dans docs/conventions.md. */
export interface Page<T> {
  items: T[];
  page: number;
  size: number;
  total: number;
}

export interface ApiError {
  status: number;
  error: string;
  message: string;
  details?: { field: string; message: string }[];
}

/** Extrait un message lisible d'une erreur HTTP renvoyée par un service. */
export function errorMessage(err: unknown): string {
  const body = (err as { error?: Partial<ApiError> })?.error;
  return body?.message ?? 'Une erreur est survenue. Réessayez plus tard.';
}

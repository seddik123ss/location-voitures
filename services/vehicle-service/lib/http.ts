export type ErrorDetail = { field: string; message: string };

export function errorResponse(
  status: number,
  error: string,
  message: string,
  details: ErrorDetail[] = []
) {
  return Response.json({ status, error, message, details }, { status });
}

export const badRequest = (message: string, details: ErrorDetail[] = []) =>
  errorResponse(400, "BAD_REQUEST", message, details);

export const notFound = (message: string) =>
  errorResponse(404, "NOT_FOUND", message);

export const conflict = (message: string, details: ErrorDetail[] = []) =>
  errorResponse(409, "CONFLICT", message, details);

export const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
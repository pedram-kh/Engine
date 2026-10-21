/**
 * The ONE place the web apps know the support address (AH-104b-del P12).
 *
 * The API has its own single place (`config('app.support_email')`, `SUPPORT_EMAIL`). Neither
 * runtime types the address anywhere else: the public delete-account page and the error
 * copy read it from here, and an architecture spec fails the build when the literal turns
 * up in any other source file. Override per environment with `VITE_SUPPORT_EMAIL`.
 */
export const SUPPORT_EMAIL: string =
  (import.meta.env.VITE_SUPPORT_EMAIL ?? '').trim() || 'support@catalyst-growth.com'

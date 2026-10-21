/**
 * The ONE way an agency screen turns a creator into text, an initial or an avatar
 * (AH-104b-del B3).
 *
 * A deleted creator keeps their row: the agency's roster, threads, applications and
 * assignments stay, so every API payload that carries a creator also carries `is_erased`
 * and no name or avatar. The screen must say "Deleted creator" in the viewer's language,
 * never the sentinel the database stores and never a blank or a "?" that looks like a bug.
 *
 * Every agency module reads creators through these functions; an architecture spec
 * (`creator-label-single-reader.spec.ts`) fails the build when a component reads
 * `display_name` or `avatar_url` directly. The guard is therefore in one place: even if a
 * payload ever leaked a name for an erased creator, this is where it would be refused.
 */

/** The fields every creator-bearing agency payload shares (all optional: slim shapes omit some). */
export interface CreatorLike {
  display_name?: string | null
  avatar_url?: string | null
  is_erased?: boolean
}

type Translate = (key: string) => string

export function creatorIsErased(creator: CreatorLike | null | undefined): boolean {
  return creator?.is_erased === true
}

/**
 * The text for a creator: the localised "Deleted creator" when erased, else the trimmed
 * name, else `fallback` (the screen's own already-translated "unnamed" wording, or '').
 */
export function creatorLabel(
  creator: CreatorLike | null | undefined,
  t: Translate,
  fallback = '',
): string {
  if (creatorIsErased(creator)) {
    return t('app.creatorErased')
  }
  const name = creator?.display_name?.trim()
  return name !== undefined && name !== '' ? name : fallback
}

/** One upper-cased letter for an avatar fallback; `?` for an erased or nameless creator. */
export function creatorInitial(creator: CreatorLike | null | undefined): string {
  if (creatorIsErased(creator)) {
    return '?'
  }
  const name = creator?.display_name?.trim()
  return name !== undefined && name !== '' ? (name.charAt(0).toUpperCase() as string) : '?'
}

/** The avatar to draw: never one for an erased creator, whatever the payload carried. */
export function creatorAvatarUrl(creator: CreatorLike | null | undefined): string | null {
  if (creatorIsErased(creator)) {
    return null
  }
  return creator?.avatar_url ?? null
}

/** Alt text for an avatar image: the label, but empty (decorative) for an erased creator. */
export function creatorAvatarAlt(creator: CreatorLike | null | undefined): string {
  return creatorIsErased(creator) ? '' : (creator?.display_name ?? '')
}

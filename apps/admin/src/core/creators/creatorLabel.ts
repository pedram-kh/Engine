/**
 * The ONE way an admin screen turns a creator (or an account) into text (AH-104c C2-4).
 *
 * An erased account keeps its row, but its name is gone and its email is an undeliverable
 * placeholder (`erased-<ulid>@erased.invalid`). Every admin payload that carries a creator
 * also carries `is_erased` and `deletion_pending`, so a screen can say "Deleted creator" in
 * the admin's language instead of a blank, a "?" or the placeholder, and can mark an account
 * that has asked to leave before a reviewer approves, messages or impersonates it.
 *
 * Every admin module reads creators through these functions; an architecture spec
 * (`creator-label-single-reader.spec.ts`) fails the build when a component reads
 * `display_name` straight off a payload. So even if a payload ever leaked a name or the
 * placeholder for an erased creator, this is where it would be refused.
 *
 * `deletion_pending` is admin-only data: it exists on admin payloads and nowhere else.
 */

/** The fields every creator-bearing admin payload shares (all optional: slim shapes omit some). */
export interface CreatorLike {
  display_name?: string | null
  email?: string | null
  is_erased?: boolean
  deletion_pending?: boolean
}

/** An account (not a creator row): the impersonation search carries `name`, not `display_name`. */
export interface AccountLike {
  name?: string | null
  email?: string | null
  is_erased?: boolean
  deletion_pending?: boolean
}

type Translate = (key: string) => string

export function creatorIsErased(creator: CreatorLike | AccountLike | null | undefined): boolean {
  return creator?.is_erased === true
}

/** The person has asked to delete the account and the request stands (never true once erased). */
export function creatorDeletionPending(
  creator: CreatorLike | AccountLike | null | undefined,
): boolean {
  return creator?.deletion_pending === true && !creatorIsErased(creator)
}

/**
 * The text for a creator: the localised "Deleted creator" when erased, else the trimmed name,
 * else `fallback` (the screen's own already-translated "unnamed" wording, or '').
 */
export function creatorLabel(
  creator: CreatorLike | null | undefined,
  t: Translate,
  fallback = '',
): string {
  if (creatorIsErased(creator)) {
    return t('admin.creators.erased')
  }
  const name = creator?.display_name?.trim()
  return name !== undefined && name !== '' ? name : fallback
}

/** Same, for an account that carries `name` (the impersonation search). */
export function accountLabel(
  account: AccountLike | null | undefined,
  t: Translate,
  fallback = '',
): string {
  if (creatorIsErased(account)) {
    return t('admin.creators.erased')
  }
  const name = account?.name?.trim()
  return name !== undefined && name !== '' ? name : fallback
}

/**
 * A creator resource (the admin detail) as the shape the readers take: the name from
 * `attributes`, the email and the two flags from `admin_attributes`.
 */
export function creatorFromResource(
  resource:
    | {
        attributes: { display_name?: string | null }
        admin_attributes?: {
          email?: string | null
          is_erased?: boolean
          deletion_pending?: boolean
        }
      }
    | null
    | undefined,
): CreatorLike {
  return {
    display_name: resource?.attributes.display_name ?? null,
    email: resource?.admin_attributes?.email ?? null,
    is_erased: resource?.admin_attributes?.is_erased ?? false,
    deletion_pending: resource?.admin_attributes?.deletion_pending ?? false,
  }
}

/** The email to show: never the placeholder of an erased account. */
export function creatorEmail(creator: CreatorLike | AccountLike | null | undefined): string | null {
  if (creatorIsErased(creator)) {
    return null
  }
  const email = creator?.email?.trim()
  return email !== undefined && email !== '' ? email : null
}

/**
 * The stored name for an edit form to pre-fill: the raw field, '' for an erased creator (so a
 * form never offers to edit a name that is gone). Not for display: use {@link creatorLabel}.
 */
export function creatorRawName(creator: CreatorLike | null | undefined): string {
  return creatorIsErased(creator) ? '' : (creator?.display_name ?? '')
}

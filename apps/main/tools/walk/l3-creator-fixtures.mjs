/**
 * AH-115 L3 — mock API for the REAL creator pages (dashboard, availability, assignments and
 * detail, onboarding, profile, job detail), used by `shots-ah115-l3.mjs`. Anything else falls
 * through to the L2 and shared fixtures. Dev tool only. Fixture text avoids the banned vocabulary.
 */

import { creatorFixtureFor } from './l2-fixtures.mjs'

export const ASSIGNMENT = '01HASSIGNMENT0000000001'
export const JOB = '01HJOB0000000000000000001'
/** AH-120: a job the caller is brand-locked out of ("Not available to you"). Additive walk target. */
export const JOB_BRAND_LOCKED = '01HJOB0000000000000000002'

const iso = (days, hours = 0) =>
  new Date(Date.now() + days * 86_400_000 + hours * 3_600_000).toISOString()

function creator(status) {
  const incomplete = status === 'incomplete'
  return {
    data: {
      id: '01HCREATORMAYA00000000001',
      type: 'creators',
      attributes: {
        display_name: 'Maya Thompson',
        bio: 'Lifestyle and skincare creator based in Lisbon.',
        country_code: 'PT',
        region: 'Lisboa',
        date_of_birth: '1994-03-17',
        age: 32,
        phone: null,
        whatsapp: null,
        address_street: null,
        address_postal_code: null,
        primary_language: 'en',
        secondary_languages: ['pt'],
        categories: ['lifestyle', 'beauty'],
        avatar_path: incomplete ? null : 'creators/01/avatar.jpg',
        cover_path: null,
        avatar_url: null,
        cover_url: null,
        verification_level: 'unverified',
        application_status: status,
        tier: null,
        kyc_status: 'none',
        kyc_verified_at: null,
        tax_profile_complete: false,
        payout_method_set: false,
        has_signed_master_contract: false,
        click_through_accepted_at: null,
        social_accounts: incomplete
          ? []
          : [
              {
                platform: 'instagram',
                handle: 'maya.makes',
                profile_url: 'https://example.com/maya.makes',
                is_primary: true,
              },
            ],
        portfolio: [],
        profile_completeness_score: incomplete ? 40 : 100,
        submitted_at: incomplete ? null : iso(-20),
        approved_at: status === 'approved' ? iso(-10) : null,
        created_at: iso(-30),
        updated_at: iso(-2),
      },
      wizard: {
        next_step: incomplete ? 'profile' : 'review',
        is_submitted: !incomplete,
        steps: [],
        weights: {},
        flags: {
          kyc_verification_enabled: false,
          creator_payout_method_enabled: false,
          contract_signing_enabled: false,
        },
      },
    },
  }
}

function assignment(id, status, name, brand, fee) {
  return {
    id,
    type: 'campaign_assignment',
    attributes: {
      status,
      agreed_fee_minor_units: fee,
      agreed_fee_currency: 'EUR',
      fee_per: 'per video',
      offer_description: 'A calm, natural style with real routines.',
      offer_attachment: null,
      countered_fee_minor_units: null,
      countered_fee_currency: null,
      deliverables: ['1 video', '15 to 30 seconds'],
      posting_due_at: iso(12),
      invited_at: iso(-3),
      campaign: {
        id: `01HCAMP${id.slice(-6)}`,
        name,
        posting_window_starts_at: iso(5),
        posting_window_ends_at: iso(19),
        brand_name: brand,
      },
    },
  }
}

export function l3CreatorFixtures({ status = 'approved' } = {}) {
  return function fixtureFor(url, method) {
    const p = url.pathname
    const api = '/api/v1/creators/me'
    if (method === 'GET' && p === api) return creator(status)
    if (method === 'GET' && p === `${api}/connection-requests`) return { data: [] }
    if (method === 'GET' && p === `${api}/assignments`) {
      return {
        data: [
          assignment(ASSIGNMENT, 'invited', 'Summer skincare UGC', 'Glow Labs', 250000),
          assignment(
            '01HASSIGNMENT0000000002',
            'accepted',
            'Autumn coffee launch',
            'Northwind Coffee',
            180000,
          ),
          assignment(
            '01HASSIGNMENT0000000003',
            'approved',
            'Studio audio review',
            'Lumen Audio',
            320000,
          ),
        ],
      }
    }
    if (method === 'GET' && p === `${api}/assignments/${ASSIGNMENT}`) {
      const row = assignment(ASSIGNMENT, 'invited', 'Summer skincare UGC', 'Glow Labs', 250000)
      return {
        data: {
          ...row,
          attributes: {
            ...row.attributes,
            attachments: [],
            submitted_draft_at: null,
            approved_at: null,
            posted_at: null,
            campaign: {
              ...row.attributes.campaign,
              description: 'Three short videos showing a real morning routine.',
            },
          },
          relationships: { drafts: [], posted_content: [], contract: null },
        },
        meta: {},
      }
    }
    if (p.startsWith(`${api}/assignments/${ASSIGNMENT}/messages`)) {
      return {
        data: [],
        meta: {
          thread: {
            id: 'thread-a',
            assignment_id: ASSIGNMENT,
            last_message_at: null,
            unread_count: 0,
            human_send_blocked: false,
          },
          has_more: false,
        },
      }
    }
    if (method === 'GET' && p === `${api}/availability`) {
      const block = (id, d, kind, reason) => ({
        id,
        type: 'availability_blocks',
        attributes: {
          starts_at: iso(d),
          ends_at: iso(d + 1),
          is_all_day: true,
          block_type: 'unavailable',
          kind,
          reason,
          is_recurring: false,
          recurrence_rule: null,
        },
      })
      return {
        data: [
          block('01HBLOCK00000000000000001', 2, 'vacation', 'Family trip'),
          block('01HBLOCK00000000000000002', 9, 'personal', null),
        ],
        meta: { window: { from: iso(-30), to: iso(60) } },
      }
    }
    if (method === 'GET' && p === `${api}/jobs/${JOB_BRAND_LOCKED}`) {
      return {
        data: {
          id: JOB_BRAND_LOCKED,
          type: 'creator_job',
          attributes: {
            name: 'Yahoo Spring creators',
            listing_fee: '€300 per video',
            listing_duration: '4 weeks',
            applicant_count: 3,
            listed_at: iso(-2),
            application_status: null,
            assignment_state: null,
            description: 'Three short-form videos per month, filmed at home with natural light.',
            listing_languages: ['en', 'pt'],
            listing_regions: ['PT', 'ES'],
            is_region_locked: false,
            region_lock_reason: null,
            is_brand_locked: true,
            brand_lock_reason: 'brand_exclusive',
            listing_examples_url: null,
            assignment_ulid: null,
            attachments: [],
            brand: { name: 'Yahoo', logo_url: null, website_url: null },
          },
        },
      }
    }
    if (method === 'GET' && p === `${api}/jobs/${JOB}`) {
      return {
        data: {
          id: JOB,
          type: 'creator_job',
          attributes: {
            name: 'Autumn UGC push',
            listing_fee: '€300 per video',
            listing_duration: '4 weeks',
            applicant_count: 3,
            listed_at: iso(-2),
            application_status: null,
            assignment_state: null,
            description: 'Three short-form videos per month, filmed at home with natural light.',
            listing_languages: ['en', 'pt'],
            listing_regions: ['PT', 'ES'],
            is_region_locked: false,
            region_lock_reason: null,
            listing_examples_url: 'https://examples.test/work',
            assignment_ulid: null,
            attachments: [],
            brand: {
              name: 'Northwind Coffee',
              logo_url: null,
              website_url: 'https://northwind.test',
            },
          },
        },
      }
    }
    return creatorFixtureFor(url, method)
  }
}

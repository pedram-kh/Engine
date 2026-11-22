<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Models\WaveConceptLink;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;

/**
 * Reference links of a concept (PL-2.3, ruling C4). The server never fetches the URL: see {@see ConceptLinkRules}. At most
 * 10 per concept (ruling Q8). Each add, edit and remove counts as an edit of the concept (an approved one goes back to
 * Awaiting). Audit rows carry ids and a flag, never the URL or the title.
 */
final class ConceptLinkService
{
    public function __construct(private readonly ConceptService $concepts) {}

    /** @return array{link: WaveConceptLink, reset_to_awaiting: bool} */
    public function add(Brand $brand, Wave $wave, string $conceptUlid, User $actor, string $url, string $title): array
    {
        StrategyGuard::assertBrandWritable($brand);
        $url = ConceptLinkRules::validate($url);

        return StrategyTrackerSync::transaction($wave, $actor, [StrategyStepState::CONCEPTS], function () use ($wave, $conceptUlid, $actor, $url, $title): array {
            $locked = StrategyGuard::lockWave($wave);
            $concept = $this->concepts->lockedConcept($locked, $conceptUlid);

            $count = WaveConceptLink::query()->where('concept_id', $concept->id)->count();
            if ($count >= StrategyLimits::LINKS_PER_CONCEPT) {
                throw StrategyRuleViolation::linksFull();
            }

            $link = WaveConceptLink::query()->create([
                'agency_id' => $locked->agency_id,
                'wave_id' => $locked->id,
                'market_id' => $concept->market_id,
                'concept_id' => $concept->id,
                'url' => $url,
                'title' => $title,
                'position' => $count + 1,
            ]);
            $reset = ConceptService::resetIfApproved($concept);
            Audit::log(
                action: AuditAction::WaveConceptLinkAdded,
                actor: $actor,
                subject: $link,
                metadata: ['wave' => $locked->ulid, 'concept' => $concept->ulid, 'link' => $link->ulid, 'reset_to_awaiting' => $reset],
                agencyId: $locked->agency_id,
            );

            return ['link' => $link, 'reset_to_awaiting' => $reset];
        });
    }

    /**
     * @param  array<string, mixed>  $data  `url` and/or `title`
     * @return array{link: WaveConceptLink, reset_to_awaiting: bool}
     */
    public function update(Brand $brand, Wave $wave, string $conceptUlid, string $linkUlid, User $actor, array $data): array
    {
        StrategyGuard::assertBrandWritable($brand);
        if (array_key_exists('url', $data)) {
            $data['url'] = ConceptLinkRules::validate((string) $data['url']);
        }

        return StrategyTrackerSync::transaction($wave, $actor, [StrategyStepState::CONCEPTS], function () use ($wave, $conceptUlid, $linkUlid, $actor, $data): array {
            $locked = StrategyGuard::lockWave($wave);
            $concept = $this->concepts->lockedConcept($locked, $conceptUlid);
            $link = $this->find($concept->id, $linkUlid);

            $changed = false;
            foreach (['url', 'title'] as $key) {
                if (array_key_exists($key, $data) && $data[$key] !== $link->getAttribute($key)) {
                    $link->setAttribute($key, $data[$key]);
                    $changed = true;
                }
            }
            if (! $changed) {
                return ['link' => $link, 'reset_to_awaiting' => false];
            }

            $link->save();
            $reset = ConceptService::resetIfApproved($concept);
            Audit::log(
                action: AuditAction::WaveConceptLinkUpdated,
                actor: $actor,
                subject: $link,
                metadata: ['wave' => $locked->ulid, 'concept' => $concept->ulid, 'link' => $link->ulid, 'reset_to_awaiting' => $reset],
                agencyId: $locked->agency_id,
            );

            return ['link' => $link, 'reset_to_awaiting' => $reset];
        });
    }

    public function remove(Brand $brand, Wave $wave, string $conceptUlid, string $linkUlid, User $actor): bool
    {
        StrategyGuard::assertBrandWritable($brand);

        return StrategyTrackerSync::transaction($wave, $actor, [StrategyStepState::CONCEPTS], function () use ($wave, $conceptUlid, $linkUlid, $actor): bool {
            $locked = StrategyGuard::lockWave($wave);
            $concept = $this->concepts->lockedConcept($locked, $conceptUlid);
            $link = $this->find($concept->id, $linkUlid);
            $link->delete();

            $reset = ConceptService::resetIfApproved($concept);
            Audit::log(
                action: AuditAction::WaveConceptLinkRemoved,
                actor: $actor,
                subject: $link,
                metadata: ['wave' => $locked->ulid, 'concept' => $concept->ulid, 'link' => $link->ulid, 'reset_to_awaiting' => $reset],
                agencyId: $locked->agency_id,
            );

            return $reset;
        });
    }

    private function find(int $conceptId, string $ulid): WaveConceptLink
    {
        $link = WaveConceptLink::query()->where('concept_id', $conceptId)->where('ulid', $ulid)->first();
        if ($link === null) {
            throw StrategyRuleViolation::conceptNotFound();
        }

        return $link;
    }
}

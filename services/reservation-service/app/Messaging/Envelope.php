<?php

namespace App\Messaging;

use App\Http\Json;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Enveloppe commune des messages : {"eventId", "type", "occurredAt", "data"} (docs/events/README.md). */
final class Envelope
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly string $occurredAt,
        public readonly array $data,
    ) {
    }

    /** Nouveau message à publier : eventId unique (UUID v4), occurredAt = maintenant en UTC. */
    public static function create(string $type, array $data): self
    {
        return new self((string) Str::uuid(), $type, Carbon::now()->utc()->toIso8601ZuluString(), $data);
    }

    /** @throws InvalidMessage JSON illisible ou enveloppe incomplète (envoyé directement en .dlq) */
    public static function parse(string $body): self
    {
        try {
            $json = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidMessage('JSON invalide : '.$e->getMessage());
        }
        if (! is_array($json) || array_is_list($json)) {
            throw new InvalidMessage('Objet JSON attendu');
        }
        $eventId = $json['eventId'] ?? null;
        if (! is_string($eventId) || ! Str::isUuid($eventId)) {
            throw new InvalidMessage('eventId absent ou qui n\'est pas un UUID');
        }
        foreach (['type', 'occurredAt'] as $field) {
            if (! is_string($json[$field] ?? null) || $json[$field] === '') {
                throw new InvalidMessage("$field absent");
            }
        }
        $data = $json['data'] ?? null;
        if (! is_array($data) || (array_is_list($data) && $data !== [])) {
            throw new InvalidMessage('data absent ou qui n\'est pas un objet');
        }

        return new self(strtolower($eventId), $json['type'], $json['occurredAt'], $data);
    }

    /** JSON du message ; montants (Amount) écrits comme nombres décimaux. */
    public function toJson(): string
    {
        return Json::encode([
            'eventId' => $this->eventId,
            'type' => $this->type,
            'occurredAt' => $this->occurredAt,
            'data' => $this->data,
        ]);
    }
}

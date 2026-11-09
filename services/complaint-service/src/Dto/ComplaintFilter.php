<?php

namespace App\Dto;

use App\Enum\ComplaintCategory;
use App\Enum\ComplaintPriority;
use App\Enum\ComplaintStatus;
use App\Exception\ApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** Paramètres de GET /api/complaints : ?page=0&size=20&userId=&status=&category=&priority= */
final readonly class ComplaintFilter
{
    public const MAX_SIZE = 100;

    public function __construct(
        public int $page = 0,
        public int $size = 20,
        public ?string $userId = null,
        public ?ComplaintStatus $status = null,
        public ?ComplaintCategory $category = null,
        public ?ComplaintPriority $priority = null,
    ) {
    }

    /** @throws ApiException 400 avec un détail par paramètre invalide */
    public static function fromRequest(Request $request): self
    {
        $q = $request->query;
        $details = [];

        $page = self::int($q->get('page'), 0);
        if ($page === null || $page < 0) {
            $details[] = ['field' => 'page', 'message' => 'Entier positif ou nul attendu'];
        }
        $size = self::int($q->get('size'), 20);
        if ($size === null || $size < 1 || $size > self::MAX_SIZE) {
            $details[] = ['field' => 'size', 'message' => sprintf('Entier entre 1 et %d attendu', self::MAX_SIZE)];
        }

        $userId = self::str($q->get('userId'));
        if ($userId !== null && !Uuid::isValid($userId)) {
            $details[] = ['field' => 'userId', 'message' => 'Doit être un UUID'];
        }

        $status = self::enum($q->get('status'), ComplaintStatus::class, 'status', $details);
        $category = self::enum($q->get('category'), ComplaintCategory::class, 'category', $details);
        $priority = self::enum($q->get('priority'), ComplaintPriority::class, 'priority', $details);

        if ($details !== []) {
            throw ApiException::badRequest('Paramètres de requête invalides', $details);
        }

        return new self($page, $size, $userId !== null ? strtolower($userId) : null, $status, $category, $priority);
    }

    public function withUserId(string $userId): self
    {
        return new self($this->page, $this->size, $userId, $this->status, $this->category, $this->priority);
    }

    private static function int(mixed $raw, int $default): ?int
    {
        if ($raw === null || $raw === '') {
            return $default;
        }

        return is_string($raw) && preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    private static function str(mixed $raw): ?string
    {
        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T>                              $enum
     * @param list<array{field: string, message: string}> $details
     *
     * @return T|null
     */
    private static function enum(mixed $raw, string $enum, string $field, array &$details): ?\BackedEnum
    {
        $value = self::str($raw);
        if ($value === null) {
            return null;
        }
        $case = $enum::tryFrom(strtoupper($value));
        if ($case === null) {
            $details[] = ['field' => $field, 'message' => 'Valeur invalide, valeurs possibles : ' . implode(', ', $enum::values())];
        }

        return $case;
    }
}

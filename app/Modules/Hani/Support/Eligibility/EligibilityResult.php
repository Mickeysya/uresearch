<?php

namespace App\Modules\Hani\Support\Eligibility;

/**
 * What one eligibility item came to: checked by the system, left to the
 * student's own declaration, or failed.
 */
final class EligibilityResult
{
    public const VERIFIED = 'verified';

    public const NOT_MET = 'not_met';

    public const SELF_DECLARED = 'self_declared';

    /** The system tried to check and could not (a query error, say). Blocks. */
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
    ) {}

    public static function selfDeclared(?string $message = null): self
    {
        return new self(self::SELF_DECLARED, $message);
    }

    public function label(): string
    {
        return match ($this->status) {
            self::VERIFIED => 'Verified by system',
            self::NOT_MET => 'Not met (system check)',
            self::FAILED => 'Could not be checked',
            default => 'Self-declared',
        };
    }

    /** Does this item stop the notice, whatever the student ticked? */
    public function blocks(): bool
    {
        return in_array($this->status, [self::NOT_MET, self::FAILED], true);
    }

    public function toArray(): array
    {
        return ['status' => $this->status, 'message' => $this->message];
    }

    public static function fromArray(?array $data): self
    {
        return new self($data['status'] ?? self::SELF_DECLARED, $data['message'] ?? null);
    }
}

<?php

namespace App\Modules\Norhanis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Memo Request Split Budget: the request to pay the part of a fee above
 * the CGS cap from a research grant.
 *
 * FROM the supervisor, THROUGH the head of the centre that holds the grant,
 * TO the Senior Director CGS. The first two sign here, in that order, before
 * the application is submitted; the Senior Director's answer is their final
 * approval in the workflow itself.
 */
class PublicationMemo extends Model
{
    /** What the CGS fund covers of one publication fee, in ringgit. */
    public const CGS_CAP = 3000.00;

    public const SUPERVISOR = 'supervisor';
    public const HEAD = 'head';

    /** The signers, in signing order: role => the memo line they sign. */
    public const SIGNERS = [
        self::SUPERVISOR => 'From',
        self::HEAD => 'Through',
    ];

    // The signature and decline columns are left out on purpose: only
    // PublicationSignOffs writes them.
    protected $fillable = [
        'publication_detail_id', 'fee_myr', 'cgs_amount', 'grant_amount',
        'grant_name', 'grant_cost_centre', 'centre_name',
        'supervisor_name', 'supervisor_email', 'supervisor_department',
        'head_name', 'head_email',
    ];

    protected $hidden = ['supervisor_signature_png', 'head_signature_png'];

    protected function casts(): array
    {
        return [
            'fee_myr' => 'decimal:2',
            'cgs_amount' => 'decimal:2',
            'grant_amount' => 'decimal:2',
            'supervisor_invited_at' => 'datetime',
            'supervisor_signed_at' => 'datetime',
            'head_invited_at' => 'datetime',
            'head_signed_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function publicationDetail(): BelongsTo
    {
        return $this->belongsTo(PublicationDetail::class);
    }

    /** A fee needs a memo only when it is above the cap. */
    public static function neededFor(float $feeMyr): bool
    {
        return $feeMyr > self::CGS_CAP;
    }

    /**
     * How a fee splits between the CGS fund and the grant.
     *
     * @return array{fee_myr: float, cgs_amount: float, grant_amount: float}
     */
    public static function split(float $feeMyr): array
    {
        return [
            'fee_myr' => round($feeMyr, 2),
            'cgs_amount' => self::CGS_CAP,
            'grant_amount' => round($feeMyr - self::CGS_CAP, 2),
        ];
    }

    /** The share of the fee, as a percentage to one decimal place. */
    public function percentOf(string $column): float
    {
        return (float) $this->fee_myr > 0 ? round((float) $this->{$column} / (float) $this->fee_myr * 100, 1) : 0.0;
    }

    public function hasSigned(string $signer): bool
    {
        return $this->{"{$signer}_signed_at"} !== null;
    }

    public function isSigned(): bool
    {
        return $this->hasSigned(self::SUPERVISOR) && $this->hasSigned(self::HEAD);
    }

    public function isDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    /**
     * Whose turn it is to sign, or null when nobody's: it is fully signed, or
     * it was sent back and is waiting for the student to revise it.
     */
    public function awaiting(): ?string
    {
        if ($this->isDeclined()) {
            return null;
        }

        foreach (array_keys(self::SIGNERS) as $signer) {
            if (! $this->hasSigned($signer)) {
                return $signer;
            }
        }

        return null;
    }

    /** "Supervisor" or "Centre Head", for messages. */
    public static function signerLabel(string $signer): string
    {
        return $signer === self::HEAD ? 'Centre Head' : 'Supervisor';
    }
}

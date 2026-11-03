<?php

namespace App\Modules\Hani\Support\Import;

/**
 * One examiner as read from a sheet, before it is compared with the pool.
 *
 * Both layout readers produce these, and so does HaniDemoSeeder, so the rest
 * of the import -- classification, the preview, applying it -- has one shape
 * to deal with whatever the file looked like. Plain public properties because
 * it is serialised into examiner_import_batches between preview and confirm;
 * toArray()/fromArray() are that round trip.
 */
final class PoolRow
{
    /** @var array<string, mixed> examiners columns, only the ones the sheet gave */
    public array $attributes = [];

    /** @var array<int, string> */
    public array $keywords = [];

    /** @var array<int, array{title: string, year: ?int, coauthors: ?string}> */
    public array $publications = [];

    /** @var array<int, string> students the sheet lists as currently held, first = current */
    public array $students = [];

    /** @var array<int, string> reasons the row cannot be imported */
    public array $errors = [];

    /** @var array<int, string> things worth knowing that do not stop it */
    public array $warnings = [];

    public ?string $departmentAsWritten = null;

    public function __construct(
        /** "Internal Examiner, row 4": where to find it in the file. */
        public string $source,
    ) {}

    public function get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function type(): ?string
    {
        return $this->attributes['type'] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $row = new self($data['source'] ?? '');

        foreach (['attributes', 'keywords', 'publications', 'students', 'errors', 'warnings'] as $key) {
            $row->{$key} = $data[$key] ?? [];
        }

        $row->departmentAsWritten = $data['departmentAsWritten'] ?? null;

        return $row;
    }
}

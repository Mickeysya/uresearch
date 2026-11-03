# Plan: checking the contents of GA Extension documents

**Status:** plan only, nothing implemented. Written 1 November 2026.
**Owner:** Nureen (GA Extension). **Needs team agreement:** one `composer require` (see §6).

## The idea

GA Extension's *System checks* panel already confirms that each required
document **type** is attached. CGS staff still open every file to confirm it
belongs to the right student. The proposal: extract the text of each uploaded
file and report whether the applicant's **name** and **matric number** appear
in it, as *Match / Not found / Could not read*.

Like the GA/GRA appointment check (`Support\AppointmentVerifier`), it is
**informational only**. It never blocks submission, a decision, or a return,
and it says plainly what it cannot see.

---

## 1. What is available today

**Nothing in the project extracts text.** `composer.json` requires only
`barryvdh/laravel-dompdf`, `laravel/framework`, `maatwebsite/excel` and
`spatie/laravel-activitylog`. Dompdf *writes* PDFs and cannot read them, and
there is no PDF parser anywhere in `composer.lock`.

The app container (PHP 8.3.33) has **no `pdftotext` and no `tesseract`
binary**. It does have `zlib`, `iconv`, `mbstring`, `zip`, `dom`, `xml`, `gd`
and `imagick`.

**What students can upload:** `DocumentStore::MULTI_ALLOWED` is
**pdf, jpg, jpeg, png, docx**: up to 5 files per document type, 5 MB each,
20 MB per type. GA Extension has 4 required types, so an application can carry
up to 20 files.

## 2. The options

Facts from Packagist and GitHub, checked 1 November 2026.

| | **smalot/pdfparser** | **spatie/pdf-to-text** | **thiagoalessio/tesseract_ocr** |
|---|---|---|---|
| What it does | Parses PDF text itself | Wraps the `pdftotext` command (poppler-utils) | Wraps the `tesseract` OCR command |
| Licence | LGPL-3.0 | MIT | MIT |
| Latest release | v2.13.1, 13 Oct 2026 | 1.55.0, 24 Nov 2025; **needs PHP ^8.4**. On our PHP 8.3, Composer would install **1.54.1** (13 Jun 2025) | 2.13.0, 5 Oct 2023 |
| Maintenance | Active: 8 releases in the last 14 months; last push 13 Oct 2026; ~2.7k stars; ~200 open issues | Active | Quiet for three years (it is a thin wrapper, so this matters less) |
| Pure PHP? | **Yes.** Needs only `ext-zlib`, `ext-iconv` and a polyfill, all already present | **No.** `pdftotext` must be installed in the Docker image *and* on any production server | **No.** `tesseract` plus language data (`eng`, `msa`) must be installed |
| Text quality | Good on ordinary digital PDFs. Unusual font encodings can come out garbled or letter-spaced ("A h m a d") | Usually the best of the three, and faster | Text from images; accuracy depends on scan quality |
| **Scanned / image-only PDFs** | **Cannot read them**: returns empty text | **Cannot read them**: returns empty text | **Can**, but only after rendering each PDF page to an image (Imagick is present) |
| Encrypted PDFs | Throws ("secured pdf not supported") | Fails unless there is no user password | n/a |

**DOCX needs no package.** A `.docx` is a zip containing `word/document.xml`.
`ext-zip` and `ext-dom` are present, so its text can be read with
`ZipArchive` + `DOMDocument`. (`phpoffice/phpword` 1.4.0, LGPL-3.0-only, also
works, but it is far more than this needs.)

### Recommendation

**Phase 1: `smalot/pdfparser` for PDFs, plus a built-in DOCX reader.** It is
the only option needing no system binary, so no Dockerfile or server change,
and it is actively maintained. Its weaknesses (scanned files, odd font
encodings) are handled honestly by the statuses in §4 rather than hidden.

Put the extractor behind a small interface, as `GeneratesQrCode` and
`AttendanceSource` are, so `pdftotext` or OCR can replace or join it later
without touching the matcher, the storage or the view:

```php
interface ExtractsDocumentText
{
    /** @return array{status: 'ok'|'no_text'|'encrypted'|'unreadable'|'unsupported', text: string, pages: int} */
    public function extract(string $absolutePath, string $mimeType): array;
}
```

**Phase 2 (optional, only if CGS finds that many uploads are scans): OCR.**
This needs `tesseract-ocr`, `tesseract-ocr-eng` and `tesseract-ocr-msa` in the
shared app Docker image and on the production server. That is a bigger team
decision than a composer line.

---

## 3. How it fits the existing structure

### When it runs: a queued job at submission, never in the request

- **Not during `store()`.** Parsing up to 20 files of up to 5 MB in pure PHP
  could add many seconds to a student's submit, and a pathological PDF could
  exhaust memory inside a web request.
- **Not when an approver opens the queue.** The queue renders 20 rows a page,
  each with up to 20 files: that is far too slow, and it would re-parse the
  same unchanged files on every page view.
- **Instead:** `GaExtensionController::store()` dispatches
  `CheckGaExtensionDocuments` with `afterCommit`. It goes on the Redis queue
  the worker already processes, using the same "best effort, never break the
  request" approach as the notifications.
  - The job is per application and processes each file independently, so one
    bad file never stops the others.
  - **Limits:** the first 10 pages of each file only; a per-job `timeout`
    and memory limit; smalot's `setRetainImageContent(false)` and decode
    memory limit, so embedded images are not held in memory.
- **New artisan command:** `ga-extension:check-documents {--application=} {--all-pending}`.
  It backfills applications submitted before the feature is switched on, and
  re-checks after the matching rules improve.

GA Extension documents never change after submission: a return to the
supervisor keeps the same files. So one check per file is enough, with no
invalidation logic. Results record the extractor version, so the command can
re-run only the out-of-date ones.

### Where results live: a Nureen table, nothing added to Core

Rule 2 forbids adding columns to `application_documents`, so add a new
Nureen-owned table that only *references* it:

```
ga_extension_document_checks
  id
  application_document_id   FK -> application_documents.id, unique, cascadeOnDelete
  status                    pending | checked | no_text | encrypted | unreadable | unsupported
  name_result               match | partial | not_found | not_expected | null
  matric_result             match | not_found | not_expected | null
  name_tokens_found         json, e.g. ["aisyah","humaira"], to explain a "partial"
  pages_scanned             unsigned small int
  extractor                 e.g. "smalot/pdfparser 2.13.1"
  checked_at                timestamp
  timestamps
```

It stores **outcomes only, never the extracted text.** One of the four types
is an IC or passport copy; keeping its text in the database would create a
second, unprotected copy of identity data, for no benefit.

A model `GaExtensionDocumentCheck` with a `document()` relation.
`GaExtensionController::queue()` eager-loads the checks for the page's
documents in one query, keyed by `application_document_id`, the same way it
already loads `$details`.

### How it appears: inside the existing System checks panel

`ga_extension/_detail.blade.php` already lists each required type with a
✓/✗ and a file count. Each file would get one indented line under its type:

```
System checks
✓ GA Extension Form (1 file)
    GA-Extension-Form.pdf    Name: Match · Matric: Match
✓ IC or Passport/Visa Copy (1 file)
    IC-front.jpg             Not checked: image file (text cannot be read)
✓ Supervisor's Supporting Letter (2 files)
    Letter.pdf               Name: Partial (aisyah, humaira) · Matric: Not found
    Letter-p2.pdf            Could not read: no text layer (probably a scan)
✓ Acceptance Letter or Published Paper (1 file)
    Paper.docx               Name: Match · Matric: not expected

Content checks are automatic and informational. They cannot read scans or
images, and a name can be spelt differently. Always open the document to
confirm.
```

- **Tones:** *Match* in the success colour. *Partial* and *Not found* in the
  **warning** colour, never danger: these are prompts to look, not failures.
  *Could not read* and *Not checked* in grey; *Pending* in grey with
  "check in progress".
- **No new decision path.** `decide()` and `returnToSupervisor()` never read
  this table. A test asserts approve, reject and return all work with every
  status present, including *Not found*.
- **Behind a config switch.** `nureen.ga_extension.content_check_enabled`
  (default `false`), exactly like `ga_certification.appointment_check_enabled`.
  When off, nothing is dispatched, computed or shown.

### What is expected in each document type

Not every document should contain a matric number: an IC or passport never
does. Put the expectations in config, next to `required_documents`, so the
panel shows *not expected* instead of a misleading *Not found*:

| Document type | Name | Matric |
|---|---|---|
| GA Extension Form | expected | expected |
| IC or Passport/Visa Copy | expected | not expected |
| Supervisor's Supporting Letter | expected | optional (shown, not flagged) |
| Acceptance Letter or Published Paper | expected (as an author) | not expected |

---

## 4. Limitations, and how each is shown

| Limitation | What happens | Shown to the approver as |
|---|---|---|
| **Scanned / image-only PDF** | smalot returns little or no text. Treat under ~20 characters per page as "no text layer". | **Could not read: no text layer (probably a scan).** Grey, never "Not found". |
| **JPG / PNG uploads** | No text to extract without OCR. | **Not checked: image file.** Grey. |
| **DOCX** | Read via `ZipArchive` + `word/document.xml`. Text inside embedded images, and some text boxes, is missed. | Normal statuses. A *Not found* still prompts the approver to look. |
| **Encrypted / password-protected PDF** | smalot throws. | **Could not read: the file is password-protected.** |
| **Corrupt or unusual PDF** (parse error, timeout, odd font encoding giving garbled text) | Caught per file. Garbled text usually yields *Not found*. | **Could not read: the file could not be processed** on an exception. Otherwise a *Not found*, which the footnote covers. |
| **Name spelling variations** | See matching rules below. | **Partial** lists which name parts were found, so the approver sees *why*. |
| **Matric false positives** (an 8-digit number that merely happens to match) | Matched only as a whole word, allowing spaces or hyphens between digit groups. Very unlikely with 8+ digits. | *Match*, like any other. The footnote stands. |
| **Right name, wrong person** (two students share a name) | The matric check is what tells them apart. | Name *Match* + matric *Not found* on the GA Extension Form is the signal to look. |
| **Only the first 10 pages are scanned** | Deliberate, for time and memory. | Shown when hit: "first 10 of 34 pages checked". |
| **Check not run yet** | The job has not finished, or the queue worker is down. | **Pending: check in progress.** It never blocks anything. |

### Name-matching rules

The matching is built for how names actually appear in Malaysian documents:

- **Normalise both sides:** lower-case; Unicode NFKD with accents removed
  (via `iconv` transliteration, since `intl` is not guaranteed); punctuation
  becomes spaces; whitespace collapsed. Also match once with all spaces
  removed, to survive letter-spaced extraction ("A H M A D").
- **Ignore connective particles:** `bin`, `binti`, `bt`, `bte`, `b.`, `a/l`,
  `a/p`, `s/o`, `d/o`, `al`. "Nur Aisyah binti Ahmad" should match
  "NUR AISYAH BT AHMAD".
- **Known equivalents, from config** (so CGS can extend the list):
  `muhammad ~ mohd ~ mohamad ~ mohammad ~ muhamad`, `abdul ~ abd`,
  `nur ~ noor ~ nor`, `siti ~ st`.
- **Result:** *Match* when every remaining name part is found; *Partial*
  when at least half are (listing which); otherwise *Not found*.
  Never fuzzy-match beyond the equivalents list: a confident wrong *Match* is
  worse than an honest *Partial*.

---

## 5. Effort estimate

For one person who knows the module, excluding team discussion:

| Piece | Estimate |
|---|---|
| Composer change and lock file, once agreed (§6) | 0.5 h |
| Migration, model and config (switch, per-type expectations, name equivalents) | 1.5 h |
| `ExtractsDocumentText` + smalot PDF extractor + DOCX reader + scan, encryption and error detection + page/time/memory limits | 5–6 h |
| Name and matric matcher, with normalisation and the Malaysian name rules | 3–4 h |
| Queued job, dispatch from `store()`, backfill/re-check command | 2–3 h |
| System checks panel lines, tones, footnote; hidden when the switch is off | 2 h |
| Tests: Dompdf-generated fixtures with and without the name; an image-only PDF; an encrypted PDF; a DOCX; a JPG; spelling variants; "never blocks a decision"; a queue-page query-count guard | 5–6 h |
| Real-app verification (upload real PDFs, a scan and a photo; run the worker; read the panel) and docs | 2 h |
| **Total** | **≈ 21–25 hours (about 3 working days)** |

OCR (phase 2) would add roughly another 1–1.5 days, plus whatever the team
needs to agree the Docker and server changes.

## 6. Shared changes needing team agreement

1. **`composer.json`: `"smalot/pdfparser": "^2.13"` in `require`,** plus the
   regenerated `composer.lock`. Everyone then runs `sync.sh` (or
   `composer install`) after pulling. Proposed in `SHARED_CHANGES.md` §8.
   - **Licence:** LGPL-3.0. Using the library unmodified via Composer is
     fine for this project; the LGPL obligations bite only if we modify and
     distribute the library itself. Still worth a one-line team (or
     supervisor) OK in case UTP has a licence policy.
2. **Nothing in Core.** The check only *reads* `application_documents` and
   the stored files, and writes to its own Nureen table. No columns added, no
   Core view changed: the panel is Nureen's own `_detail` partial.
3. **No Docker or server change for phase 1.** The extensions it needs are
   already there.
4. **Phase 2 only (OCR):** `tesseract-ocr`, `tesseract-ocr-eng` and
   `tesseract-ocr-msa` in the shared app Dockerfile and on the production
   server, plus either `thiagoalessio/tesseract_ocr` or a direct
   `symfony/process` call (already a Laravel dependency). Not proposed yet.

## 7. Open questions for CGS

- Do they want the student told at upload time ("we couldn't find your matric
  number in GA-Form.pdf")? It would catch wrong uploads earlier, but the check
  is asynchronous, so it would arrive as a notification, not a form error.
  Not in this plan.
- What share of real uploads are scans or phone photos? If it is most of
  them, phase 1 alone will mostly say *Could not read*, and OCR is the real
  feature.
- Should the Certification letter's "Evidence of Work Done" get the same
  check? The design reuses as-is: only the expectations table and the
  dispatch point differ.

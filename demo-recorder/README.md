# Demo recorder

Records the demo video of Nureen's four modules (GA Extension, Supervision,
GA/GRA Certification, Attendance) with Playwright, through the real login
page and the real screens. It adds no code to the app and changes none.

## Run it

From the project root, with the app running (`./vendor/bin/sail up -d`):

```bash
./demo-recorder/record.sh               # all five clips + demo-full.mp4
./demo-recorder/record.sh --clip 03     # just one clip (repeat --clip for more)
```

`record.sh` runs everything in Docker (Node, Playwright's Chromium, ffmpeg
and poppler), so nothing needs installing in WSL. The first run builds the
image `uresearch-demo-recorder:1.65.1` (a few minutes). Inside the container
it runs `npm run record -- <your arguments>`.

With Node 20+ and ffmpeg on the host instead, from `demo-recorder/`:

```bash
npm install
npx playwright install chromium
npm run record                # all clips
npm run record -- --clip 03   # one clip
```

On WSL, Chromium also needs system libraries (`npx playwright install-deps
chromium`, which needs sudo). That is why `record.sh` uses Docker.

Every run first resets the demo data: `nureen:demo-fresh --reset`, then
`nureen:demo-fresh`. Then `scripts/demo-info.php` (read-only, through
tinker) lists what was created, so the clips know each application's id,
student and approvers. An application the recorder submits on camera is
registered as demo data too, so the next reset removes it.

## Settings

Copy `.env.example` to `.env` (record.sh does it the first time). Students
are not listed there: `nureen:demo-fresh` picks them on every run.

| Variable | Default | |
|---|---|---|
| `BASE_URL` | `http://localhost:8000` | the app |
| `MAILPIT_URL` | `http://localhost:8025` | Mailpit |
| `DEMO_PASSWORD` | `password` | every seeded account |
| `ADMIN_EMAIL`, `CGS_EMAIL`, `CHAIR_EMAIL`, `DIRECTOR_EMAIL` | the seeded staff | used if the demo data names nobody |
| `SLOW_MO`, `HOLD`, `TYPE_DELAY` | 250, 1800, 40 ms | pacing |

## Clips

| File | What it shows |
|---|---|
| `01-ga-extension` | A student fills in the GA Extension form, saves a draft, submits; Admin, CGS (System Checks), Supervisor and Senior Director approve; the generated GA Extension Form opens |
| `02-tracking-and-email` | Track My Applications, View details (who acted, who is next), the expected decision date, the status emails in Mailpit |
| `03-attendance` | The At-Risk list (one student by projection, one by the 80% rule), the early-warning email, the student's Attendance Action card |
| `04-supervision` | Supervisor, Chair and CGS endorse a supervision request; the appointment letter (CGS/SVAPPT/...) opens |
| `05-certification` | CGS verifies and the Senior Director approves a certification request; the letter (CGS/GACERT/...) opens, then its public verification page shows "Valid" |

## Output (`videos/`, not committed)

- `01-ga-extension.mp4` ... `05-certification.mp4` (H.264), their `.webm`
  originals, and `demo-full.mp4` (all five, in order; made when all clips are recorded).
- `outcomes/`: each generated PDF, every page of it as PNG (for slides), a
  screenshot of it in the preview overlay, and the verification page.
- `failures/`: a screenshot of any step that failed; the console names the
  clip, step and reason.
- `raw/`: the per-role segments each clip is joined from.

## How it records

Each role has its own browser context, logged in through the real login
page. Every context is recorded and the segments are joined per clip.
Chromium runs in its "new headless" mode, which has the built-in PDF viewer
the app's preview overlay needs. A cursor dot, a click ring and a caption
banner are drawn into the page by `src/overlay.ts`. Waits are for pages and
elements. The only fixed pauses are the reading holds (`HOLD`), plus about
a second for the PDF viewer to paint after it loads.

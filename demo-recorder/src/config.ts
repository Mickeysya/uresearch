import 'dotenv/config';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** demo-recorder/ (this file is demo-recorder/src/config.ts). */
export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const num = (v: string | undefined, d: number) => (v && !Number.isNaN(Number(v)) ? Number(v) : d);

export const config = {
  baseUrl: (process.env.BASE_URL ?? 'http://localhost:8000').replace(/\/$/, ''),
  mailpitUrl: (process.env.MAILPIT_URL ?? 'http://localhost:8025').replace(/\/$/, ''),
  password: process.env.DEMO_PASSWORD ?? 'password',
  staff: {
    admin: process.env.ADMIN_EMAIL ?? 'admin@utp.edu.my',
    cgs: process.env.CGS_EMAIL ?? 'zulkifly.cgs@utp.edu.my',
    chair: process.env.CHAIR_EMAIL ?? 'chair@utp.edu.my',
    director: process.env.DIRECTOR_EMAIL ?? 'director@utp.edu.my',
  },
  slowMo: num(process.env.SLOW_MO, 250),
  hold: num(process.env.HOLD, 1800),
  typeDelay: num(process.env.TYPE_DELAY, 40),
  /** How long a generated document stays on screen at its top and at its bottom. */
  docHold: num(process.env.DOC_HOLD, 3000),
  /** Every other explicit pause in the clips is multiplied by this. */
  pace: 1,
  /** PDF scroll: pixels per wheel step. */
  scrollStep: 90,
  /** Cursor glide (mouse steps) and the pause before each click, in ms. */
  pointerSteps: 18,
  clickPause: 250,
  fast: false,
  viewport: { width: 1920, height: 1080 },
  /** demo-full.mp4 and demo-viva.mp4 live here. */
  videosDir: path.join(ROOT, 'videos'),
  /** Where this run's clips go: videos/ normally, videos/fast/ with --fast. */
  clipsDir: path.join(ROOT, 'videos'),
  rawDir: path.join(ROOT, 'videos', 'raw'),
  outcomesDir: path.join(ROOT, 'videos', 'outcomes'),
  failuresDir: path.join(ROOT, 'videos', 'failures'),
  uploadsDir: path.join(ROOT, 'videos', '.uploads'),
};

/**
 * `--fast`: the short viva cut. Quicker pacing, and its own folder
 * (videos/fast/) so the normal-speed clips behind demo-full.mp4 are never
 * overwritten.
 */
export function useFastMode(): void {
  const fast = path.join(ROOT, 'videos', 'fast');
  Object.assign(config, {
    fast: true,
    slowMo: num(process.env.FAST_SLOW_MO, 120),
    hold: num(process.env.FAST_HOLD, 1000),
    typeDelay: num(process.env.FAST_TYPE_DELAY, 15),
    docHold: num(process.env.FAST_DOC_HOLD, 2000),
    pace: 0.55,
    scrollStep: 160,
    pointerSteps: 8,
    clickPause: 80,
    clipsDir: fast,
    rawDir: path.join(fast, 'raw'),
    outcomesDir: path.join(fast, 'outcomes'),
    failuresDir: path.join(fast, 'failures'),
  });
}

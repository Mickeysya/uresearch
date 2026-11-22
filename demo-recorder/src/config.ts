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
  viewport: { width: 1920, height: 1080 },
  videosDir: path.join(ROOT, 'videos'),
  rawDir: path.join(ROOT, 'videos', 'raw'),
  outcomesDir: path.join(ROOT, 'videos', 'outcomes'),
  failuresDir: path.join(ROOT, 'videos', 'failures'),
  uploadsDir: path.join(ROOT, 'videos', '.uploads'),
};

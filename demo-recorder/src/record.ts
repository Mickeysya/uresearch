import fs from 'node:fs';
import path from 'node:path';
import { config, ROOT, useFastMode } from './config.js';
import { freshDemoData, type DemoInfo } from './demoData.js';
import { Recorder, StepError } from './director.js';
import { buildClip, duration, hasFfmpeg, join } from './video.js';
import * as ga from './clips/01-ga-extension.js';
import * as tracking from './clips/02-tracking-and-email.js';
import * as attendance from './clips/03-attendance.js';
import * as supervision from './clips/04-supervision.js';
import * as certification from './clips/05-certification.js';

type Clip = { id: string; name: string; run: (rec: Recorder, info: DemoInfo) => Promise<void> };

const CLIPS: Clip[] = [
  { id: '01', name: '01-ga-extension', run: ga.run },
  { id: '02', name: '02-tracking-and-email', run: tracking.run },
  { id: '03', name: '03-attendance', run: attendance.run },
  { id: '04', name: '04-supervision', run: supervision.run },
  { id: '05', name: '05-certification', run: certification.run },
];

/** The viva cut: GA Extension, attendance prediction, certification letter + verification. */
const VIVA = ['01-ga-extension', '03-attendance', '05-certification'];

/**
 * `npm run record` (all clips), `npm run record -- --clip 03` (one;
 * repeatable), `--fast` for the short viva pacing (clips go to videos/fast/).
 */
function chosenClips(argv: string[]): Clip[] {
  const wanted: string[] = [];
  argv.forEach((a, i) => {
    if (a === '--clip' && argv[i + 1]) wanted.push(argv[i + 1]);
    else if (a.startsWith('--clip=')) wanted.push(a.slice(7));
  });
  if (wanted.length === 0) return CLIPS;
  const picked = CLIPS.filter((c) => wanted.some((w) => c.id === w.padStart(2, '0') || c.name === w));
  if (picked.length === 0) throw new Error(`Unknown clip "${wanted.join(', ')}". Choose from: ${CLIPS.map((c) => c.id).join(', ')}`);
  return picked;
}

async function reachable(url: string): Promise<boolean> {
  try { const r = await fetch(url, { redirect: 'manual' }); return r.status < 500; } catch { return false; }
}

async function main(): Promise<number> {
  const argv = process.argv.slice(2);
  const clips = chosenClips(argv);
  if (argv.includes('--fast')) useFastMode();
  console.log(config.fast ? 'Pace: --fast (viva cut), clips in videos/fast/' : 'Pace: normal, clips in videos/');

  for (const [what, url] of [['The app', `${config.baseUrl}/login`], ['Mailpit', config.mailpitUrl]] as const) {
    if (!(await reachable(url))) {
      console.error(`${what} is not reachable at ${url}.\nStart it with:  ./vendor/bin/sail up -d   (from the project root), then run this again.`);
      return 2;
    }
  }
  if (!hasFfmpeg()) {
    console.warn('ffmpeg is not installed: videos stay as .webm and demo-full.mp4 is not made.\n'
      + 'Install it with  sudo apt install ffmpeg  (or use ./record.sh, whose Docker image has it).');
  }

  for (const dir of [config.videosDir, config.clipsDir, config.rawDir, config.outcomesDir]) fs.mkdirSync(dir, { recursive: true });
  fs.rmSync(config.failuresDir, { recursive: true, force: true });

  const info = freshDemoData();
  console.log(`Demo data ready: ${info.applications.length} applications, ${info.attendance.length} attendance students.`);

  const browser = await Recorder.launch();
  const failures: string[] = [];

  for (const clip of clips) {
    console.log(`\n▶ ${clip.name}`);
    const rec = new Recorder(browser, clip.name);
    try {
      await clip.run(rec, info);
    } catch (e) {
      const msg = e instanceof StepError ? e.message : `${clip.name} failed: ${e instanceof Error ? e.stack ?? e.message : e}`;
      failures.push(msg);
      console.error(`  ✗ ${msg}`);
    }
    if (rec.segments.length) {
      const out = buildClip(clip.name, rec.segments);
      console.log(`  saved ${path.relative(process.cwd(), out.mp4 ?? out.webm)}`);
    }
  }
  await browser.close();

  // Rebuilt whenever every clip they need exists, so re-recording one clip
  // refreshes them: demo-full.mp4 from the normal-speed clips in videos/,
  // demo-viva.mp4 from the --fast clips in videos/fast/.
  const normalDir = config.videosDir;
  const fastDir = path.join(config.videosDir, 'fast');
  join(CLIPS.map((c) => path.join(normalDir, `${c.name}.mp4`)), 'demo-full.mp4');
  join(VIVA.map((c) => path.join(fastDir, `${c}.mp4`)), 'demo-viva.mp4');

  for (const [title, dir] of [['Videos (normal speed, videos/)', normalDir], ['Videos (--fast, videos/fast/)', fastDir]] as const) {
    const found = CLIPS.map((c) => path.join(dir, `${c.name}.mp4`)).filter((f) => fs.existsSync(f));
    if (!found.length) continue;
    console.log(`\n${title}:`);
    found.forEach((f) => console.log(`  ${path.basename(f)}  ${duration(f)}`));
  }
  for (const name of ['demo-full.mp4', 'demo-viva.mp4']) {
    const f = path.join(config.videosDir, name);
    if (fs.existsSync(f)) console.log(`  ${name}  ${duration(f)}`);
  }

  console.log(`\nOutcome screenshots (${path.relative(ROOT, config.outcomesDir)}/):`);
  for (const f of fs.readdirSync(config.outcomesDir).filter((f) => f.endsWith('.png')).sort()) console.log(`  ${f}`);

  if (failures.length) {
    console.log(`\n${failures.length} clip(s) had a failing step:`);
    failures.forEach((f) => console.log(`  - ${f}`));
    return 1;
  }
  console.log('\nAll clips recorded.');
  return 0;
}

main().then((code) => process.exit(code), (e) => { console.error(e); process.exit(1); });

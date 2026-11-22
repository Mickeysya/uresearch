import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { ROOT } from './config.js';

export interface Person { name: string; email: string; matric: string; programme: string | null; department: string | null }
export interface DemoApplication {
  id: number; reference: string; module: string; status: string; stage: string | null;
  student: Person & { supervisor_email: string | null; supervisor_name: string | null };
  chain: { key: string; label: string; login: string | null }[];
  ga_service_end?: string | null; certificate_reference?: string | null;
}
export interface DemoInfo {
  applications: DemoApplication[];
  attendance: { student: Person; at_risk: boolean; reason: string | null; current: number | null; projected: number | null }[];
  staff: { admin: string; cgs: string | null; director: string };
}

const APP_ROOT = path.resolve(ROOT, '..');
const INFO_FILE = path.join(ROOT, '.demo-fresh.txt');
const sail = path.join(APP_ROOT, 'vendor', 'bin', 'sail');

/**
 * Resets the demo data and reads what `nureen:demo-fresh` created.
 *
 * record.sh does the reset on the host (where ./vendor/bin/sail works) and
 * leaves the output in .demo-fresh.txt, then sets SKIP_RESET=1. Run
 * directly with Node on the host, this does the same itself.
 */
export function freshDemoData(): DemoInfo {
  if (!process.env.SKIP_RESET) {
    console.log('Resetting the demo data: nureen:demo-fresh --reset, then nureen:demo-fresh');
    const run = (args: string[]) => execFileSync(sail, args, { cwd: APP_ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });
    // Applications an earlier recording submitted on camera: demo data too.
    const created = path.join(ROOT, '.demo-created.json');
    if (fs.existsSync(created)) {
      run(['artisan', 'tinker', '--execute', `\\App\\Modules\\Nureen\\Models\\DemoRecord::remember('applications', json_decode('${fs.readFileSync(created, 'utf8').trim()}', true));`]);
      fs.rmSync(created);
    }
    run(['artisan', 'nureen:demo-fresh', '--reset']);
    const table = run(['artisan', 'nureen:demo-fresh']);
    const php = fs.readFileSync(path.join(ROOT, 'scripts', 'demo-info.php'), 'utf8').replace(/^<\?php\s*/, '');
    const info = run(['artisan', 'tinker', '--execute', php]);
    fs.writeFileSync(INFO_FILE, table + '\n' + info);
  }

  const text = fs.readFileSync(INFO_FILE, 'utf8');
  const line = text.split('\n').find((l) => l.startsWith('DEMOINFO:'));
  if (!line) throw new Error(`No demo data found in ${INFO_FILE}. Did nureen:demo-fresh run?`);
  return JSON.parse(line.slice('DEMOINFO:'.length)) as DemoInfo;
}

/** The demo application of a module at a given position (0-3, the order nureen:demo-fresh makes them). */
export function demoApp(info: DemoInfo, module: string, index: number): DemoApplication {
  const app = info.applications.filter((a) => a.module === module)[index];
  if (!app) throw new Error(`nureen:demo-fresh made no ${module} application #${index + 1}`);
  return app;
}

/** Who acts at a stage of an application's chain. */
export function loginAt(app: DemoApplication, stageKey: string): string {
  const login = app.chain.find((c) => c.key === stageKey)?.login;
  if (!login) throw new Error(`no account acts at "${stageKey}" for ${app.reference}`);
  return login;
}

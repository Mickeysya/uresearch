import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { config } from './config.js';

export function hasFfmpeg(): boolean {
  try { execFileSync('ffmpeg', ['-version'], { stdio: 'ignore' }); return true; } catch { return false; }
}

const ffmpeg = (args: string[]) => execFileSync('ffmpeg', ['-y', '-hide_banner', '-loglevel', 'error', ...args], { stdio: 'inherit' });

function concatList(files: string[], listFile: string): string {
  fs.writeFileSync(listFile, files.map((f) => `file '${f.replace(/'/g, "'\\''")}'`).join('\n'));
  return listFile;
}

/**
 * The clip's segments joined into videos/<clip>.webm (all segments share
 * size and codec, so this is a straight copy), then videos/<clip>.mp4.
 */
export function buildClip(clip: string, segments: string[]): { webm: string; mp4: string | null } {
  fs.mkdirSync(config.clipsDir, { recursive: true });
  const webm = path.join(config.clipsDir, `${clip}.webm`);
  if (segments.length === 1) {
    fs.copyFileSync(segments[0], webm);
  } else {
    const list = concatList(segments, path.join(config.rawDir, `${clip}.txt`));
    ffmpeg(['-f', 'concat', '-safe', '0', '-i', list, '-c', 'copy', webm]);
  }
  if (!hasFfmpeg()) return { webm, mp4: null };
  const mp4 = path.join(config.clipsDir, `${clip}.mp4`);
  ffmpeg(['-i', webm, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p', '-r', '25', '-movflags', '+faststart', mp4]);
  return { webm, mp4 };
}

/** The given clip files (in that order) joined into videos/<name>. */
export function join(mp4s: string[], name: string): string | null {
  if (mp4s.length === 0 || !mp4s.every((f) => fs.existsSync(f)) || !hasFfmpeg()) return null;
  const out = path.join(config.videosDir, name);
  ffmpeg(['-f', 'concat', '-safe', '0', '-i', concatList(mp4s, path.join(config.rawDir, `${name}.txt`)), '-c', 'copy', '-movflags', '+faststart', out]);
  return out;
}

export function duration(file: string): string {
  try {
    const s = Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', file], { encoding: 'utf8' }).trim());
    return `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}`;
  } catch { return '?'; }
}

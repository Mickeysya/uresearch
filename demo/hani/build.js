// Builds the final video from the recorded segments (out/seg<N>.ffconcat + .json).
//   node build.js            title card + every recorded segment + closing card
// Writes out/UResearch-Hani-demo-captioned.mp4, .srt, .ass, captions.json and timings.txt.
// Captions are PNG overlays rendered in Chrome (the Homebrew ffmpeg has no
// libass), styled as the spec: sans-serif ~34px, white on a dark box, bottom centre.
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const puppeteer = require('puppeteer-core');

const OUT = path.join(__dirname, 'out');
const TMP = path.join(OUT, 'build');
const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const CARD_SECONDS = 4;
const SEGMENT_NAMES = { 1: 'Viva notice', 2: 'Softbound checks', 3: 'Examiner nomination', 4: 'DAC + conflict detection', 5: 'Viva scheduling', 6: 'Re-viva monitoring' };
const X264 = ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', '30', '-an'];

const ff = args => execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', ...args], { stdio: 'inherit' });
const probe = file => parseFloat(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', file]).toString());
const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');

const CHIPS = ['Viva Voce Assessment', 'Examiner Nomination', 'Re-viva Monitoring'];
const card = ({ small, kicker, title, subtitle }) => `<!doctype html><html><head><meta charset="utf-8"><style>
  html,body{margin:0;width:1920px;height:1080px;background:#12324F;color:#fff;font-family:-apple-system,"Helvetica Neue",Arial,sans-serif}
  .wrap{position:absolute;inset:0;display:flex;flex-direction:column;justify-content:center;padding:0 180px}
  .small{font-size:28px;opacity:.85;letter-spacing:.2px;margin-bottom:56px}
  .kicker{font-size:26px;text-transform:uppercase;letter-spacing:4px;color:#F5B942;font-weight:600;margin-bottom:20px}
  h1{font-size:128px;margin:0 0 24px;font-weight:800;letter-spacing:-2px;line-height:1.02}
  .sub{font-size:40px;opacity:.92;max-width:1400px;line-height:1.3;margin-bottom:56px}
  .chips{display:flex;gap:18px;flex-wrap:wrap}
  .chip{font-size:28px;padding:14px 30px;border-radius:999px;border:2px solid rgba(255,255,255,.55);background:rgba(255,255,255,.08)}
  .bar{position:absolute;left:180px;bottom:120px;width:120px;height:6px;background:#F5B942;border-radius:3px}
</style></head><body><div class="wrap">
  ${small ? `<div class="small">${esc(small)}</div>` : ''}
  ${kicker ? `<div class="kicker">${esc(kicker)}</div>` : ''}
  <h1>${esc(title)}</h1>
  ${subtitle ? `<div class="sub">${esc(subtitle)}</div>` : ''}
  <div class="chips">${CHIPS.map(c => `<span class="chip">${esc(c)}</span>`).join('')}</div>
</div><div class="bar"></div></body></html>`;

const captionHtml = (text, pos) => `<!doctype html><html><head><meta charset="utf-8"><style>
  html,body{margin:0;width:1920px;height:1080px;background:transparent}
  .c{position:absolute;left:50%;${pos === 'top' ? 'top:84px' : 'bottom:56px'};transform:translateX(-50%);max-width:1440px;width:max-content;box-sizing:border-box;
     padding:16px 32px;border-radius:12px;background:rgba(10,20,35,.80);color:#fff;text-align:center;
     font:500 34px/1.32 -apple-system,"Helvetica Neue",Arial,sans-serif;letter-spacing:.1px}
</style></head><body><div class="c">${esc(text)}</div></body></html>`;

const srtTime = t => { const ms = Math.round(t * 1000); const h = Math.floor(ms / 3600000), m = Math.floor(ms / 60000) % 60, s = Math.floor(ms / 1000) % 60; return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')},${String(ms % 1000).padStart(3, '0')}`; };
const assTime = t => { const cs = Math.round(t * 100); const h = Math.floor(cs / 360000), m = Math.floor(cs / 6000) % 60, s = Math.floor(cs / 100) % 60; return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}.${String(cs % 100).padStart(2, '0')}`; };
const mmss = t => `${Math.floor(t / 60)}:${String(Math.floor(t % 60)).padStart(2, '0')}`;

(async () => {
  fs.mkdirSync(TMP, { recursive: true });
  const segs = Object.keys(SEGMENT_NAMES).map(Number).filter(n => fs.existsSync(path.join(OUT, `seg${n}.ffconcat`)));
  if (!segs.length) throw new Error('no recorded segments in out/');

  // 1. Cards and caption images.
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, defaultViewport: { width: 1920, height: 1080, deviceScaleFactor: 1 } });
  const page = await browser.newPage();
  const render = async (html, file, transparent = false) => { await page.setContent(html, { waitUntil: 'load' }); await page.screenshot({ path: file, omitBackground: transparent }); };
  await render(card({ small: 'Nur Hani Sofia Binti Mohd Azam · 22001418', kicker: 'Final Year Project 2 · Universiti Teknologi PETRONAS', title: 'UResearch 2.0', subtitle: 'Rule-based viva, examiner nomination and re-viva monitoring for CGS' }), path.join(TMP, 'title.png'));
  await render(card({ title: 'Three modules, one examination workflow' }), path.join(TMP, 'closing.png'));

  // 2. Clips: cards (with a short fade) and segments (variable frame times -> 30 fps).
  const clips = [];
  const cardClip = (png, mp4) => ff(['-loop', '1', '-t', String(CARD_SECONDS), '-i', png, '-vf', `fade=in:st=0:d=0.4,fade=out:st=${CARD_SECONDS - 0.4}:d=0.4`, ...X264, mp4]);
  cardClip(path.join(TMP, 'title.png'), path.join(TMP, 'title.mp4'));
  clips.push({ name: 'Title card', file: path.join(TMP, 'title.mp4'), captions: [] });
  for (const n of segs) {
    const mp4 = path.join(TMP, `seg${n}.mp4`);
    ff(['-f', 'concat', '-safe', '0', '-i', path.join(OUT, `seg${n}.ffconcat`), '-vf', 'fps=30,scale=1920:1080:flags=lanczos', ...X264, mp4]);
    const meta = JSON.parse(fs.readFileSync(path.join(OUT, `seg${n}.json`), 'utf8'));
    clips.push({ name: `${n}. ${SEGMENT_NAMES[n]}`, file: mp4, captions: meta.captions });
  }
  cardClip(path.join(TMP, 'closing.png'), path.join(TMP, 'closing.mp4'));
  clips.push({ name: 'Closing card', file: path.join(TMP, 'closing.mp4'), captions: [] });

  // 3. Join, and build the caption timeline in final-video time.
  const list = path.join(TMP, 'clips.txt');
  fs.writeFileSync(list, clips.map(c => `file '${c.file}'`).join('\n') + '\n');
  const joined = path.join(TMP, 'joined.mp4');
  ff(['-f', 'concat', '-safe', '0', '-i', list, '-c', 'copy', joined]);
  let t = 0;
  const timeline = [], timings = [];
  for (const c of clips) {
    const d = probe(c.file);
    timings.push({ name: c.name, start: t, end: t + d });
    for (const cap of c.captions) timeline.push({ start: +(t + cap.start).toFixed(2), end: +(t + Math.min(cap.end, d)).toFixed(2), text: cap.text, segment: c.name, ...(cap.pos ? { pos: cap.pos } : {}) });
    t += d;
  }

  // 4. Caption overlays.
  const pngs = [];
  for (let i = 0; i < timeline.length; i++) {
    const f = path.join(TMP, `cap${String(i).padStart(2, '0')}.png`);
    await render(captionHtml(timeline[i].text, timeline[i].pos), f, true);
    pngs.push(f);
  }
  await browser.close();
  const final = path.join(OUT, 'UResearch-Hani-demo-captioned.mp4');
  const inputs = ['-i', joined];
  // A still image is one frame at t=0; loop it so overlay's enable window can see it.
  pngs.forEach(p => inputs.push('-loop', '1', '-framerate', '30', '-t', String(t.toFixed(2)), '-i', p));
  let chain = '', last = '0:v';
  timeline.forEach((c, i) => {
    const outL = i === timeline.length - 1 ? 'vout' : `v${i}`;
    chain += `[${last}][${i + 1}:v]overlay=0:0:enable='between(t,${c.start},${c.end})'[${outL}];`;
    last = outL;
  });
  if (timeline.length) ff([...inputs, '-filter_complex', chain.replace(/;$/, ''), '-map', '[vout]', '-t', probe(joined).toFixed(3), ...X264, '-movflags', '+faststart', final]);
  else fs.copyFileSync(joined, final);

  // 5. Side files.
  fs.writeFileSync(path.join(OUT, 'UResearch-Hani-demo-captioned.srt'), timeline.map((c, i) => `${i + 1}\n${srtTime(c.start)} --> ${srtTime(c.end)}\n${c.text}\n`).join('\n'));
  fs.writeFileSync(path.join(OUT, 'UResearch-Hani-demo-captioned.ass'), `[Script Info]\nScriptType: v4.00+\nPlayResX: 1920\nPlayResY: 1080\n\n[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, BackColour, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV\nStyle: Default,Helvetica,34,&H00FFFFFF,&H33231409,3,12,0,2,240,240,56\n\n[Events]\nFormat: Layer, Start, End, Style, Text\n` + timeline.map(c => `Dialogue: 0,${assTime(c.start)},${assTime(c.end)},Default,${c.text}`).join('\n') + '\n');
  fs.writeFileSync(path.join(__dirname, 'captions.json'), JSON.stringify(timeline, null, 2) + '\n');
  const total = probe(final);
  const report = timings.map(s => `${mmss(s.start)}–${mmss(s.end)}  (${(s.end - s.start).toFixed(1)} s)  ${s.name}`).join('\n') + `\nTotal: ${mmss(total)} (${total.toFixed(1)} s)\n`;
  fs.writeFileSync(path.join(OUT, 'timings.txt'), report);
  console.log(report);
})().catch(e => { console.error(e); process.exit(1); });

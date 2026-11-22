import fs from 'node:fs';
import path from 'node:path';
import type { Browser } from 'playwright';
import { config } from './config.js';
import type { Person } from './demoData.js';

export type UploadKind = 'supporting' | 'admission' | 'identity' | 'acceptance';

const TITLES: Record<UploadKind, [string, string]> = {
  supporting: ["Supervisor's Supporting Letter", 'Supporting-Letter'],
  admission: ['Admission Letter & Offer Letter', 'Admission-Offer-Letter'],
  identity: ['IC or Passport/Visa Copy', 'IC-Copy'],
  acceptance: ['Proof of Journal Article Acceptance', 'Journal-Acceptance-Letter'],
};

/**
 * One-page, readable supporting documents for the student who submits on
 * camera, made with Chromium's own page.pdf() -- named the way a student
 * would name them ("Supporting-Letter-Ng-Xin-Yi.pdf"). Each says in its
 * footer that it is a sample.
 */
export async function makeUploads(browser: Browser, student: Person, supervisorName: string, kinds: UploadKind[]): Promise<Record<UploadKind, string>> {
  fs.mkdirSync(config.uploadsDir, { recursive: true });
  const page = await browser.newPage();
  const out = {} as Record<UploadKind, string>;
  const who = student.name.split(/\s+/).map((p) => p[0].toUpperCase() + p.slice(1)).join('-');
  const today = new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });

  for (const kind of kinds) {
    const [title, prefix] = TITLES[kind];
    const file = path.join(config.uploadsDir, `${prefix}-${who}.pdf`);
    await page.setContent(html(kind, title, student, supervisorName, today));
    await page.pdf({ path: file, format: 'A4', printBackground: true, margin: { top: '20mm', bottom: '20mm', left: '22mm', right: '22mm' } });
    out[kind] = file;
  }
  await page.close();
  return out;
}

const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]!));

function html(kind: UploadKind, title: string, s: Person, sv: string, today: string): string {
  const name = esc(s.name); const matric = esc(s.matric); const dept = esc(s.department ?? 'Computing');
  const prog = esc(s.programme ?? 'Postgraduate'); const svName = esc(sv);
  const body: Record<UploadKind, string> = {
    supporting: `<p>${today}</p><p>The Senior Director<br>Centre for Graduate Studies<br>Universiti Teknologi PETRONAS</p>
      <h1>Support for Graduate Assistantship Extension: ${name} (${matric})</h1>
      <p>I am the Main Supervisor of ${name} (Matric No. ${matric}), a ${prog} candidate in the Department of ${dept}.
      I support the request to extend the Graduate Assistantship for a further six months.</p>
      <p>The student has met the milestones agreed for this stage of candidature and continues to support the department's teaching well.</p>
      <p style="margin-top:48px">Yours faithfully,</p><p style="margin-top:42px;border-top:1px solid #444;width:45%;padding-top:4px">${svName}<br>Main Supervisor, Department of ${dept}</p>`,
    admission: `<h1>Offer of Admission</h1><p>Ref: UTP/CGS/ADM/${matric}</p><p>${name}<br>Matric No. ${matric}</p>
      <p>We are pleased to offer you a place in the following programme:</p>
      <table><tr><td>Programme</td><td>${prog}</td></tr><tr><td>Department</td><td>${dept}</td></tr>
      <tr><td>Mode of study</td><td>Full-time, by research</td></tr><tr><td>Main Supervisor</td><td>${svName}</td></tr></table>
      <p>ACCEPTED by the candidate.</p><p style="margin-top:42px;border-top:1px solid #444;width:45%;padding-top:4px">Senior Director<br>Centre for Graduate Studies</p>`,
    identity: `<h1>Copy of MyKad (Identity Card)</h1><p>Submitted by ${name} (${matric}).</p>
      <div style="border:2px solid #555;border-radius:12px;padding:18px;width:70%;background:#f4f7fb">
      <b style="color:#0b3d6e">MALAYSIA · KAD PENGENALAN</b><table><tr><td>Nama</td><td>${name.toUpperCase()}</td></tr>
      <tr><td>No. KP</td><td>010315-08-${matric.slice(-4)}</td></tr><tr><td>Warganegara</td><td>WARGANEGARA</td></tr></table></div>
      <p>I certify that this is a true copy of the original.</p><p style="margin-top:42px;border-top:1px solid #444;width:45%;padding-top:4px">${name}</p>`,
    acceptance: `<h1>Notification of Acceptance</h1><p>Dear ${name},</p>
      <p>Your manuscript <b>"Federated Learning for Privacy-Preserving Medical Image Classification"</b>, co-authored with ${svName},
      has been accepted for publication in <i>IEEE Access</i> (Web of Science, Q2).</p><p style="margin-top:42px">Editor-in-Chief</p>`,
  };

  return `<!doctype html><html><head><meta charset="utf-8"><style>
    body{font-family:"DejaVu Sans",sans-serif;font-size:11pt;color:#222;line-height:1.5}
    .head{border-bottom:2px solid #0b3d6e;padding-bottom:8px;margin-bottom:18px;color:#0b3d6e;font-weight:bold;font-size:13pt}
    h1{font-size:14pt;color:#0b3d6e} table{border-collapse:collapse;margin:8px 0} td{padding:4px 14px 4px 0;vertical-align:top}
    td:first-child{color:#555} .foot{position:fixed;bottom:0;left:0;right:0;font-size:8pt;color:#888;text-align:center}
  </style></head><body><div class="head">Universiti Teknologi PETRONAS<br><span style="font-weight:normal;font-size:9pt;color:#555">${esc(title)}</span></div>
  ${body[kind]}<div class="foot">Sample document for the UResearch 2.0 demonstration, ${name} (${matric}). Not an official record.</div></body></html>`;
}

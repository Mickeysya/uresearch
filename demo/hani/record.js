// Records the Hani demo video, one segment per clip.
//   node record.js                 every segment, in order
//   node record.js 3 4             only these (reseed first; see build.sh)
//   node record.js --dry 1         walk a segment fast, no capture, screenshots in out/shots
// Output per segment: out/seg<N>.ffconcat (frames) and out/seg<N>.json (captions, marks).
// All data is fictional: HaniDemoSeeder + ExaminerNominationUatSeeder.
const fs = require('fs');
const path = require('path');
const { Demo, BASE, sleep } = require('./lib');

const OUT = path.join(__dirname, 'out');
const ASSETS = path.join(__dirname, 'assets');

// Dates the app computes relative to today.
const iso = d => d.toISOString().slice(0, 10);
const addMonths = (n, extraDays = 0) => { const d = new Date(); d.setMonth(d.getMonth() + n); d.setDate(d.getDate() + extraDays); return d; };
const nextWeekday = (dow, minDaysAhead) => { const d = new Date(); d.setDate(d.getDate() + minDaysAhead); while (d.getDay() !== dow) d.setDate(d.getDate() + 1); return d; };

const S01 = {
  title: 'Deep Learning-Based Anomaly Detection for Offshore Pipeline Sensor Data',
  abstract: 'Offshore pipelines are monitored by hundreds of pressure, temperature and flow sensors, and the faults that matter most are rare, '
    + 'so they are easy to miss among normal variation. This study develops an LSTM autoencoder model to detect anomalies in multivariate '
    + 'time series collected from offshore pipeline sensors. The model learns the normal behaviour of the sensor network and flags readings '
    + 'whose reconstruction error is unusually high. It is compared with isolation forest and one-class SVM baselines on field data from a '
    + 'Malaysian operator, using precision, recall and detection delay. The study also examines how the window length and the alarm threshold '
    + 'affect false alarms, and proposes a simple procedure for operators to tune them.',
  keywords: 'deep learning; anomaly detection; time series; sensor data; pipelines',
};

async function ensureAssets(demo) {
  fs.mkdirSync(ASSETS, { recursive: true });
  const docs = {
    'acceptance-letter.pdf': ['Journal of Pipeline Engineering (fictional)', 'Acceptance letter', 'Manuscript JPE-2026-0412, "LSTM autoencoders for pipeline sensor anomalies", by A. H. Roslan and N. Aziz, has been accepted for publication. Fictional document for a software demonstration.'],
    'rm-result.pdf': ['Universiti Teknologi PETRONAS (fictional record)', 'Research Methodology result', 'Aiman Hakim bin Roslan, 24000101: Research Methodology, Grade A-. Fictional document for a software demonstration.'],
  };
  for (const [name, [org, title, body]] of Object.entries(docs)) {
    const file = path.join(ASSETS, name);
    if (fs.existsSync(file)) continue;
    const p = await demo.browser.newPage();
    await p.setContent(`<body style="font-family:Georgia,serif;padding:60px"><p style="color:#555">${org}</p><h1>${title}</h1><p>${body}</p></body>`);
    await p.pdf({ path: file, format: 'A4' });
    await p.close();
  }
  // A neutral placeholder signature (a scribble, no name) for the Dean's
  // re-viva letters: a fresh database has none, and approval requires one.
  const sig = path.join(ASSETS, 'demo-signature.png');
  if (!fs.existsSync(sig)) {
    const p = await demo.browser.newPage();
    await p.setViewport({ width: 480, height: 160 });
    await p.setContent('<body style="margin:0;background:#fff"><svg width="480" height="160"><path d="M30 110 C 70 20, 110 140, 150 70 S 230 30, 260 100 S 340 130, 370 60 S 430 80, 450 95" fill="none" stroke="#1d2b5c" stroke-width="5" stroke-linecap="round"/><text x="30" y="150" font-family="Arial" font-size="14" fill="#999">demo signature</text></svg></body>');
    await p.screenshot({ path: sig });
    await p.close();
  }
}

// Off camera: give the logged-in Dean a signature if they have none yet.
async function ensureDeanSignature(d) {
  const b64 = fs.readFileSync(path.join(ASSETS, 'demo-signature.png')).toString('base64');
  const status = await d.page.evaluate(async b64 => {
    const has = await fetch('/re-viva/signature/image', { credentials: 'same-origin' });
    if (has.ok) return 'present';
    const bytes = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
    const fd = new FormData();
    fd.append('signature', new Blob([bytes], { type: 'image/png' }), 'demo-signature.png');
    const r = await fetch('/re-viva/signature', { method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
    return r.ok ? 'uploaded' : 'failed ' + r.status;
  }, b64);
  if (status.startsWith('failed')) throw new Error('Dean signature upload ' + status);
}

// ---------------------------------------------------------------------------
const SEGMENTS = {
  // 1. Viva notice: student files, supervisor endorses, AE verifies.
  1: async d => {
    const setDate = v => d.page.evaluate(v => { const e = document.querySelector('#expected_softbound_date'); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, v);
    await d.as('aiman.roslan@example.com', '/viva/eligibility');
    await d.caption('The student completes the Handbook eligibility checklist.', async () => {
      const boxes = await d.page.$$('input.req-box:not([disabled])');
      for (const b of boxes) {
        const label = await b.evaluateHandle(e => e.closest('.req').querySelector('label'));
        await d.click(label.asElement());
        await d.wait(120);
      }
      await d.click(['button', 'Continue']);
      await d.wait(400);
    });
    await d.caption('The title, abstract and keywords also feed the examiner suggestions later.', async () => {
      await d.type('#thesis_title', S01.title, { delay: 8 });
      await d.paste('#abstract', S01.abstract);
      await d.type('#keywords', S01.keywords, { delay: 8 });
      await d.click(['button', 'Continue']);
      await d.wait(400);
    });
    await d.caption('The softbound date must be at least 3 months away, or the notice is refused.', async () => {
      await d.click('#expected_softbound_date');
      await setDate(iso(addMonths(1)));
      // Chrome's own validation bubble ("Value must be ... or later") appears here.
      await d.click(['button', 'Continue']);
      await d.wait(200);
      await d.highlight('#expected_softbound_date', { hold: 2600, pad: 10 });
    });
    await d.caption('Once the date is fixed, the notice is filed…', async () => {
      await d.click('#expected_softbound_date');
      await setDate(iso(addMonths(3, 14)));
      await d.moveTo('#publication_papers');
      await (await d.page.$('#publication_papers')).uploadFile(path.join(ASSETS, 'acceptance-letter.pdf'));
      await d.moveTo('#research_methodology_result');
      await (await d.page.$('#research_methodology_result')).uploadFile(path.join(ASSETS, 'rm-result.pdf'));
      await d.click(['button', 'Continue']);
      await d.wait(600);
      await d.clickNav(['button', 'Submit Notice of Thesis Submission']);
    });
    await d.caption('…and the progress tracker starts.', async () => {
      await d.highlight(await d.find('h3, h2', 'Your Viva Progress'), { hold: 2400, union: [await d.find('tr', 'VIVA VOCE')] });
    });

    await d.as('nurul.aziz@example.com', '/viva/queue');
    await d.caption('The supervisor endorses it first, or returns it with a comment.', async () => {
      const legend = await d.find('fieldset', 'Endorsement');
      await d.highlight(legend, { hold: 2200, union: [['label', 'Comment']] });
      await d.click(['label', 'Endorse']);
      await d.clickNav(['button', 'Submit endorsement']);
    });

    await d.as('ae@utp.edu.my', '/viva/queue');
    await d.caption('The AE verifies every item.', async () => {
      const radios = await d.page.$$('table.notice-eligibility input[type=radio][value=verified]');
      // The AE column stays highlighted while each item is marked.
      await d.highlight(radios[0], { hold: 0, keep: true, union: radios.slice(1) });
      for (const r of radios) {
        const label = await r.evaluateHandle(e => e.closest('label'));
        await d.click(label.asElement());
        await d.wait(80);
      }
      await d.wait(1200);
      await d.hideHighlights();
    });
    await d.caption('The notice only passes when all are met.', async () => {
      await d.highlight(['p.fstep-hint', 'Verified needs every eligibility item'], { hold: 2000 });
      await d.click(['fieldset label', 'Verified']);
      await d.clickNav(['button', 'Submit verification']);
      await d.wait(700);
    });
  },

  // 2. Softbound checks: Nur Farah's Form #019, read by the system.
  2: async d => {
    await d.as('ae@utp.edu.my', '/viva/softbound/queue');
    await d.caption('For another student, Nur Farah, the system reads the Turnitin report itself: similarity, single sources above 5%, title and word count.', async () => {
      await d.click(['summary.queue-row-summary .queue-row-summary-text', 'Form #019']);
      await d.wait(700);
      const row = label => d.find('.system-checks tr', label);
      await d.highlight(await row('Similarity read from the report'), { hold: 2200, union: [await row('Largest single source')] });
      await d.highlight(await row('Report title against the notice'), { hold: 2000, union: [await row('Word count')] });
    }, { min: 5 });
    await d.caption("Anything that doesn't match is a warning for the AE, never a silent pass.", async () => {
      await d.highlight(await d.find('.system-checks tr', 'Similarity read from the report'), { hold: 2600, union: [await d.find('.system-checks tr', 'Report age')] });
    }, { min: 4 });
    await d.caption('The AE approves, and the softbound thesis is verified.', async () => {
      await d.clickNav(['details.queue-row[open] button', 'Approve']);
      await d.highlight(['div', 'approved'], { hold: 2000 }).catch(() => d.wait(1500));
    }, { min: 4 });
  },

  // 3. Examiner nomination: the supervisor builds Nur Farah's panel.
  3: async d => {
    await d.as('supervisor@utp.edu.my', '/examiner-nomination/new');
    // Off camera: pick the candidate and title, so the scene opens on the panel.
    await d.cut(async () => {
      const id = await d.page.evaluate(() => [...document.querySelectorAll('#student_id option')].find(o => o.textContent.includes('Nur Farah')).value);
      await d.page.select('#student_id', id);
      await d.page.evaluate(t => { const e = document.querySelector('#thesis_title'); e.value = t; e.dispatchEvent(new Event('input', { bubbles: true })); }, 'Privacy-Preserving Federated Deep Learning for Medical Image Classification Across Hospitals');
      await d.click(['button', 'Continue']);
      await sleep(1500);
      await d.page.evaluate(() => window.scrollTo(0, 0));
    });
    await d.caption('Only Available examiners can be picked. Anyone in their 90-day gap is greyed out.', async () => {
      // A native dropdown is not painted in a screencast, so show the same
      // <select> as an open list for a moment: its real, disabled options.
      await d.click('#internal_main_id');
      await d.page.evaluate(() => {
        const s = document.querySelector('#internal_main_id');
        s.size = 9; s.style.height = 'auto';
        const o = [...s.options].find(o => o.textContent.includes('Rahmat'));
        s.scrollTop = Math.max(0, o.offsetTop - 80);
      });
      await d.wait(300);
      await d.highlight('#internal_main_id', { hold: 2800 });
      await d.page.evaluate(() => { const s = document.querySelector('#internal_main_id'); s.size = 0; s.style.height = ''; });
    }, { min: 5 });
    const sugg = (side, name) => d.find(`[data-suggestions="${side}"] .suggest-item`, name);
    await d.caption('Suggestions are ranked by TF-IDF, and each one shows why it matched.', async () => {
      const top = await sugg('internal', 'Mohd Haikal');
      await d.highlight(top, { hold: 2600 });
      await d.click(await top.$('xpath/.//button[contains(., "Use as main")]'));
      await d.wait(600);
    }, { min: 4 });
    await d.caption('Conflicts of interest are flagged with the evidence: here, a paper co-written with the supervisor.', async () => {
      const tan = await sugg('internal', 'Tan Mei Xuan');
      const summary = await tan.$('summary');
      if (summary) { await d.click(summary); await d.wait(500); }
      await d.highlight(await tan.$('.suggest-flag') || tan, { hold: 2600 });
      const backup = await (await sugg('internal', 'Hafizah')).$('xpath/.//button[contains(., "Use as backup")]');
      await d.click(backup);
      await d.wait(500);
      await d.click(['button', 'Continue']);
      await d.wait(1500);
    }, { min: 5 });
    await d.caption("An examiner already on another department's list is flagged too.", async () => {
      const gan = await sugg('external', 'Gan Wei Jie');
      await d.highlight(await gan.$('.suggest-flag') || gan, { hold: 2500 });
      const ong = await sugg('external', 'Ong Siew Ling');
      await d.click(await ong.$('xpath/.//button[contains(., "Use as main")]'));
      await d.wait(300);
      await d.click(await (await sugg('external', 'Gan Wei Jie')).$('xpath/.//button[contains(., "Use as backup")]'));
      await d.wait(400);
    }, { min: 4 });
    await d.caption('The supervisor submits the four-seat panel.', async () => {
      await d.click(['button', 'Continue']);
      await d.wait(900);
      await d.click(['button', 'Continue']);
      await d.wait(1000);
      await d.clickNav(['button', 'Submit Nomination']);
      await d.wait(800);
    }, { min: 3.5 });

    await d.as('ae@utp.edu.my', '/examiners');
    await d.caption('Pool states are worked out from dates, so nobody updates a spreadsheet any more.', async () => {
      const cards = await d.page.$$('.sdash-stat');
      await d.highlight(cards[0], { hold: 2800, union: cards.slice(1, 3) });
    }, { min: 4 });
  },

  // 4. DAC compilation and faculty-level conflicts.
  4: async d => {
    await d.as('ae@utp.edu.my', '/examiner-nomination/dac');
    await d.caption('The AE compiles nominations for the DAC. Nothing reaches CGS without a DAC record.', async () => {
      await d.highlight(await d.findAny(['tr', 'Nur Farah'], ['li', 'Nur Farah'], ['div', 'Nur Farah']), { hold: 2800 });
      await d.moveTo(['button', 'Download PDF']);
      await d.wait(500);
    }, { min: 5 });
    await d.cut(async () => { await d.openPdf('/examiner-nomination/dac/export?format=pdf', 'dac-compiled-list.pdf'); });
    await d.caption('The compiled list is generated as a PDF for the DAC meeting.', async () => { await d.wait(3500); }, { min: 4.5 });

    await d.as('waheda.cgs@utp.edu.my', '/examiner-nomination/conflicts');
    await d.caption('At faculty level, the same examiner nominated by two departments is flagged, not rejected.', async () => {
      await d.highlight(['p', 'named by more than one department'], { hold: 2000 });
      await d.highlight(await d.find('tr', 'Firdaus'), { hold: 2600 });
    }, { min: 5 });
    await d.caption("Near-identical names are caught too, so one person isn't booked twice.", async () => {
      // The heading and the pair listed under it (not the whole card).
      const h3 = await d.find('h3', 'Possibly the same person');
      const pair = (await h3.evaluateHandle(e => e.nextElementSibling)).asElement();
      await d.highlight(h3, { hold: 2600, union: pair ? [pair] : [] });
    }, { min: 4 });
  },

  // 5. Viva scheduling.
  5: async d => {
    await d.as('ae@utp.edu.my', '/viva/scheduling');
    await d.caption('A viva can only be set once the softbound is verified and the nomination is released.', async () => {
      await d.highlight(await d.find('tr', 'Nur Farah'), { hold: 2800 });
    }, { min: 4.5 });
    await d.as('ae.mechanical@utp.edu.my', '/viva/scheduling');
    await d.caption("Lee Jia Hui is ready. Her examiners' report categories differ, so the AE sees it before setting the date.", async () => {
      await d.highlight(await d.find('tr', 'Lee Jia Hui'), { hold: 2800 });
      await d.clickNav(await (await d.find('tr', 'Lee Jia Hui')).$('xpath/.//a[contains(., "Set Date")]'));
    }, { min: 5 });
    // Off camera: the form is filled (a Saturday, 10:00, venue, chairman).
    await d.cut(async () => {
      const sat = iso(nextWeekday(6, 10));
      await d.page.evaluate(sat => {
        const set = (sel, v) => { const e = document.querySelector(sel); if (!e.value || sel === '#viva_date' || sel === '#viva_time') { e.value = v; e.dispatchEvent(new Event('change', { bubbles: true })); } };
        set('#viva_date', sat); set('#viva_time', '10:00');
        set('#venue', 'Seminar Room 1, Chancellor Complex'); set('#chair_name', 'Prof Dr Mahmud bin Idris'); set('#chair_email', 'mahmud.idris@example.com');
      }, sat);
    });
    await d.caption('Weekends, public holidays and clashes are warned about, and the AE decides.', async () => {
      await d.clickNav(['button', 'Schedule and Notify']);
      await d.highlight(await d.findAny(['.message-warning', null], ['div', 'Saturday'], ['div', 'weekend']), { hold: 2600 });
      await d.click('label[for=override_warnings]');
      await d.clickNav(['button', 'Schedule and Notify']);
    }, { min: 6 });
    await d.cut(async () => { await d.wait(2500); await d.mailpit('Viva scheduled: Lee Jia Hui'); });
    await d.caption('Setting the date sends calendar invites to the student, supervisor, examiners and chair.', async () => {
      await d.highlight(['*', 'Attachments ('], { hold: 2000 }).catch(() => {});
      await d.click(['button, a, span', 'Show attachment details']).catch(() => {});
      await d.wait(600);
      await d.highlight(await d.findAny(['a', 'viva-voce.ics'], ['*', 'viva-voce.ics']), { hold: 2600 });
    }, { min: 4.5 });
    await d.as('lee.jiahui@example.com', '/viva/eligibility');
    await d.caption('Students follow their progress but never see examiner names.', async () => {
      const t = await d.find('h3, h2', 'Your Viva Progress');
      await d.highlight(t, { hold: 2800, union: [await d.find('tr', 'VIVA VOCE')] });
    }, { min: 4 });
  },

  // 6. Re-viva monitoring.
  6: async d => {
    await d.as('waheda.cgs@utp.edu.my', '/re-viva/cycles');
    await d.caption('Every re-viva has a formal submission time, so each deadline is calculated from it.', async () => {
      const row = await d.find('tr', 'Hazwani');
      await d.highlight(await row.$('xpath/./td[last()-1]') || row, { hold: 2600 });
    }, { min: 4 });
    await d.caption("The risk score lists its factors. It's rule-based, not a prediction.", async () => {
      const row = await d.find('tr', 'Hazwani');
      await d.click(await row.$('summary'));
      await d.wait(500);
      await d.highlight(await row.$('.reviva-risk-factors'), { hold: 2800, union: [await row.$('.status-badge')] });
    }, { min: 4.5 });
    const letter = await d.page.evaluate(() => [...document.querySelectorAll('a')].find(a => a.textContent.includes('Correction letter') && a.href.includes('staff-documents'))?.href);
    await d.cut(async () => { await d.openPdf(letter, 'correction-letter.pdf'); });
    await d.caption('Correction letters and re-evaluation forms are generated automatically.', async () => { await d.wait(3500); }, { min: 4.5 });
    await d.cut(async () => { await d.loginAs('dean@utp.edu.my'); await ensureDeanSignature(d); await d.go('/re-viva/queue'); });
    await d.caption('The Dean approves the pack…', async () => {
      await d.highlight(['h3, h4, p, b, div', 'Letters and report forms'], { hold: 2000 }).catch(() => {});
      await d.clickNav(['button', 'Approve']);
    }, { min: 4 });
    await d.as('waheda.cgs@utp.edu.my', '/re-viva/queue?stage=ready_to_send');
    await d.caption('…and CGS sends it to the panel in one click, with signed, expiring links.', async () => {
      // The pack the Dean just approved (Elina), so the story carries on.
      const btn = (await d.page.evaluateHandle(() => {
        const btns = [...document.querySelectorAll('button')].filter(b => b.textContent.includes('Send to panel'));
        return btns.find(b => { let e = b; for (let i = 0; i < 8 && e; i++, e = e.parentElement) if (e.innerText.includes('Elina')) return true; return false; }) || btns[0] || null;
      })).asElement();
      await d.highlight(btn, { hold: 2000 });
      await d.clickNav(btn);
      await d.wait(800);
    }, { min: 4 });
    await d.cut(async () => { await d.wait(3000); await d.mailpit('Correction of thesis for re-evaluation'); });
    await d.caption("The panel gets a working-day deadline: 20 days for Master, 30 for PhD, with reminders before it's due.", async () => {
      await d.highlightInFrame('iframe', 'p, td', 'Please return the completed report', { hold: 3000 }).catch(() => d.wait(3000));
    }, { min: 5 });
  },
};

// ---------------------------------------------------------------------------
(async () => {
  const args = process.argv.slice(2);
  const dry = args.includes('--dry');
  const wanted = args.filter(a => /^\d+$/.test(a)).map(Number);
  const list = wanted.length ? wanted : Object.keys(SEGMENTS).map(Number);
  fs.mkdirSync(OUT, { recursive: true });
  // Segments that build on earlier ones' changes: when recorded alone, the
  // earlier ones are replayed off camera first (fast, no capture).
  const DEPENDS = { 4: [3], 5: [2, 3] };
  const done = new Set();
  for (const n of list) {
    for (const dep of DEPENDS[n] || []) {
      if (list.includes(dep) || done.has(dep)) continue;
      const p = await Demo.launch({ outDir: OUT, segment: 'prep' + dep, dry: true });
      await ensureAssets(p);
      try { await SEGMENTS[dep](p); } finally { await p.browser.close(); }
      done.add(dep);
      console.log(`segment ${dep}: replayed off camera for segment ${n}`);
    }
    done.add(n);
    const seg = 'seg' + n;
    fs.rmSync(path.join(OUT, 'frames', seg), { recursive: true, force: true });
    const d = await Demo.launch({ outDir: OUT, segment: seg, dry });
    await ensureAssets(d);
    const t0 = Date.now();
    try {
      // In dry runs, a screenshot after every caption for checking.
      if (dry) { const orig = d.caption.bind(d); let i = 0; d.caption = async (t, fn, o) => { await orig(t, fn, o); await d.shot(String(++i).padStart(2, '0')); }; }
      await SEGMENTS[n](d);
      await d.wait(600);
    } catch (e) {
      await d.shot('error').catch(() => {});
      await d.browser.close();
      console.error(`segment ${n} failed:`, e.message);
      process.exit(1);
    }
    const total = await d.close(path.join(OUT, seg));
    console.log(`segment ${n}: ${total.toFixed(1)} s of video (${((Date.now() - t0) / 1000).toFixed(0)} s wall)`);
  }
})();

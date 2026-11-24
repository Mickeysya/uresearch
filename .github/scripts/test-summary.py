"""Write one Markdown job summary: overall totals first, then each module.

Usage:
  python3 test-summary.py "Title" "Module A=a.xml" "Module B=b.xml" ... \
      [--functional docs/functional-tests/results.md] >> "$GITHUB_STEP_SUMMARY"

Each X.xml is a PHPUnit JUnit report. --functional adds the recorded browser
run. CI does not run those cases; it only reports what the file records.
"""
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

PREFIX = {"GAX": "GA Extension", "SUP": "Supervision", "CER": "Certification",
          "ATT": "Attendance", "SHR": "Shared"}


def read_junit(path):
    """List of (class, tests, failed, skipped, seconds, [failure lines]), or None if missing."""
    if not Path(path).exists():
        return None
    out = []
    for suite in ET.parse(path).getroot().iter("testsuite"):
        if suite.find("testcase") is None:
            continue
        name = suite.get("name").rsplit("\\", 1)[-1]
        fails = []
        for case in suite.iter("testcase"):
            problem = case.find("failure")
            if problem is None:
                problem = case.find("error")
            if problem is not None:
                first = ((problem.text or "").strip().splitlines() or [""])[0]
                fails.append(f"`{case.get('name')}`: {first}")
        out.append((name, int(suite.get("tests")),
                    int(suite.get("failures")) + int(suite.get("errors")),
                    int(suite.get("skipped")), float(suite.get("time")), fails))
    return out


def read_functional(path):
    """Rows (id, case, result, covering test) from the results table, plus the run dates."""
    text = Path(path).read_text()
    rows = []
    for line in text.splitlines():
        cells = [c.strip() for c in line.strip().strip("|").split("|")]
        if len(cells) >= 7 and re.fullmatch(r"[A-Z]{3}-\d+", cells[0]):
            result = cells[5].replace("*", "")
            if result in ("Pass", "Fail", "Not run"):
                rows.append((cells[0], cells[1], result, cells[6]))
    dates = sorted(set(re.findall(r"day (\d{1,2} \w+ \d{4})", text.split("**Total:**")[0])))
    return rows, dates


def icon(bad, missing=False):
    return "⚠️" if missing else ("❌" if bad else "✅")


title, args = sys.argv[1], sys.argv[2:]
functional = None
if "--functional" in args:
    i = args.index("--functional")
    functional = args[i + 1]
    del args[i:i + 2]

modules = [(name, read_junit(path)) for name, path in (a.split("=", 1) for a in args)]

# ---- Overall ---------------------------------------------------------------
print(f"# {title}\n")
print("## Overall\n")
print("### Automated tests (run by CI on this commit)\n")
print("| | Module | Tests | Passed | Failed | Skipped | Time |")
print("|---|---|---:|---:|---:|---:|---:|")
T = F = S = 0
secs = 0.0
missing = []
for name, classes in modules:
    if classes is None:
        missing.append(name)
        print(f"| ⚠️ | {name} | – | – | – | – | no report (job stopped before tests) |")
        continue
    t, f, s, x = (sum(c[i] for c in classes) for i in (1, 2, 3, 4))
    T, F, S, secs = T + t, F + f, S + s, secs + x
    print(f"| {icon(f)} | {name} | {t} | {t - f - s} | {f} | {s} | {x:.1f}s |")
print(f"| {icon(F, bool(missing))} | **Total** | **{T}** | **{T - F - S}** | **{F}** | **{S}** | {secs:.1f}s |\n")

if functional:
    rows, dates = read_functional(functional)
    print("### Functional cases (manual browser run, recorded, not run by CI)\n")
    print(f"From `{functional}`, run on {', '.join(dates) or 'see file'}.\n")
    print("| | Module | Cases | Passed | Failed | Not run |")
    print("|---|---|---:|---:|---:|---:|")
    totals = [0, 0, 0, 0]
    for prefix, name in PREFIX.items():
        mine = [r for r in rows if r[0].startswith(prefix)]
        counts = [len(mine)] + [sum(r[2] == k for r in mine) for k in ("Pass", "Fail", "Not run")]
        totals = [a + b for a, b in zip(totals, counts)]
        print(f"| {icon(counts[2], not counts[2] and counts[3])} | {name} | " + " | ".join(map(str, counts)) + " |")
    print(f"| {icon(totals[2], not totals[2] and totals[3])} | **Total** | " + " | ".join(f"**{n}**" for n in totals) + " |\n")
    other = [r for r in rows if r[2] != "Pass"]
    if other:
        print("Not passed in the browser:\n")
        print("| ID | Case | Result | Covered by automated test |")
        print("|---|---|---|---|")
        for rid, case, result, cover in other:
            print(f"| {rid} | {case} | {result} | {cover or '–'} |")
        print()

# ---- Module by module ------------------------------------------------------
print("## By module\n")
for name, classes in modules:
    if classes is None:
        print(f"### ⚠️ {name}\n\nNo test report: the job stopped before the tests ran. Open its log.\n")
        continue
    f = sum(c[2] for c in classes)
    print(f"<details{' open' if f else ''}><summary><b>{icon(f)} {name}</b>: "
          f"{sum(c[1] for c in classes) - f - sum(c[3] for c in classes)} / {sum(c[1] for c in classes)} passed</summary>\n")
    print("| | Test class | Tests | Passed | Failed | Skipped | Time |")
    print("|---|---|---:|---:|---:|---:|---:|")
    for cname, t, bad, skip, x, _ in classes:
        print(f"| {icon(bad)} | {cname} | {t} | {t - bad - skip} | {bad} | {skip} | {x:.1f}s |")
    fails = [f"- **{c[0]}** › {line}" for c in classes for line in c[5]]
    if fails:
        print("\n**Failures**\n")
        print("\n".join(fails))
    print("\n</details>\n")

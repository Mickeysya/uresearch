"""Turn a PHPUnit JUnit report into a Markdown job summary.

Usage: python3 test-summary.py junit.xml "Module name" >> "$GITHUB_STEP_SUMMARY"
"""
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

path, title = sys.argv[1], sys.argv[2]

if not Path(path).exists():
    print(f"## ❌ {title}\n\nNo test report: the run stopped before the tests started. Open the job log.")
    sys.exit(0)

root = ET.parse(path).getroot()
classes = [s for s in root.iter("testsuite") if s.find("testcase") is not None]

total = failed = skipped = 0
seconds = 0.0
rows, failures = [], []
for suite in classes:
    name = suite.get("name").rsplit("\\", 1)[-1]
    tests = int(suite.get("tests"))
    bad = int(suite.get("failures")) + int(suite.get("errors"))
    skip = int(suite.get("skipped"))
    time = float(suite.get("time"))
    total, failed, skipped, seconds = total + tests, failed + bad, skipped + skip, seconds + time
    rows.append(f"| {'❌' if bad else '✅'} | {name} | {tests} | {tests - bad - skip} | {bad} | {skip} | {time:.1f}s |")
    for case in suite.iter("testcase"):
        problem = case.find("failure") if case.find("failure") is not None else case.find("error")
        if problem is not None:
            first_line = (problem.text or "").strip().splitlines()[:1]
            failures.append(f"- **{name}** › `{case.get('name')}`  \n  {first_line[0] if first_line else ''}")

print(f"## {'❌' if failed else '✅'} {title}\n")
print(f"**{total - failed - skipped} / {total} passed** · {failed} failed · {skipped} skipped · {seconds:.1f}s\n")
print("| | Test class | Tests | Passed | Failed | Skipped | Time |")
print("|---|---|---:|---:|---:|---:|---:|")
print("\n".join(rows))
if failures:
    print("\n### Failures\n")
    print("\n".join(failures))

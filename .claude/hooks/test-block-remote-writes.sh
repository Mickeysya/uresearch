#!/usr/bin/env bash
# Tests for block-remote-writes.py. Run after editing the hook:
#     ./.claude/hooks/test-block-remote-writes.sh
set -uo pipefail
cd "$(dirname "$0")"
H=./block-remote-writes.py
pass=0; fail=0

check() {  # check BLOCK|ALLOW "<command>"
  local json got
  json=$(python3 -c 'import json,sys;print(json.dumps({"tool_name":"Bash","tool_input":{"command":sys.argv[1]}}))' "$2")
  printf '%s' "$json" | python3 "$H" >/dev/null 2>&1
  got=$([ $? -eq 2 ] && echo BLOCK || echo ALLOW)
  if [ "$got" = "$1" ]; then
    pass=$((pass+1)); printf '  \033[32m✓\033[0m %-5s %s\n' "$got" "$2"
  else
    fail=$((fail+1)); printf '  \033[31m✗\033[0m expected %s got %s: %s\n' "$1" "$got" "$2"
  fi
}

echo "── must BLOCK: pushes"
check BLOCK "git push"
check BLOCK "git push -u origin laravel-rewrite"
check BLOCK "git push --force-with-lease origin main"
check BLOCK "cd /tmp && git push"
check BLOCK "git add -A && git commit -m x && git push"
check BLOCK "git -C /home/sharvin/projects/uresearch push"
check BLOCK "git -c user.name=x -C /tmp push origin main"
check BLOCK "/usr/bin/git push"
check BLOCK "sudo git push"
check BLOCK "GIT_SSH_COMMAND='ssh -i k' git push"
check BLOCK "(cd /tmp && git push)"
check BLOCK "echo \$(git push)"
check BLOCK "{ git push; }"
check BLOCK "echo hi; git push"

echo "── must BLOCK: remote redirection and other publishing"
check BLOCK "git remote set-url origin git@github.com:someone/else.git"
check BLOCK "git remote add mirror git@github.com:x/y.git"
check BLOCK "git send-email --to x@y.com"

echo "── must BLOCK: gh writes"
check BLOCK "gh pr create --title x --body y"
check BLOCK "gh pr merge 3 --squash"
check BLOCK "gh repo create foo --public"
check BLOCK "gh release create v1.0"
check BLOCK "gh issue create -t bug"
check BLOCK "gh api -X POST /repos/x/y/issues"
check BLOCK "gh api --method=DELETE /repos/x/y"
check BLOCK "gh secret set TOKEN"
check BLOCK "gh workflow run deploy.yml"

echo "── must ALLOW: ordinary local work"
check ALLOW "git status"
check ALLOW "git add -A"
check ALLOW "git commit -m 'a change'"
check ALLOW "git commit -m 'fix(core): thing'"
check ALLOW "git log --oneline -5"
check ALLOW "git fetch origin"
check ALLOW "git pull --rebase origin main"
check ALLOW "git remote -v"
check ALLOW "git remote show origin"
check ALLOW "git checkout -b feature/x"
check ALLOW "git diff --stat"
check ALLOW "git -C /tmp status"
check ALLOW "php artisan migrate"
check ALLOW "docker compose up -d"

echo "── must ALLOW: read-only gh"
check ALLOW "gh pr view 3"
check ALLOW "gh pr list --state open"
check ALLOW "gh pr diff 3"
check ALLOW "gh repo view"
check ALLOW "gh issue list"
check ALLOW "gh api /repos/x/y"

echo "── must ALLOW: mentions, not invocations"
check ALLOW "echo 'do not git push this'"
check ALLOW "echo \"then run: git push -u origin main\""
check ALLOW "grep -rn 'git push' README.md"
check ALLOW "sed -i 's/git push/git pull/' notes.txt"
check ALLOW "check() { echo hi; }"
check ALLOW "for f in \$(ls); do echo \$f; done"
printf 'cat > /tmp/x.md <<%s\nRun git push -u origin main\ngh pr create --title x\nEOF\n' "'EOF'" > /tmp/_hd.txt
check ALLOW "$(cat /tmp/_hd.txt)"; rm -f /tmp/_hd.txt

echo
printf '  \033[1m%d passed, %d failed\033[0m\n' "$pass" "$fail"
[ "$fail" -eq 0 ]

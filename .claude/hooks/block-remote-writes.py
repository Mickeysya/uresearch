#!/usr/bin/env python3
"""
Blocks Claude from publishing anything to GitHub or any other remote.

Pushing, opening PRs, creating releases and repointing remotes are the human's
call on this project. Claude may still commit locally, read from remotes, and
run every read-only `gh` command.

Registered as a PreToolUse hook in .claude/settings.json. Exit code 2 rejects
the tool call and shows the message below to Claude.

The command is tokenised rather than pattern-matched against the raw string,
so that `git -C /path push` is caught while `echo "run git push"` and a
heredoc that documents `git push` are not.

To lift this: delete the hooks block from .claude/settings.json, or this file.
"""
import json
import re
import shlex
import sys

# git subcommands that write to a remote or could redirect one.
GIT_BLOCKED = {
    'push': 'git push',
    'send-email': 'git send-email',
}
GIT_REMOTE_WRITES = {'add', 'set-url', 'rename', 'remove', 'rm', 'set-branches', 'set-head'}

# gh <group> <verb> pairs that write.
GH_BLOCKED = {
    'pr':       {'create', 'merge', 'close', 'reopen', 'edit', 'ready', 'review', 'comment'},
    'repo':     {'create', 'delete', 'edit', 'fork', 'sync', 'rename', 'archive', 'unarchive'},
    'release':  {'create', 'delete', 'edit', 'upload', 'download-delete'},
    'issue':    {'create', 'close', 'reopen', 'edit', 'comment', 'delete', 'transfer', 'pin'},
    'gist':     {'create', 'edit', 'delete', 'rename'},
    'secret':   {'set', 'delete', 'remove'},
    'variable': {'set', 'delete', 'remove'},
    'workflow': {'run', 'enable', 'disable'},
    'cache':    {'delete'},
    'ssh-key':  {'add', 'delete'},
    'gpg-key':  {'add', 'delete'},
}

# git global options that consume the following token as their value.
GIT_OPTS_WITH_VALUE = {'-C', '-c', '--git-dir', '--work-tree', '--namespace', '--exec-path'}

WRAPPERS = {'sudo', 'env', 'command', 'nohup', 'time', 'stdbuf', 'nice', 'setsid'}

SEPARATORS = re.compile(r'\|\||&&|[;|&\n]')

MESSAGE = """BLOCKED: {what}

Publishing to a remote is the user's call on this project, not yours.
Commit locally, then tell the user exactly what to run, for example:

    git push -u origin <branch>

You may still: commit, fetch, pull, and run read-only gh commands
(gh pr view/list/diff, gh repo view, gh issue list, gh api without -X).

See the "Publishing" rule in CLAUDE.md."""


def strip_heredocs(command: str) -> str:
    """Drop heredoc bodies so documented commands inside them are not treated
    as commands themselves."""
    lines = command.split('\n')
    out, i = [], 0
    while i < len(lines):
        line = lines[i]
        out.append(line)
        for m in re.finditer(r'<<-?\s*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1', line):
            terminator = m.group(2)
            i += 1
            while i < len(lines) and lines[i].strip() != terminator:
                i += 1
            break
        i += 1
    return '\n'.join(out)


def segments(command: str):
    """Split into command segments on shell separators, ignoring separators
    that sit inside quotes. Subshell and command-substitution punctuation is a
    separator too, so `(cd /tmp && git push)` and `$(git push)` each yield a
    segment whose first token is the real program."""
    parts, buf, quote = [], [], None
    i = 0
    while i < len(command):
        ch = command[i]
        if quote:
            buf.append(ch)
            if ch == quote and command[i - 1] != '\\':
                quote = None
        elif ch in '"\'':
            quote = ch
            buf.append(ch)
        elif ch in ';|&\n()':
            parts.append(''.join(buf))
            buf = []
            while i + 1 < len(command) and command[i + 1] in ';|&':
                i += 1
        else:
            buf.append(ch)
        i += 1
    parts.append(''.join(buf))
    return [p.strip() for p in parts if p.strip()]


def argv_of(segment: str):
    """Tokens of a segment with env assignments, wrappers and subshell
    punctuation stripped. None if it cannot be parsed."""
    try:
        tokens = shlex.split(segment, posix=True)
    except ValueError:
        return None

    while tokens:
        head = tokens[0].lstrip('(){} ')
        if not head:
            tokens = tokens[1:]
        elif re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*=.*', head):
            tokens = tokens[1:]
        elif head.rsplit('/', 1)[-1] in WRAPPERS:
            tokens = tokens[1:]
        else:
            tokens = [head] + tokens[1:]
            break
    return tokens


def git_subcommand(tokens):
    """First non-option token after `git`, skipping global options and the
    values they consume."""
    i = 1
    while i < len(tokens):
        t = tokens[i]
        if t in GIT_OPTS_WITH_VALUE:
            i += 2
        elif t.startswith('-'):
            i += 1
        else:
            return tokens[i], tokens[i + 1:]
        continue
    return None, []


def verdict(command: str):
    for segment in segments(strip_heredocs(command)):
        tokens = argv_of(segment)
        if tokens is None:
            # Unparseable (unbalanced quotes). Fall back to a raw scan so an
            # obfuscated push cannot slip through on a syntax error.
            if re.search(r'\bgit\b[^\n]*\bpush\b', segment) or \
               re.search(r'\bgh\b\s+\w+\s+(create|merge|delete|edit)\b', segment):
                return 'a possible remote write (command could not be parsed)'
            continue
        if not tokens:
            continue

        program = tokens[0].rsplit('/', 1)[-1]

        if program == 'git':
            sub, rest = git_subcommand(tokens)
            if sub in GIT_BLOCKED:
                return GIT_BLOCKED[sub]
            if sub == 'remote' and rest and rest[0] in GIT_REMOTE_WRITES:
                return 'changing a git remote'
            if sub == 'svn' and rest and rest[0] == 'dcommit':
                return 'git svn dcommit'

        elif program == 'gh':
            args = [t for t in tokens[1:] if not t.startswith('-')]
            if len(args) >= 2 and args[1] in GH_BLOCKED.get(args[0], ()):
                return f'a gh {args[0]} write ({args[0]} {args[1]})'
            if args and args[0] == 'api':
                for i, t in enumerate(tokens):
                    if t in ('-X', '--method') and i + 1 < len(tokens) \
                       and tokens[i + 1].upper() in ('POST', 'PUT', 'PATCH', 'DELETE'):
                        return 'a mutating gh api call'
                    if t.startswith('--method=') and t.split('=', 1)[1].upper() in \
                       ('POST', 'PUT', 'PATCH', 'DELETE'):
                        return 'a mutating gh api call'
    return None


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except (json.JSONDecodeError, ValueError):
        return 0  # never break the session on a malformed payload

    if payload.get('tool_name') != 'Bash':
        return 0

    command = (payload.get('tool_input') or {}).get('command', '')
    if not command:
        return 0

    what = verdict(command)
    if what:
        print(MESSAGE.format(what=what), file=sys.stderr)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main())

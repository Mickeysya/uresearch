#!/usr/bin/env bash
# The Hani demo video, end to end.
#   ./build.sh                 full reseed, record every segment, build the video
#   ./build.sh 3 4             reseed, re-record segments 3 and 4 only (their
#                              prerequisites are replayed off camera), rebuild
#   ./build.sh --build-only    rebuild from the recorded segments (captions, cards)
#   ./build.sh --no-reseed 6   re-record without reseeding: only for segments
#                              that need no earlier state (2, 6; see storyboard.md)
# Output: out/UResearch-Hani-demo-captioned.mp4 (+ .srt, .ass, timings.txt)
set -euo pipefail
cd "$(dirname "$0")"
[ -d node_modules ] || npm install --silent

RESEED=1; RECORD=1; SEGS=()
for a in "$@"; do
  case "$a" in
    --build-only) RECORD=0; RESEED=0 ;;
    --no-reseed)  RESEED=0 ;;
    *)            SEGS+=("$a") ;;
  esac
done

if [ "$RESEED" -eq 1 ]; then ./reseed.sh; fi
if [ "$RECORD" -eq 1 ]; then node record.js ${SEGS[@]+"${SEGS[@]}"}; fi
node build.js

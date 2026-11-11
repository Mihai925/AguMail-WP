#!/usr/bin/env bash
# Copies the wp.org listing images (banners, icons, screenshots) from
# .wordpress-org/ into the given directory, which the 10up actions then sync to
# SVN /assets. Sources, render scripts and READMEs kept next to them stay in git.
#
# When there is nothing to stage it leaves no directory behind, so the deploy
# skips the asset sync instead of emptying SVN /assets.
set -euo pipefail

SRC=.wordpress-org
DEST=${1:?usage: stage-wporg-assets.sh <dest-dir>}

rm -rf "$DEST"
mkdir -p "$DEST"
if [ -d "$SRC" ]; then
  find "$SRC" -maxdepth 1 -type f \
    \( -name 'banner-*' -o -name 'icon-*' -o -name 'icon.svg' -o -name 'screenshot-*' \) \
    \( -name '*.png' -o -name '*.jpg' -o -name '*.jpeg' -o -name '*.gif' -o -name '*.svg' \) \
    -exec cp {} "$DEST/" \;
fi

COUNT=$(find "$DEST" -type f | wc -l | tr -d ' ')
if [ "$COUNT" -eq 0 ]; then
  rmdir "$DEST"
  echo "No listing images in $SRC/, SVN /assets is left as it is."
  HAS=false
else
  echo "Staged $COUNT listing image(s):"
  ls -1 "$DEST"
  HAS=true
fi

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  echo "has_assets=$HAS" >> "$GITHUB_OUTPUT"
fi

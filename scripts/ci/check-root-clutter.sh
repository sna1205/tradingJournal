#!/usr/bin/env bash
set -euo pipefail

ALLOWLIST_REGEX='^(apps|packages|infra|scripts|docs|tests|\.github|\.vscode)$'

ALLOW_FILES_REGEX='^(\.gitignore|\.editorconfig|README\.md|CODEOWNERS|package\.json|pnpm-lock\.yaml|package-lock\.json|yarn\.lock|vercel\.json)$'

bad=0

while IFS= read -r name; do
  if [[ ! "$name" =~ $ALLOWLIST_REGEX ]]; then
    echo "Unexpected root directory: $name"
    bad=1
  fi
done < <(git ls-files | awk -F/ 'NF>1 {print $1}' | sort -u)

while IFS= read -r name; do
  if [[ ! "$name" =~ $ALLOW_FILES_REGEX ]]; then
    echo "Unexpected root file: $name"
    bad=1
  fi
done < <(git ls-files | awk -F/ 'NF==1 {print $1}' | sort -u)

if [[ $bad -eq 1 ]]; then
  echo
  echo "Fix: move files into apps/, infra/, scripts/, docs/ or update allowlist intentionally."
  exit 1
fi

echo "Root clutter check passed."

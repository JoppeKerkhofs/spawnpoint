#!/bin/sh
# Fails unless the version in panel/composer.json is higher than on the given git
# ref. Usage: sh scripts/check-version.sh origin/main
set -eu

version() {
    sed -n 's/.*"version": *"\([^"]*\)".*/\1/p' | head -n 1
}

new=$(version < panel/composer.json)
old=$(git show "$1:panel/composer.json" | version)

if ! echo "$new" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "panel/composer.json needs a version like 1.2.3, found: '$new'" >&2
    exit 1
fi

# A base without a version has nothing to compare with.
if [ -z "$old" ]; then
    echo "Version $new (no version on $1 yet)"
    exit 0
fi

if awk -v new="$new" -v old="$old" 'BEGIN {
    split(new, n, "."); split(old, o, ".")
    for (i = 1; i <= 3; i++) {
        if (n[i] + 0 > o[i] + 0) exit 0
        if (n[i] + 0 < o[i] + 0) exit 1
    }
    exit 1
}'; then
    echo "Version raised from $old to $new"
else
    echo "Raise the version in panel/composer.json: it is $new, and $1 is already at $old." >&2
    exit 1
fi

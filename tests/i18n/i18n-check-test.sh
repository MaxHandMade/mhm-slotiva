#!/usr/bin/env bash
# bin/i18n-check.py placeholder audit, against small .po fixtures (Codex final audit finding 5).
# Usage: bash tests/i18n/i18n-check-test.sh            (repo root; CHECK=<path> tests another checker)
set -uo pipefail
cd "$( dirname "$0" )/../.."
CHECK="${CHECK:-bin/i18n-check.py}"
tmp="$( mktemp -d )"
trap 'rm -rf "$tmp"' EXIT

header='msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"
"Plural-Forms: nplurals=2; plural=(n > 1);\n"'

fail=0
expect() { # name, wanted exit, .po body
	printf '%s\n\n%s\n' "$header" "$3" > "$tmp/$1.po"
	python -X utf8 "$CHECK" "$tmp/$1.po" > "$tmp/$1.out" 2>&1
	got=$?
	if [ "$got" -ne "$2" ]; then
		echo "FAIL $1: exit $got, want $2"
		cat "$tmp/$1.out"
		fail=1
	else
		echo "ok   $1"
	fi
}

expect clean_plural 0 'msgid "%d day"
msgid_plural "%d days"
msgstr[0] "%d gün"
msgstr[1] "%d gün"'

expect plural_extra_placeholder 1 'msgid "%d day"
msgid_plural "%d days"
msgstr[0] "%d gün"
msgstr[1] "%d gün %s"'

expect plural_missing_placeholder 1 'msgid "%d day"
msgid_plural "%d days"
msgstr[0] "%d gün"
msgstr[1] "günler"'

expect singular_mismatch 1 'msgid "Hello %s"
msgstr "Merhaba %d"'

expect empty_plural_form 1 'msgid "%d day"
msgid_plural "%d days"
msgstr[0] "%d gün"
msgstr[1] ""'

exit "$fail"

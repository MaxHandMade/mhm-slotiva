"""Turkish .po audit (bin/i18n.sh): empty msgstr 0, fuzzy 0, placeholders match.

Each form is compared with its own source: msgstr / msgstr[0] with msgid, msgstr[1..] with msgid_plural. The
placeholder multiset (%s, %d, %1$s ...) must be equal; no form is exempt.

Usage: python -X utf8 bin/i18n-check.py languages/mhm-slotiva-tr_TR.po   (exit 0 = clean, 1 = finding)
"""
import re
import sys

PH = re.compile(r'%(?:\d+\$)?[sd]')


def val(block, key):
    m = re.search(r'^' + re.escape(key) + r' ((?:".*"\n?)+)', block, re.M)
    return None if not m else ''.join(re.findall(r'"(.*)"', m.group(1)))


def forms(block):
    """[(translation, source)] for every msgstr form of the block."""
    mid = val(block, 'msgid')
    single = val(block, 'msgstr')
    if single is not None:
        return [(single, mid)]
    plural = val(block, 'msgid_plural')
    out = []
    n = 0
    while True:
        s = val(block, 'msgstr[%d]' % n)
        if s is None:
            break
        out.append((s, mid if n == 0 else plural))
        n += 1
    return out


def main(path):
    txt = open(path, encoding='utf-8').read()
    blocks = txt.strip().split('\n\n')[1:]
    empty = fuzzy = bad = 0
    for b in blocks:
        if b.lstrip().startswith('#~'):
            continue
        if re.search(r'^#,.*fuzzy', b, re.M):
            fuzzy += 1
        pairs = forms(b)
        if not pairs:
            empty += 1
        for s, src in pairs:
            if not s:
                empty += 1
            elif sorted(PH.findall(s)) != sorted(PH.findall(src or '')):
                bad += 1
                print('yer tutucu uyuşmuyor:', val(b, 'msgid'))
    print(f'girdi={len(blocks)} bos_msgstr={empty} fuzzy={fuzzy} yer_tutucu_hatasi={bad}')
    return 1 if empty or fuzzy or bad else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1]))

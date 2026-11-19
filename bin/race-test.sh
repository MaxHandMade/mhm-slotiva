#!/usr/bin/env bash
# Yarış testleri (gerçek HTTP, yerel Docker yığını http://localhost:8110; pakete girmez):
#   1) tests/e2e/race-checkout.sh — kabul senaryosu 1: iki ayrı sepet çerezi AYNI dilimi AYNI ANDA öder
#      → tam bir 200 + bir 409 slotiva_slot_taken, o dilim için COUNT(*) = 1 etkin rezervasyon.
#   2) tests/e2e/race-quota.sh — kota yarışı (§15.8): aynı IP'den farklı oturumlar sınırda eşzamanlı → biri 429;
#      aynı oturumdan sınırda eşzamanlı iki gönderim → biri reddedilir; kota hiç aşılmaz.
#   3) tests/e2e/http-guards.sh — senaryo 3 (eski sayfa dolu dilimi gönderir → 409) ve 4 (randevusuz ödeme → 400).
# Betikler kendi verisini kurar ve siler. Kullanım (depo kökünden): bash bin/race-test.sh
set -uo pipefail
cd "$( dirname "$0" )/.."

rc=0
echo "=== 1/3 race-checkout (senaryo 1) ==="
bash tests/e2e/race-checkout.sh || rc=1
echo
echo "=== 2/3 race-quota (oturum/IP kotası) ==="
bash tests/e2e/race-quota.sh || rc=1
echo
echo "=== 3/3 http-guards (senaryo 3, 4) ==="
bash tests/e2e/http-guards.sh || rc=1
echo
if [ "$rc" = 0 ]; then echo "YARIŞ TESTLERİ: GEÇTİ"; else echo "YARIŞ TESTLERİ: KALDI" >&2; fi
exit "$rc"

#!/usr/bin/env bash
#
# Exercise every Bouncer surface that only exists over HTTP: the request guard, the password gate,
# the guarded file route, signed URLs and range requests.
#
# Seed first:
#   docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bouncer/tests/manual/seed-demo.php
# then:
#   bash tests/manual/http-probe.sh [base-url]
#
# Nothing here can be asserted from a console script: the guard hangs off a controller action, the
# gate is a session, and the file route is a stream with headers.

set -uo pipefail

BASE="${1:-https://plugin-testing.ddev.site}"
JAR=$(mktemp)
JAR2=$(mktemp)
PASS="letmein"
PASSED=0
FAILED=0

cleanup() { rm -f "$JAR" "$JAR2"; }
trap cleanup EXIT

req() { curl -sk -b "$1" -c "$1" "${@:2}"; }

expect() {
    local label="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        printf '  \033[32m✓\033[0m %s\n' "$label"
        PASSED=$((PASSED + 1))
    else
        printf '  \033[31m✗\033[0m %s — expected %s, got %s\n' "$label" "$expected" "$actual"
        FAILED=$((FAILED + 1))
    fi
}

status() { req "$1" -o /dev/null -w '%{http_code}' "${@:2}"; }

# Fetch into a file before grepping. Piping curl straight into `grep -q` makes grep exit on the
# first match and close the pipe, curl dies of SIGPIPE, and under `pipefail` the whole expression
# reports failure — intermittently, depending on how fast the match arrives.
body_has() {
    local jar="$1" url="$2" needle="$3" tmp
    tmp=$(mktemp)
    req "$jar" -o "$tmp" "$url"
    if grep -q "$needle" "$tmp"; then echo yes; else echo no; fi
    rm -f "$tmp"
}

header_has() {
    local jar="$1" url="$2" needle="$3" tmp
    tmp=$(mktemp)
    req "$jar" -D "$tmp" -o /dev/null "$url"
    if grep -qi "$needle" "$tmp"; then echo yes; else echo no; fi
    rm -f "$tmp"
}

csrf() {
    req "$1" -H 'Accept: application/json' "$BASE/actions/users/session-info" \
        | sed -n 's/.*"csrfTokenValue":"\([^"]*\)".*/\1/p'
}

login() {
    local jar="$1" user="$2" password="$3"
    local token
    token=$(csrf "$jar")
    req "$jar" -o /dev/null -H 'Accept: application/json' \
        -d "loginName=$user" -d "password=$password" -d "CRAFT_CSRF_TOKEN=$token" \
        "$BASE/actions/users/login"
}

asset_uid() {
    docker exec -w /var/www/html ddev-plugin-testing-web php -r '
        require "/var/www/html/bootstrap.php";
        $app = require CRAFT_VENDOR_PATH."/craftcms/cms/bootstrap/console.php";
        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        $asset = $volume ? craft\elements\Asset::find()->volumeId($volume->id)->kind("image")->bouncer(false)->one() : null;
        echo $asset->uid ?? "";
    ' 2>/dev/null
}

signed_url() {
    docker exec -w /var/www/html ddev-plugin-testing-web php -r '
        require "/var/www/html/bootstrap.php";
        $app = require CRAFT_VENDOR_PATH."/craftcms/cms/bootstrap/console.php";
        $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        $asset = $volume ? craft\elements\Asset::find()->volumeId($volume->id)->kind("image")->bouncer(false)->one() : null;
        echo $asset ? justinholtweb\bouncer\Plugin::getInstance()->assets->signedUrl($asset, 900) : "";
    ' 2>/dev/null
}

echo "Bouncer HTTP probe — $BASE"

echo
echo "The request guard"
expect "an unprotected page is untouched" 200 "$(status "$JAR" "$BASE/")"
expect "an anonymous visitor is refused a protected entry" 403 "$(status "$JAR" "$BASE/bouncer-demo/members-only")"
expect "and the second entry in the same section too" 403 "$(status "$JAR" "$BASE/bouncer-demo/also-members-only")"

login "$JAR" bouncerDemoOutsider bouncerdemo1
expect "a logged-in user outside the group is still refused" 403 "$(status "$JAR" "$BASE/bouncer-demo/members-only")"

login "$JAR2" bouncerDemoMember bouncerdemo1
expect "a member of the group is let through" 200 "$(status "$JAR2" "$BASE/bouncer-demo/members-only")"
expect "and gets the real page, not a shell" "yes" "$(body_has "$JAR2" "$BASE/bouncer-demo/members-only" 'through the door')"

echo
echo "Other refusal shapes"
PW_JAR=$(mktemp)
expect "a template response renders in place with a 403" 403 "$(status "$PW_JAR" "$BASE/bouncer-paywall")"
expect "and renders the site's own template, not Bouncer's" "yes" "$(body_has "$PW_JAR" "$BASE/bouncer-paywall" 'id="paywall"')"
expect "with the rule's message available to it" "yes" "$(body_has "$PW_JAR" "$BASE/bouncer-paywall" 'Subscribers only')"
expect "and the reason, so the template can vary its copy" "yes" "$(body_has "$PW_JAR" "$BASE/bouncer-paywall" 'id="reason">login<')"
expect "a redirect response redirects" 302 "$(status "$PW_JAR" "$BASE/bouncer-redirect")"
expect "and a member is let straight through the paywall" 200 "$(status "$JAR2" "$BASE/bouncer-paywall")"
rm -f "$PW_JAR"

echo
echo "The password gate"
GATE_JAR=$(mktemp)
expect "the wall answers 403, not 200" 403 "$(status "$GATE_JAR" "$BASE/bouncer-gate")"
expect "and renders the password form" "yes" "$(body_has "$GATE_JAR" "$BASE/bouncer-gate" 'bouncer/gate/submit')"

TOKEN=$(csrf "$GATE_JAR")
req "$GATE_JAR" -o /dev/null -d "CRAFT_CSRF_TOKEN=$TOKEN" -d "rule=bouncerDemoPassword" -d "password=wrong" "$BASE/actions/bouncer/gate/submit"
expect "a wrong password does not unlock it" 403 "$(status "$GATE_JAR" "$BASE/bouncer-gate")"

TOKEN=$(csrf "$GATE_JAR")
req "$GATE_JAR" -o /dev/null -d "CRAFT_CSRF_TOKEN=$TOKEN" -d "rule=bouncerDemoPassword" -d "password=$PASS" "$BASE/actions/bouncer/gate/submit"
expect "the right one does" 200 "$(status "$GATE_JAR" "$BASE/bouncer-gate")"

TOKEN=$(csrf "$GATE_JAR")
req "$GATE_JAR" -o /dev/null -d "CRAFT_CSRF_TOKEN=$TOKEN" "$BASE/actions/bouncer/gate/lock"
expect "and locking puts it back" 403 "$(status "$GATE_JAR" "$BASE/bouncer-gate")"

FRESH=$(mktemp)
expect "an unlock does not leak to another session" 403 "$(status "$FRESH" "$BASE/bouncer-gate")"
rm -f "$GATE_JAR" "$FRESH"

echo
echo "The guarded file route"
UID_=$(asset_uid)

if [[ -z "$UID_" ]]; then
    echo "  (skipped — no image assets on this install)"
else
    FILE="$BASE/bouncer/file/$UID_"
    ANON=$(mktemp)

    expect "an anonymous visitor is refused the file" 403 "$(status "$ANON" "$FILE")"
    expect "a logged-in visitor gets it" 200 "$(status "$JAR2" "$FILE")"
    expect "an unknown UID is a 404, not a 403" 404 "$(status "$JAR2" "$BASE/bouncer/file/00000000-0000-0000-0000-000000000000")"
    expect "a forged reference is refused" 404 "$(status "$JAR2" "$FILE?bref=deadbeef")"

    expect "the response is not cacheable by a shared cache" "yes" "$(header_has "$JAR2" "$FILE" 'cache-control: private.*no-store')"
    expect "and is marked no-index" "yes" "$(header_has "$JAR2" "$FILE" 'x-robots-tag: noindex')"
    expect "content sniffing is off" "yes" "$(header_has "$JAR2" "$FILE" 'x-content-type-options: nosniff')"
    expect "ranges are advertised" "yes" "$(header_has "$JAR2" "$FILE" 'accept-ranges: bytes')"
    expect "a range request is answered with 206" 206 "$(status "$JAR2" -H 'Range: bytes=0-99' "$FILE")"
    expect "and returns exactly the bytes asked for" 100 \
        "$(req "$JAR2" -H 'Range: bytes=0-99' -o /dev/null -w '%{size_download}' "$FILE")"
    expect "an impossible range is refused" 416 "$(status "$JAR2" -H 'Range: bytes=99999999-' "$FILE")"

    ETAG=$(req "$JAR2" -D- -o /dev/null "$FILE" | sed -n 's/^[Ee][Tt]ag: *\(.*\)\r*$/\1/p' | tr -d '\r')
    expect "a conditional request is answered 304" 304 "$(status "$JAR2" -H "If-None-Match: $ETAG" "$FILE")"

    SIGNED=$(signed_url)
    if [[ -n "$SIGNED" ]]; then
        expect "a signed URL works with no account at all" 200 "$(status "$ANON" "$SIGNED")"
    fi

    rm -f "$ANON"
fi

echo
echo "----------------------------------------"
echo "$PASSED passed, $FAILED failed"
exit $((FAILED > 0 ? 1 : 0))

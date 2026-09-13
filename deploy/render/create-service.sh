#!/usr/bin/env bash
#
# Create the Render web service from this repository, injecting every environment
# variable it needs — without ever printing a secret.
#
# Why this exists rather than a one-line command: `render services create` accepts
# `--env-var KEY=VALUE` (repeatable), so the whole service, secrets included, can be
# created from a terminal. Building that argument list by hand means either typing
# the secrets into a shell, where they land in history, or echoing them somewhere
# they can be read back. This reads them from `.env` instead, passes each as a single
# argv entry, and masks every value in its own output.
#
# Usage:
#   deploy/render/create-service.sh --dry-run   # print the plan; no API call, no values
#   deploy/render/create-service.sh             # create the service on Render
#
# Prerequisites, each checked before anything is created:
#
#   1. `render login` has been run. Every CLI command needs an authenticated client;
#      the token is written to ~/.render/cli.yaml by the browser flow, never here.
#   2. Render's GitHub App can see the repository. Git-backed services connect through
#      the App, and a public repository does not remove that requirement.
#   3. An external MySQL. Render has no managed MySQL, and a free instance has no
#      private service either, so WordPress's database lives somewhere else. Put its
#      three values in `deploy/render/.env.render` (gitignored) or in the environment.
#
# `services update` has no `--env-var` flag, so this runs once per service: changing a
# variable afterwards is the Dashboard's Environment tab or the REST API.
#
# @package bookify-booking

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

dry_run=0
[ "${1:-}" = "--dry-run" ] && dry_run=1

die() { printf 'error: %s\n' "$*" >&2; exit 1; }
note() { printf '%s\n' "$*" >&2; }

# --------------------------------------------------------------------------- service

name="${RENDER_SERVICE_NAME:-bookify}"
repo="${RENDER_REPO_URL:-https://github.com/paws1234/Bokoify}"
branch="${RENDER_BRANCH:-main}"
plan="${RENDER_PLAN:-free}"
region="${RENDER_REGION:-frankfurt}"

# ----------------------------------------------------------------------------- env

# `.env` is KEY=value, not shell: `BOOKIFY_MAIL_FROM_NAME=Reyvand Jasper Medrano` has
# unquoted spaces, so sourcing it would run `Jasper`. Parse it instead. A name already
# present in the real environment wins, so a one-off override needs no file edit.
load_env() {
	local file="$1" line key value

	[ -f "$file" ] || return 0

	while IFS= read -r line || [ -n "$line" ]; do
		line="${line%$'\r'}"
		case "$line" in ''|'#'*) continue ;; esac
		case "$line" in *=*) ;; *) continue ;; esac

		key="${line%%=*}"
		value="${line#*=}"
		key="${key//[[:space:]]/}"

		# A name that is not a shell identifier is not one this script will export.
		case "$key" in
			[A-Za-z_][A-Za-z0-9_]*) ;;
			*) continue ;;
		esac

		# Trim surrounding whitespace, then one layer of matching quotes.
		value="${value#"${value%%[![:space:]]*}"}"
		value="${value%"${value##*[![:space:]]}"}"
		case "$value" in
			\'*\') value="${value#\'}"; value="${value%\'}" ;;
			'"'*'"') value="${value#\"}"; value="${value%\"}" ;;
		esac

		[ -n "${!key:-}" ] && continue
		printf -v "$key" '%s' "$value"
		export "${key?}"
	done < "$file"
}

load_env .env
load_env deploy/render/.env.render

# ------------------------------------------------------------------------ the list

# `.env` name -> Render name. Only two of these are not identical, and both are easy
# to get silently wrong: the entrypoint reads the administrator from BOOKIFY_ADMIN_*,
# and WordPress's title from WORDPRESS_SITE_TITLE. SITE_URL is deliberately absent:
# the live URL is Render's, and WORDPRESS_CONFIG_EXTRA pins WP_HOME and WP_SITEURL to
# RENDER_EXTERNAL_URL, so injecting http://localhost:8890 could only ever mislead.
map_from_env() {
	BOOKIFY_ADMIN_USER="$ADMIN_USER"
	BOOKIFY_ADMIN_PASSWORD="$ADMIN_PASSWORD"
	BOOKIFY_ADMIN_EMAIL="$ADMIN_EMAIL"
	WORDPRESS_SITE_TITLE="$SITE_TITLE"
	RESEND_API_KEY="${RESEND_API_KEY:-}"
	BOOKIFY_MAIL_FROM="${BOOKIFY_MAIL_FROM:-}"
	BOOKIFY_MAIL_FROM_NAME="${BOOKIFY_MAIL_FROM_NAME:-}"
	BOOKIFY_MAIL_REDIRECT_TO="${BOOKIFY_MAIL_REDIRECT_TO:-}"
	BOOKIFY_SUPABASE_DB_URL="${BOOKIFY_SUPABASE_DB_URL:-}"
	BOOKIFY_SUPABASE_DB_PASSWORD="${BOOKIFY_SUPABASE_DB_PASSWORD:-}"
	BOOKIFY_SUPABASE_DB_SSLMODE="${BOOKIFY_SUPABASE_DB_SSLMODE:-require}"
}
map_from_env

# The database. Either an external MySQL — Render has none of its own, so that is somebody else's server
# and only its owner knows the address — or, with BOOKIFY_LOCAL_DB=1, MariaDB running beside Apache
# inside the instance, which needs no values from anyone.
local_db="${BOOKIFY_LOCAL_DB:-0}"

missing=()
if [ "$local_db" != '1' ]; then
	for v in WORDPRESS_DB_HOST WORDPRESS_DB_USER WORDPRESS_DB_PASSWORD; do
		[ -n "${!v:-}" ] || missing+=( "$v" )
	done
fi

if [ "${#missing[@]}" -gt 0 ] && [ "$dry_run" -eq 0 ]; then
	note "error: no database. Render has no managed MySQL, and a free instance has no"
	note "       private service either, so WordPress's database has to come from":
	note ""
	for v in "${missing[@]}"; do note "         $v"; done
	note ""
	note "       Put them in deploy/render/.env.render (gitignored), for example:"
	note "         WORDPRESS_DB_HOST=mysql.example.com:3306"
	note "         WORDPRESS_DB_USER=bookify"
	note "         WORDPRESS_DB_PASSWORD=..."
	note ""
	note "       Or set BOOKIFY_LOCAL_DB=1 to have the instance run MariaDB itself: free"
	note "       and self-contained, but the filesystem is wiped on every spin-down and"
	note "       the site is rebuilt from Supabase on each cold start."
	exit 1
fi

# Set literally rather than read: the database name is the blueprint's, and
# WORDPRESS_CONFIG_EXTRA is the whole reason a Render instance behaves like production
# — including the absence of DISABLE_WP_CRON, because the free plan has no cron job and
# a page view is the only thing left that can run scheduled work.
config_extra='define( '"'"'WP_HOME'"'"', getenv( '"'"'RENDER_EXTERNAL_URL'"'"' ) );
define( '"'"'WP_SITEURL'"'"', getenv( '"'"'RENDER_EXTERNAL_URL'"'"' ) );
define( '"'"'WP_ENVIRONMENT_TYPE'"'"', '"'"'production'"'"' );
define( '"'"'FS_METHOD'"'"', '"'"'direct'"'"' );
define( '"'"'WP_DEBUG_DISPLAY'"'"', false );'

# ------------------------------------------------------------------------- the plan

# Every value is masked. The four that are shown are ones that cannot be a credential,
# so the plan can still be checked at a glance.
showable=' WORDPRESS_DB_NAME WORDPRESS_SITE_TITLE BOOKIFY_SUPABASE_DB_SSLMODE '

describe() { # key value
	local key="$1" value="$2"

	if [ -z "$value" ]; then
		printf '  %-32s %s\n' "$key" '(empty - left unset)'
	elif [ "$key" = 'WORDPRESS_CONFIG_EXTRA' ]; then
		printf '  %-32s %s\n' "$key" '(multi-line config block)'
	elif [ "${showable#* $key }" != "$showable" ]; then
		printf '  %-32s %s\n' "$key" "$value"
	else
		printf '  %-32s %s\n' "$key" "(set, ${#value} chars)"
	fi
}

note "service   $name  ($plan, $region, docker)"
note "repository $repo  [$branch]"
note ""

pairs=()
add() { # key value
	[ -n "$2" ] || return 0
	pairs+=( "$1=$2" )
	describe "$1" "$2"
}

if [ "$local_db" = '1' ]; then
	add BOOKIFY_LOCAL_DB 1
	add WORDPRESS_DB_HOST 127.0.0.1:3306
	add WORDPRESS_DB_NAME wordpress
	add WORDPRESS_DB_USER "${WORDPRESS_DB_USER:-wordpress}"
	# Generated once, here, rather than at boot: `wp-config.php` reads these from the environment, so a
	# value minted inside the container would not reach a process started later by `render ssh` or
	# `docker exec`, and the `wp` command would answer "Error establishing a database connection" while
	# the site itself worked. Measured exactly that before the values were passed in here. Loopback-only
	# and single-tenant, so they guard nothing — they just have to be the same everywhere.
	add WORDPRESS_DB_PASSWORD "${WORDPRESS_DB_PASSWORD:-$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | cut -c1-20)}"
else
	add WORDPRESS_DB_HOST "$(printf '%s' "${WORDPRESS_DB_HOST:-}")"
	add WORDPRESS_DB_NAME wordpress
	add WORDPRESS_DB_USER "$(printf '%s' "${WORDPRESS_DB_USER:-}")"
	add WORDPRESS_DB_PASSWORD "$(printf '%s' "${WORDPRESS_DB_PASSWORD:-}")"
fi
add WORDPRESS_CONFIG_EXTRA "$config_extra"
add WORDPRESS_SITE_TITLE "${WORDPRESS_SITE_TITLE:-}"
add BOOKIFY_ADMIN_USER "${BOOKIFY_ADMIN_USER:-}"
add BOOKIFY_ADMIN_PASSWORD "${BOOKIFY_ADMIN_PASSWORD:-}"
add BOOKIFY_ADMIN_EMAIL "${BOOKIFY_ADMIN_EMAIL:-}"
add RESEND_API_KEY "${RESEND_API_KEY:-}"
add BOOKIFY_MAIL_FROM "${BOOKIFY_MAIL_FROM:-}"
add BOOKIFY_MAIL_FROM_NAME "${BOOKIFY_MAIL_FROM_NAME:-}"
add BOOKIFY_MAIL_REDIRECT_TO "${BOOKIFY_MAIL_REDIRECT_TO:-}"
add BOOKIFY_SUPABASE_DB_URL "${BOOKIFY_SUPABASE_DB_URL:-}"
add BOOKIFY_SUPABASE_DB_PASSWORD "${BOOKIFY_SUPABASE_DB_PASSWORD:-}"
add BOOKIFY_SUPABASE_DB_SSLMODE "${BOOKIFY_SUPABASE_DB_SSLMODE:-}"

if [ "$dry_run" -eq 1 ]; then
	note ""
	note "dry run: ${#pairs[@]} variables would be passed, no request made."
	[ "${#missing[@]}" -gt 0 ] && note "still missing: ${missing[*]}"
	exit 0
fi

# -------------------------------------------------------------------------- create

command -v render >/dev/null 2>&1 || die "the Render CLI is not on PATH. See docs/DEPLOY-RENDER.md."

if ! render whoami >/dev/null 2>&1; then
	note "error: the Render CLI is not authenticated. Run 'render login' first — it"
	note "       authorises through the browser, so nothing is typed into a shell."
	exit 1
fi

args=(
	--name "$name"
	--type web_service
	--runtime docker
	--repo "$repo"
	--branch "$branch"
	--plan "$plan"
	--region "$region"
)
for pair in "${pairs[@]}"; do args+=( --env-var "$pair" ); done

# Only values that are actually secret are used for masking. Masking the database name
# or the sslmode would rewrite ordinary words in Render's own message — in testing
# "402 payment required" came back as "402 payment ***d" — while protecting nothing.
mask_values=()
for pair in "${pairs[@]}"; do
	key="${pair%%=*}"
	case " $showable " in
		*" $key "*) continue ;;
	esac
	mask_values+=( "${pair#*=}" )
done

if [ "${#mask_values[@]}" -gt 0 ]; then
	export pairs_masked="$(printf '%s\n' "${mask_values[@]}")"
else
	export pairs_masked=""
fi

tmp="$(mktemp)"; chmod 600 "$tmp"
trap 'rm -f "$tmp"' EXIT

note ""
note "creating the service..."

# The response echoes environment variables back, values included, so it is captured
# and never printed directly: on success only the identifying fields below are shown,
# and on failure the message is first filtered through `pairs_masked`.
if ! render services create "${args[@]}" --output json --confirm >"$tmp" 2>&1; then
	note "the create request failed. Render's message follows, with any value this"
	note "script sent masked:"
	note ""
	python3 -c '
import os, sys
values = [p.split("=", 1)[1] for p in os.environ.get("pairs_masked", "").splitlines() if "=" in p]
text = sys.stdin.read()
for value in values:
    if len(value) >= 6:
        text = text.replace(value, "***")
print(text.rstrip())
' < "$tmp"
	exit 1
fi

python3 -c '
import json, os, sys

values = [p.split("=", 1)[1] for p in os.environ.get("pairs_masked", "").splitlines() if "=" in p]
raw = sys.stdin.read()
for value in values:
    if len(value) >= 6:
        raw = raw.replace(value, "***")

# The response is expected to be a bare JSON document, but the CLI also writes progress
# and warnings to the same stream, so fall back to the outermost brace pair rather than
# assuming the whole of stdin is the object.
service = None
for candidate in (raw, raw[raw.find("{"):raw.rfind("}") + 1] if "{" in raw and "}" in raw else ""):
    try:
        service = json.loads(candidate)
        break
    except ValueError:
        continue

if not isinstance(service, dict):
    print(raw.rstrip())
    sys.exit(0)

details = service.get("serviceDetails") or {}
print("  id       {}".format(service.get("id", "?")))
print("  name     {}".format(service.get("name", "?")))
print("  type     {}".format(service.get("type", "?")))
print("  plan     {}".format(details.get("plan") or service.get("plan", "?")))
print("  region   {}".format(details.get("region") or service.get("region", "?")))
print("  url      {}".format(details.get("url") or "assigned once live"))
' < "$tmp"

note ""
note "next:"
note "  render deploys list $name"
note "  render logs $name"
note "  render deploys create $name --wait"

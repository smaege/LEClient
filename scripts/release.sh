#!/usr/bin/env bash
set -euo pipefail

usage() {
	cat <<'EOF'
Usage: scripts/release.sh <patch|minor|major|VERSION> [--message MSG] [--dry-run]

Bump the package version, commit the README update, create an annotated tag, and push.

Requires a clean working directory (no staged, unstaged, or untracked changes).

Examples:
  scripts/release.sh patch
  scripts/release.sh minor
  scripts/release.sh 2.0.0
  scripts/release.sh patch --message "Fix HTTP-01 retry handling"
  scripts/release.sh patch --dry-run

Version is read from the latest v* git tag, falling back to README.md.
Packagist picks up the new version from the pushed tag.
EOF
}

log() {
	printf '==> %s\n' "$*"
}

die() {
	printf 'error: %s\n' "$*" >&2
	exit 1
}

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || die "not inside a git repository"
cd "$ROOT"

DRY_RUN=0
TAG_MESSAGE=""
BUMP=""

while [[ $# -gt 0 ]]; do
	case "$1" in
		-h|--help)
			usage
			exit 0
			;;
		--dry-run)
			DRY_RUN=1
			shift
			;;
		--message|-m)
			[[ $# -ge 2 ]] || die "--message requires a value"
			TAG_MESSAGE="$2"
			shift 2
			;;
		patch|minor|major)
			[[ -z "$BUMP" ]] || die "specify only one of patch, minor, major, or an explicit version"
			BUMP="$1"
			shift
			;;
		*)
			if [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
				[[ -z "$BUMP" ]] || die "specify only one of patch, minor, major, or an explicit version"
				BUMP="$1"
				shift
			else
				die "unknown argument: $1 (run with --help)"
			fi
			;;
	esac
done

[[ -n "$BUMP" ]] || {
	usage
	exit 1
}

if [[ -n "$(git status --porcelain)" ]]; then
	die "working directory is not clean; commit or stash changes before releasing"
fi

current_version_from_tag() {
	local tag
	tag="$(git tag -l 'v*' --sort=-v:refname | head -n 1 || true)"
	if [[ -n "$tag" ]]; then
		printf '%s' "${tag#v}"
	fi
}

current_version_from_readme() {
	local readme="$ROOT/README.md"
	[[ -f "$readme" ]] || return 0
	grep -E '^The current version is [0-9]+\.[0-9]+\.[0-9]+$' "$readme" \
		| head -n 1 \
		| sed -E 's/^The current version is //'
}

CURRENT="$(current_version_from_tag)"
if [[ -z "$CURRENT" ]]; then
	CURRENT="$(current_version_from_readme)"
fi
[[ -n "$CURRENT" ]] || die "could not determine the current version (no v* tag and no README version line)"

IFS='.' read -r MAJOR MINOR PATCH <<<"$CURRENT"
[[ -n "${MAJOR:-}" && -n "${MINOR:-}" && -n "${PATCH:-}" ]] \
	|| die "current version '$CURRENT' is not valid semver (expected X.Y.Z)"

case "$BUMP" in
	patch)
		PATCH=$((PATCH + 1))
		NEW_VERSION="${MAJOR}.${MINOR}.${PATCH}"
		;;
	minor)
		MINOR=$((MINOR + 1))
		NEW_VERSION="${MAJOR}.${MINOR}.0"
		;;
	major)
		MAJOR=$((MAJOR + 1))
		NEW_VERSION="${MAJOR}.0.0"
		;;
	*)
		NEW_VERSION="$BUMP"
		;;
esac

TAG="v${NEW_VERSION}"
README="$ROOT/README.md"
[[ -f "$README" ]] || die "README.md not found"

if git rev-parse "$TAG" >/dev/null 2>&1; then
	die "tag $TAG already exists"
fi

if ! grep -qE "^The current version is ${CURRENT}\$" "$README"; then
	die "README.md does not contain the expected version line for $CURRENT"
fi

BRANCH="$(git branch --show-current)"
REMOTE="$(git remote | head -n 1 || true)"
[[ -n "$REMOTE" ]] || die "no git remote configured"

if [[ -z "$TAG_MESSAGE" ]]; then
	TAG_MESSAGE="Release ${NEW_VERSION}"
fi

run() {
	if [[ "$DRY_RUN" -eq 1 ]]; then
		printf '[dry-run] '
		printf '%q ' "$@"
		printf '\n'
	else
		"$@"
	fi
}

log "current version: $CURRENT"
log "new version:     $NEW_VERSION"
log "tag:             $TAG"
log "branch:          ${BRANCH:-'(detached HEAD)'}"
log "remote:          $REMOTE"

if [[ "$DRY_RUN" -eq 1 ]]; then
	log "dry run only; no files, commits, tags, or pushes will be made"
	exit 0
fi

if sed --version >/dev/null 2>&1; then
	sed -i -E "s/^The current version is ${CURRENT}\$/The current version is ${NEW_VERSION}/" "$README"
else
	sed -i '' -E "s/^The current version is ${CURRENT}\$/The current version is ${NEW_VERSION}/" "$README"
fi

if [[ "$(git status --porcelain)" != " M README.md" ]]; then
	git checkout -- "$README"
	die "README.md update failed; expected only the version line to change"
fi

run git add README.md
run git commit -m "Release ${NEW_VERSION}"
run git tag -a "$TAG" -m "$TAG_MESSAGE"
run git push "$REMOTE" HEAD
run git push "$REMOTE" "$TAG"

log "released ${NEW_VERSION}"
log "Packagist will pick up ${TAG} after the webhook runs"

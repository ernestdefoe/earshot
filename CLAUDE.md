# Earshot: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.**
- Commit subjects are conventional (`feat:`, `fix:`, `docs:`, `chore:`). The release drafter reads the bump from the subject.
- `js/dist` and `css/earshot.css` are committed and are what forums load. After any change under `js/src` or `less/`, run `npm run build` in `js/` (from a fresh `npm ci` before a release) and commit the output; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so. New installs go on dev only.
- Every string is translatable (`locale/en.yml`).
- Earshot Pro (private repo `ernestdefoe/earshot-pro`) builds only on this package's extension points: `app.earshot.onLoad(fn)` in JS and the `sectionItems()` / `actionItems()` ItemLists on `ShowHero` and `EpisodeHero`. Changing those is a breaking change for Pro.

## Design rules that exist for a reason

- **Lean:** `forum.js` (every page) holds only routes, the nav link, the notification and hooks. Pages, heroes, the player and every style are one lazy chunk plus `/earshot/stylesheet` (no `.css` in the route: nginx serves `*.css` itself). Nothing goes in `forum.css`.
- **No `.xml` routes:** the feed is `/podcasts/{slug}/feed`; a stock Flarum nginx config answers `*.xml` itself.
- The `earshot` discussion field is sent only when a single discussion is shown (`$context->showing(...)`), never in lists.
- Episode visibility is its discussion's visibility (`Episode::scopeVisibleTo`): a members-only tag keeps episodes out of lists and out of the public feed.
- The player updates its clock and progress bar straight in the DOM on `timeupdate`; Mithril redraws only on state changes. Never `m.redraw()` from `timeupdate`.
- Every outbound fetch goes through `SafeHttp` (pinned DNS, per-hop checks, byte and time caps). A body over the cap is an error, never a truncated feed.
- A followed feed's first import brings in only the latest `backlog` episodes and notifies nobody; later reads add only items newer than the newest episode here.
- Hosted audio is kept as uploaded but only after its magic bytes prove MP3/M4A/Ogg, under a random name with an extension Earshot picks. Covers are re-encoded.
- Notifications must implement `AlertableInterface` or no alert is ever sent (Flarum 2).
- The Starter sets a new discussion's counts (1 post, 1 participant); core's listeners recount on `Posted`, so counting here only repeated them.

## Testing

- Integration: `DB_DRIVER=sqlite DB_DATABASE=flarum_test DB_PREFIX=es_ composer test:setup` once, then the same env with `composer test:integration`. On this Mac, set `PHP_INI_SCAN_DIR` to an ini with `memory_limit=1G` or isolated tests die at 128M.
- `vendor/bin/phpstan analyse --memory-limit=1G`; `cd js && npm run format-check`.
- flarum/testing fails a request that repeats a query per record: fix the cause, never allowlist without a comment.

# Marmara AI fork modules

Code in `app/Marmara/` belongs to the Marmara Yapay Zeka Kulübü fork, not to
upstream TryPost. Each module keeps its classes, config, commands and tests in
its own files, so an upstream merge only meets the touch points listed below.

The module lives under `app/` (namespace `App\Marmara\...`) rather than as a
composer path package: `App\` is already autoloaded, so nothing changes in
`composer.json` or `composer.lock`, two files upstream edits all the time.

## Zernio: public TikTok publishing

TikTok refused the club's own TikTok app for production ("personal or internal
use not eligible"), so TryPost's native Content Posting API publishing can only
post privately. This module publishes TikTok posts through
[Zernio](https://docs.zernio.com) (formerly Late / getlate.dev), whose TikTok
app is audited for public posting.

### Files

| Path | What |
| --- | --- |
| `app/Marmara/Zernio/ZernioServiceProvider.php` | Rebinds `TikTokPublisher` in the container, registers the commands |
| `app/Marmara/Zernio/ZernioTikTokPublisher.php` | Extends `TikTokPublisher`; publishes mapped accounts through Zernio, the rest natively |
| `app/Marmara/Zernio/ZernioTikTokPayload.php` | TryPost TikTok meta to Zernio `tiktokSettings` |
| `app/Marmara/Zernio/ZernioClient.php` | HTTP client (Bearer API key) |
| `app/Marmara/Zernio/ZernioConfig.php` | Reads `config/marmara-zernio.php`, account mapping, config checks |
| `app/Marmara/Zernio/ZernioPublishGuard.php` | Checks a Zernio-mapped channel by its Zernio account health (cached 5 min) instead of the native token; lets its video id resolve without the native connection |
| `app/Marmara/Zernio/ZernioTikTokCreatorInfo.php` | Extends `TikTokCreatorInfo`: the composer's TikTok options of a mapped channel come from Zernio |
| `app/Marmara/Zernio/Models/ZernioPublication.php` + `database/migrations/` | One row per TryPost post: the current Zernio attempt (loaded by the provider with `loadMigrationsFrom`) |
| `app/Marmara/Hooks/SocialAccountPublishGuard.php`, `PublishGuardVerdict.php` | The publish job's channel-check hook; the default keeps upstream behaviour |
| `app/Marmara/Zernio/Exceptions/ZernioPublishException.php` | A `SocialPublishException`, so the publish job fails, waits or reports it like a native refusal |
| `app/Marmara/Zernio/Console/*` | `marmara:zernio:accounts`, `marmara:zernio:test`, `marmara:zernio:release {post}`, `marmara:zernio:resolve-video-ids` (scheduled every 15 min by the provider), `marmara:zernio:analytics-backfill` |
| `app/Marmara/Zernio/Analytics/ZernioTikTokAnalytics.php` | Reads a TikTok account's post list, post metrics and followers from Zernio's stored analytics; maps metrics |
| `app/Marmara/Zernio/Analytics/ZernioAnalyticsResponse.php` | Zernio HTTP answers as `AnalyticsCollectionException` categories (429 with Retry-After / `details.retryAfterSeconds`) |
| `app/Marmara/Zernio/Analytics/Collectors/*` | Subclasses of TryPost's three TikTok collectors (publications, metrics, followers), rebound by the provider |
| `config/marmara-zernio.php` | Module config (env below) |
| `tests/Feature/Marmara/Zernio/*` | Pest tests |
| `marmara/zernio.env.example` | Env block for the stage/production `.env` |

Tests sit in `tests/Feature/Marmara/` rather than `tests/Marmara/`: that way
`phpunit.xml` and `tests/Pest.php` (the `Feature` suite, `RefreshDatabase`,
`Http::preventStrayRequests()`) cover them without edits to either file.

Migration: `marmara_zernio_publications` (`post_id` unique, cascades with the
post; `zernio_account_id`, `zernio_post_id`, `idempotency_key`,
`first_attempt_at`, `last_attempt_at`, `last_status`, `last_error`,
timestamps). It lives in the module (`app/Marmara/Zernio/database/migrations`)
and runs with `php artisan migrate` like the others. The attempt is kept here,
not in `posts.error_context`, because `posts:retry` and the publish job rewrite
that column.

### Upstream touch points

| File | Lines | Change |
| --- | --- | --- |
| `bootstrap/providers.php` | 5, 14-15 | `use App\Marmara\Zernio\ZernioServiceProvider;` and the provider in the list |
| `app/Jobs/PublishToSocialPlatform.php` | 18, 100-107, 109, 115, 124 | `use App\Marmara\Hooks\SocialAccountPublishGuard;`; in `handle()` a commented call `app(SocialAccountPublishGuard::class)->check($this->post)` that can fail the post, and `$guard->checksNativeConnection &&` in front of the three native checks (disconnected, token expired, missing scopes) |

| `app/Jobs/ResolveTikTokVideoId.php` | 8, 54-57 | `use App\Marmara\Hooks\SocialAccountPublishGuard;`; in `handle()` a commented `requiresConnectedChannel($this->post)` call, and the "channel connected" check only applies when it is true |

Those are the only upstream files edited. On a merge conflict in
`handle()`, keep upstream's checks and put the guard call and the three
`$guard->checksNativeConnection &&` prefixes back. The module also relies, without editing
them, on these upstream contracts. If a merge changes one, re-check the module:

- `app/Jobs/PublishToSocialPlatform.php` `getPublisher()` resolves
  `app(TikTokPublisher::class)` (line ~568). This is the extension point: the
  provider binds `TikTokPublisher` to a closure that returns
  `ZernioTikTokPublisher` when `ZERNIO_ENABLED=true`, else the plain native
  publisher. If upstream ever builds the publisher with `new`, add back a
  container call there and list it here.
- `app/Jobs/ResolveTikTokVideoId.php` `handle(TikTokPublisher $publisher, ...)`
  (line ~50) gets the same binding, so `publicVideoId()` resolves video ids of
  Zernio posts.
- `App\Services\Social\TikTokCreatorInfo` is resolved from the container
  (composer account data, the API creator-info endpoint, the MCP tool,
  repurpose); the provider binds it to `ZernioTikTokCreatorInfo` when enabled.
  It relies on `fetch()` / `fetchOrFail()` and the returned array shape.
- `App\Services\Social\TikTokPublisher`: not final, `publish(Post): array`,
  `publicVideoId(Post): ?string`, `static postUrl()`, the protected
  `validateContentLength()` from `HasSocialHttpClient`.
- The publish result contract: `['id' => ..., 'url' => ...]`,
  `PlatformUnavailableException` (with `retryDelaySeconds`/`maxRetries`) for
  "check again later", `SocialPublishException` for failures.
- `Post::awaitsTikTokVideoId()`: a public post whose `platform_post_id` is not
  all digits is asked about again by `ResolveTikTokVideoId` and the
  `social:resolve-tiktok-video-ids` sweep.

### How it works

1. TryPost's scheduler dispatches `PublishToSocialPlatform` at the post's time,
   exactly as before. Zernio never schedules: every post is created with
   `publishNow: true`.
2. The job resolves `TikTokPublisher`. With the module enabled and the post's
   TikTok account mapped, `ZernioTikTokPublisher` checks the privacy level
   (same rule as native: no default, the user must have picked one), uploads
   the media to Zernio (`POST /media/presign` + `PUT`) or hands over the public
   TryPost URL (`ZERNIO_MEDIA_MODE=url`), and calls `POST /posts` with an
   `Idempotency-Key` and a reference (`tags: ["trypost-{key}"]`,
   `metadata.trypost_reference`) saved in `marmara_zernio_publications` first.
   Before that, the job's channel check asks `SocialAccountPublishGuard`:
   for a mapped channel `ZernioPublishGuard` checks that the Zernio account is
   listed and not disconnected (`GET /accounts`, cached 5 minutes) and skips
   the native token/scope checks. If Zernio cannot be reached the check passes
   and the publisher reports the outage.
3. Every later publish of the same post (job retry, `posts:retry`, retry from
   the app) reads the attempt first:
   - it has a Zernio post id: only `GET /posts/{id}` is asked, never a new
     create; published is recorded as published, failed fails the post (and
     marks the attempt failed, so the next retry starts a new attempt).
   - the create request got no answer (timeout, 5xx, idempotency conflict):
     it is sent again with the same key while `first_attempt_at` (set when the
     first request left, never moved) is under 23 hours old. Older, it is not
     sent again: the post is looked up with `GET /posts?tags={reference}` (then
     `updatedSince` + metadata); found, it is adopted; not found, the post fails
     with "Needs a manual check in Zernio" and stays blocked until an operator
     runs `php artisan marmara:zernio:release {post id}` after checking Zernio.
   - the attempt failed definitively (Zernio failed it, or refused the create
     with a 4xx): a new attempt with a new key and reference.
4. Zernio's answer for the TikTok target:
   - `published`: the post is marked published with the TikTok video id and
     `https://www.tiktok.com/@{username}/video/{id}`, like the native
     publisher. If TikTok has not given the video id yet, the post keeps
     `zernio:{zernio post id}` and the profile URL; `ResolveTikTokVideoId`
     (one minute later, then the 15-minute sweep) asks Zernio
     (`GET /posts/{id}`) and moves it to the video id and URL.
   - `pending`/`processing`/`uploading`: `PlatformUnavailableException`; the
     job puts the post in Retrying and checks `GET /posts/{id}` every
     `ZERNIO_STATUS_POLL_SECONDS` until `ZERNIO_STATUS_WINDOW_MINUTES`, then
     fails it.
   - `failed`/`cancelled` or a 4xx: `ZernioPublishException` with Zernio's
     message; the post fails (category from Zernio's `errorCategory` / HTTP
     status) and the attempt is marked failed, so a retry creates a new one.
     A 429 makes the post wait for the limit like any network limit; a 5xx or
     timeout is retried with the same idempotency key.
5. TryPost's own state, `post.published`/`post.failed` webhooks, emails,
   analytics publication rows and retries run as for any other publish,
   as for any publish.

TikTok settings sent (`tiktokSettings`, see
https://docs.zernio.com/platforms/tiktok/posts): `privacy_level`,
`allow_comment` (meta `allow_comments`), video `allow_duet`, `allow_stitch`,
`video_cover_timestamp_ms` (cover frame), `is_aigc` (only when on; Zernio does
not document it, see below), photo `media_type: photo`, `photo_cover_index: 0`,
`description` (the caption), `auto_add_music`; `brand_organic_toggle` /
`brand_content_toggle` become `commercialContentType` `brand_organic` /
`brand_content` (both: `isBrandOrganicPost` + `brandPartnerPromote`);
`content_preview_confirmed` and `express_consent_given` are `true` because the
composer shows TikTok's preview and declaration before a post can be scheduled.

Zernio's TikTok Business app connections (every new connection) publish
**videos only as `PUBLIC_TO_EVERYONE`**; a video set to friends/followers/only
me is refused by Zernio and the post fails with its message. Photo posts take
every level the creator allows.

### The native TikTok connection

Keep the TikTok channel in TryPost (Channels): the post needs a TikTok
channel, and its username builds the post URL. Publishing does not depend on
its native token: the Zernio path never refreshes or uses it, and through the
guard hook the job no longer blocks a mapped channel that is `disconnected`,
`token_expired` or missing `video.publish`; Zernio's account health decides.
A failed native token refresh (scheduled refresh, `RefreshExpiringTokens`)
may still mark the channel expired and email about it, but it does not fail
the Zernio publish.

The TikTok video id of a Zernio post resolves without the native connection
too: `ResolveTikTokVideoId` skips the "channel connected" check for a mapped
channel (hook above), and since upstream's `social:resolve-tiktok-video-ids`
sweep only selects connected channels, the module schedules
`marmara:zernio:resolve-video-ids` every 15 minutes for Zernio posts
(`platform_post_id` `zernio:…`, public, last 30 days) whose native channel is
not connected.

### Composer options (creator info)

The editor's TikTok privacy and interaction options come from
`TikTokCreatorInfo`. For a mapped channel `ZernioTikTokCreatorInfo` reads
`GET /v1/accounts/{id}/tiktok/creator-info?mediaType=video`
(https://docs.zernio.com/accounts/get-tiktok-creator-info) and maps it to the
same shape: `privacyLevels[].value` to `privacy_level_options` (empty when
`creator.canPostMore` is false), `postingLimits.interactionSettings.allow_*.enabled
=== false` to `comment_disabled` / `duet_disabled` / `stitch_disabled`,
`postingLimits.maxVideoDurationSec`, `creator.nickname` / `avatarUrl`, and the
TryPost username. Cached 5 minutes like upstream. A 429 (daily limit) is
reported like the native one. If Zernio cannot be asked (timeout, 5xx, key
refused), the fallback offers only `PUBLIC_TO_EVERYONE` with every switch
enabled, not cached: Zernio's Business app connections publish videos public
only, so that option is always valid. The native app's SELF_ONLY-only answer
is never used for a mapped channel.

### Enable on stage

1. In the Zernio dashboard, create an API key (https://zernio.com/dashboard/api-keys)
   and connect the club's TikTok account to a Zernio profile (dashboard, or
   `GET /v1/connect/tiktok?profileId=...` and open the returned `authUrl`).
2. On the VM, add the block from `marmara/zernio.env.example` to
   `/opt/trypost-marmara/.env` (production: `/opt/trypost-marmara-prod/.env`),
   leaving `ZERNIO_ENABLED=false` at first.
   If the VM's compose file passes variables one by one under `environment:`
   instead of `env_file: .env`, add the `ZERNIO_*` lines there as well.
3. Recreate the app container so it reads the env (`docker compose up -d
   --force-recreate app`; the entrypoint runs `config:cache` and Horizon runs
   in the same container), then:
   `docker compose exec app php artisan marmara:zernio:accounts` and copy the
   TikTok account's Zernio id into `ZERNIO_TIKTOK_ACCOUNT_ID`; set
   `ZERNIO_TIKTOK_TRYPOST_ACCOUNT` to the TikTok username in TryPost (or the
   TryPost channel id).
4. Set `ZERNIO_ENABLED=true`, recreate the container, run
   `php artisan marmara:zernio:test` (checks the key, the mapping and that the
   mapped account is connected in Zernio).
5. Publish a test post to TikTok from TryPost with privacy "Everyone".

To switch back: `ZERNIO_ENABLED=false` and recreate the container.

### Not verified against the live API

Written from Zernio's docs (November 2026) and tested with `Http::fake` only:

- The exact shape of `GET /v1/posts/{id}` and `GET /v1/accounts` items (docs
  leave the schemas empty); the code reads `platformPostId`, `platformPostUrl`
  or `publishedUrl`, `accountId` as an id or object, `_id` or `id`.
- Whether a TikTok target's `platformPostId` is the public video id once
  published (the code only trusts an all-digit id or a `/video/{id}` URL).
- `is_aigc`: TikTok's AI-generated label is not in Zernio's TikTok docs; it is
  sent only when the user turned it on. If Zernio rejects it, drop it in
  `ZernioTikTokPayload`.
- Photos wider than 1080px: the native publisher resizes them; this module
  sends them as they are and relies on Zernio.
- `GET /posts?tags=` and `updatedSince` on the list endpoint, and that
  `tags`/`metadata` come back on reads, are taken from the docs; if the lookup
  fails the post goes to "manual check", never to a resend.
- The Zernio account fields used for health (`status`, `isActive`,
  `needsReconnect`) are guesses: the docs leave the account schema empty. An
  account that is listed and not visibly disconnected counts as healthy.
- Zernio webhooks (`post.published`, `post.failed`, `post.tiktok.url_resolved`,
  signed with `X-Zernio-Signature`) are not consumed: polling covers status.
  A receiver could replace polling later.
- Analytics: that the list rows of `GET /v1/analytics` carry
  `platforms[].platformPostId` for TikTok (the OpenAPI spec types it; the
  code falls back to the `/video/{id}` in `platformPostUrl`), how far back
  Zernio synced the club's native TikTok posts (spec: "up to the last ~12
  months per account"), and that the plan includes analytics (a 402/403
  "analytics add-on required" is recorded as `permission`).

### Analytics (TikTok through Zernio)

TikTok refused the club's app the `video.list` and `user.info.stats`
scopes, so TryPost's own TikTok collectors stop at the scope gate (history
"provider limited", metrics and followers refused as `permission`). For a
mapped TikTok channel the module reads the same data from Zernio, which also
syncs the posts the club published directly on TikTok, before or without
Zernio:

| TryPost collector (rebound in the container) | Zernio endpoint |
| --- | --- |
| `TikTokPublicationCollector` (discovery + backfill) | `GET /v1/analytics?platform=tiktok&accountId=…&source=all&fromDate=…&sortBy=date&order=desc&limit=50&page=N` |
| `TikTokPublicationMetricsCollector` | the metrics of the last history page that listed the post (cached `ZERNIO_ANALYTICS_CACHE_MINUTES`, 6 h), else `GET /v1/analytics?postId={video id or Zernio post id}&accountId=…` |
| `TikTokFollowerCollector` | `GET /v1/accounts/follower-stats?accountIds=…&granularity=daily` (`currentFollowers`) |

Each subclass checks the account first: an unmapped TikTok channel, or the
module off (`ZERNIO_ENABLED=false`) or analytics off
(`ZERNIO_ANALYTICS_ENABLED=false`), runs TryPost's native code unchanged,
scope gates included. Nothing upstream is edited for this: the factories
(`PublicationHistoryCollectorFactory`, `PublicationMetricsCollectorFactory`,
`FollowerCollectorFactory`) resolve every collector with `app(...)`, and
`CollectPublicationMetrics` checks `instanceof TikTokPublicationMetricsCollector`
before asking `publicVideoId()` (the subclass resolves a `zernio:{id}` post
through Zernio). If upstream ever builds a collector with `new`, add a
container call there and list it under touch points.

Historical posts flow through the normal pipeline: `AdvanceAnalyticsSyncState`
upserts them as external publications, `QueuePublicationMetricsForPage`
queues their first read, and `ImportExternalPosts` turns the newest
`EXTERNAL_POSTS_IMPORT_LIMIT` unlinked ones into Sent posts (origin
`network`, cover from Zernio's non-expiring `thumbnailUrl`). The schedule is
TryPost's own (discovery every `PUBLICATION_DISCOVERY_INTERVAL_HOURS`,
metrics and followers daily). Zernio syncs TikTok about hourly and caches
post analytics 60 minutes, so nothing is gained by reading more often.

Metric mapping (lifetime counts): `views` → views, `likes` → reactions,
`comments`, `shares`; engagements are derived like the native collector
(reactions + comments + shares + saves). Only reported by Zernio's TikTok for
Business connection, and left out while 0: `impressions`, `reach`, `saves`,
`follows`, `profileViews` → profile visits, `igReelsVideoViewTotalTime` →
watch time ms, `igReelsAvgWatchTime` → average watch time ms.

One-off history import (the account's backfill state is restarted from
`--days` ago and run in the command, page by page, then up to
`ZERNIO_ANALYTICS_IMPORT_LIMIT` (500) publications are imported into Sent
and Zernio's daily follower counts fill the days TryPost has no reading of):

    php artisan marmara:zernio:analytics-backfill --days=365

Options: `--account=marmaraai`, `--import-limit=N`, `--skip-followers`. A
backfill that the native collector ended as "provider limited" is restarted
the same way. Zernio keeps about 12 months of synced posts and allows 366
days per request; `--days` beyond that ends provider limited. Imports also
respect `POST_HISTORY_RETENTION_DAYS`.

Rate limits: Zernio caps stored analytics reads per team (300 requests per
30-second window, scope `post-analytics`). A 429 becomes `rate_limited` with
its `Retry-After` (or `details.retryAfterSeconds`), which TryPost's jobs
retry at that time; the command waits and retries up to 5 times. A history
page is one request for 50 posts, and metric reads mostly come from its cache.

The jobs still only run for channels whose TryPost status is `connected`
(upstream's `connected()` scope): keep the native TikTok channel connected.


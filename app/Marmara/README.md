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
| `app/Marmara/Zernio/ZernioPublishGuard.php` | Checks a Zernio-mapped channel by its Zernio account health (cached 5 min) instead of the native token |
| `app/Marmara/Zernio/Models/ZernioPublication.php` + `database/migrations/` | One row per TryPost post: the current Zernio attempt (loaded by the provider with `loadMigrationsFrom`) |
| `app/Marmara/Hooks/SocialAccountPublishGuard.php`, `PublishGuardVerdict.php` | The publish job's channel-check hook; the default keeps upstream behaviour |
| `app/Marmara/Zernio/Exceptions/ZernioPublishException.php` | A `SocialPublishException`, so the publish job fails, waits or reports it like a native refusal |
| `app/Marmara/Zernio/Console/*` | `marmara:zernio:accounts`, `marmara:zernio:test`, `marmara:zernio:release {post}` |
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
  (line ~49) gets the same binding, so `publicVideoId()` resolves video ids of
  Zernio posts.
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

One upstream path still needs the native channel `connected`:
`ResolveTikTokVideoId` and the `social:resolve-tiktok-video-ids` sweep only
look at posts whose channel is connected. A post published while TikTok had
not reported the video id keeps the profile URL until the channel is
reconnected (the publish itself is unaffected).

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

### Analytics (later)

Out of scope now. TryPost's TikTok analytics collectors call TikTok with the
native token, which the club's unaudited app may not be allowed to use for
public data. Zernio has an analytics add-on (`hasAnalyticsAccess` on
`GET /v1/accounts`, `analytics.synced` webhook). A later module could add a
collector that reads Zernio's per-post analytics for posts whose
`platform_post_id` is a TikTok video id (or `zernio:{id}`) and writes them to
TryPost's analytics publication rows, bound the same way (container binding of
the TikTok collector, listed here as a touch point).

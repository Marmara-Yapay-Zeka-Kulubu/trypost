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
| `app/Marmara/Zernio/Exceptions/ZernioPublishException.php` | A `SocialPublishException`, so the publish job fails, waits or reports it like a native refusal |
| `app/Marmara/Zernio/Console/*` | `marmara:zernio:accounts`, `marmara:zernio:test` |
| `config/marmara-zernio.php` | Module config (env below) |
| `tests/Feature/Marmara/Zernio/*` | Pest tests |
| `marmara/zernio.env.example` | Env block for the stage/production `.env` |

Tests sit in `tests/Feature/Marmara/` rather than `tests/Marmara/`: that way
`phpunit.xml` and `tests/Pest.php` (the `Feature` suite, `RefreshDatabase`,
`Http::preventStrayRequests()`) cover them without edits to either file.

No migration: the in-flight state is a `marmara_zernio` key in
`posts.error_context`, next to TryPost's own publish checkpoints.

### Upstream touch points

| File | Lines | Change |
| --- | --- | --- |
| `bootstrap/providers.php` | 5, 14-15 | `use App\Marmara\Zernio\ZernioServiceProvider;` and the provider in the list |

That is the only upstream file edited. The module also relies, without editing
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
   `Idempotency-Key` saved in `posts.error_context.marmara_zernio` first, so a
   worker that dies mid-request does not post twice.
3. Zernio's answer for the TikTok target:
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
     status), the checkpoint is dropped, so a retry creates a new Zernio post.
     A 429 makes the post wait for the limit like any network limit; a 5xx or
     timeout is retried with the same idempotency key.
4. TryPost's own state, `post.published`/`post.failed` webhooks, emails,
   analytics publication rows and retries run as for any other publish,
   because the job is unchanged.

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

Keep the TikTok account connected in TryPost (Channels): the post needs a
TikTok channel, and the account's username builds the post URL. The Zernio
path never uses the native access token: it does not refresh it and does not
call TikTok. But the publish job (upstream, not changed on purpose) still
refuses to publish when the channel is `disconnected` or `token_expired`, or
lacks the `video.publish` scope, before any publisher runs. So if TryPost marks
the native TikTok connection expired, reconnect it natively; Zernio publishing
resumes. Making the Zernio path independent of the native token would mean
editing `PublishToSocialPlatform::handle()`, which this module avoids.

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
- A manual `posts:retry` drops the Zernio checkpoint, so it creates a new Zernio
  post even if the old one is still processing.
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

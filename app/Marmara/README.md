# Marmara AI fork modules

Code in `app/Marmara/` belongs to the Marmara Yapay Zeka Kulübü fork, not to
upstream TryPost. Each module keeps its classes, config, commands and tests in
its own files, so an upstream merge only meets the touch points listed below.

The module lives under `app/` (namespace `App\Marmara\...`) rather than as a
composer path package: `App\` is already autoloaded, so nothing changes in
`composer.json` or `composer.lock`, two files upstream edits all the time.

Modules: [Zernio](#zernio-tiktok-and-linkedin-page-publishing-and-analytics)
(TikTok and LinkedIn page publishing),
[LinkedIn](#linkedin-native-linkedin-page-analytics) (LinkedIn page analytics
through LinkedIn's own API) and
[Portal](#portal-default-language-and-guest-branding) (Turkish by default,
club frame on the auth screens) and
[Inbox](#inbox-comments-and-dms-in-one-place) (comments and DMs of every
channel, with replies, for the team and for Echo / Hermes). Each lists its own
upstream touch points.

## Upstream merges

Last upstream merge: v2.1.0 (tag `b569076f`, merged November 19, 2026; the
previous base was upstream `main` at `e1c984d4`, v2.0.1 + 11 commits).

### Fork changes outside `app/Marmara`

Besides the module touch points below, the fork carries features written
straight into upstream files (WhatsApp approval bridge, approval webhooks,
"ask for feedback"). These are where upstream merges conflict:

| File | What the fork adds |
| --- | --- |
| `lang/*/notes.php` | `feedback_requested`, `feedback_requested_with_note` at the end of the file |
| `lang/*/posts.php` | `approvals.errors.changed`, `approvals.errors.request_conflict`, the `feedback` block at the end |
| `lang/*/webhooks.php` | `post_approval_requested`, `post_approved`, `post_rejected`, `post_feedback_requested` (and the inbox event) in `events` and `event_descriptions` |
| `app/Enums/Webhook/EventType.php`, `resources/js/components/webhook/webhook-events.ts` | The approval, feedback and inbox events |
| `routes/app.php`, `routes/api.php` | `posts/{post}/feedback-requests` routes, after the post note routes |
| `resources/js/components/publish/PostCardMenu.vue` | `feedback` prop and the "Ask for feedback" item (`request_feedback`) |
| `resources/js/composables/publish/usePostCardMenuActions.ts` | `request_feedback` case, `feedbackOpen` / `feedbackPost` (the menu actions moved here from `PostTimelineCard.vue` in v2.1.0) |
| `resources/js/components/publish/PostTimelineCard.vue`, `board/PostBoardCard.vue` | `PostFeedbackRequestDialog` next to the recurrence dialog, `feedback` on `PostDetailsDialog` |
| `resources/js/components/publish/PostDetailsDialog.vue`, `resources/js/types/publish.ts` | `feedback` prop, `request_feedback` action |
| `tests/fixtures/single-line-copy.php` | Length budgets of the fork's webhook descriptions and feedback copy |

### How to take an upstream release

1. `git remote add upstream https://github.com/trypostit/trypost.git` (once),
   `git fetch upstream --tags`. If the clone is shallow, run
   `git fetch --unshallow upstream` first, or the merge base is wrong.
2. `git merge-base HEAD vX.Y.Z` and `git describe --tags <base>`; list
   `git log <base>..HEAD` (ours) and `git log HEAD..vX.Y.Z` (theirs). Part of
   a release may already be in the base when the fork last merged `main`.
3. `git merge --no-ff vX.Y.Z -m "Merge TryPost vX.Y.Z"` (never rebase).
   On a conflict take upstream's side and put the fork's lines back; the
   tables above and below say which.
4. New locale upstream (`id` arrived in v2.1.0): copy every fork key into it.
   Compare each `lang/<locale>/*.php` key set with `lang/en` and check the
   budgets in `tests/fixtures/single-line-copy.php` for the new locale.
5. Check `bootstrap/providers.php` and `bootstrap/app.php` upstream: the
   servers bind-mount their own copies, so any provider or middleware change
   has to be made there too.
6. Look for new migrations (`git diff --name-status <base> vX.Y.Z --
   database/migrations`) and compare names and tables with
   `app/Marmara/*/database/migrations`.
7. Read `.env.example`, `config/*.php`, `config/horizon.php`,
   `routes/console.php` diffs for new env keys, queues and scheduled commands.
8. Grep the touch points (`git grep -n -i marmara -- ':!app/Marmara'`) and
   update the line numbers in this file.
9. `php -l` and `vendor/bin/pint --test` on changed PHP files, grep for
   conflict markers; the full Pest and Vitest suites run in CI.

## Zernio: TikTok and LinkedIn page publishing and analytics

TikTok refused the club's own TikTok app for production ("personal or internal
use not eligible"), so TryPost's native Content Posting API publishing can only
post privately. This module publishes TikTok posts through
[Zernio](https://docs.zernio.com) (formerly Late / getlate.dev), whose TikTok
app is audited for public posting. The club's LinkedIn app has no Community
Management API access either, so a LinkedIn company page can be published
(and measured) through Zernio the same way. Each channel is switched by its
own mapping (see "Per-platform switch" below).

### Files

| Path | What |
| --- | --- |
| `app/Marmara/Zernio/ZernioServiceProvider.php` | Rebinds `TikTokPublisher` in the container, registers the commands |
| `app/Marmara/Zernio/ZernioTikTokPublisher.php` | Extends `TikTokPublisher`; publishes mapped accounts through Zernio, the rest natively |
| `app/Marmara/Zernio/ZernioLinkedInPagePublisher.php` | Extends `LinkedInPagePublisher`; same for mapped LinkedIn company pages |
| `app/Marmara/Zernio/Publishing/ZernioPostFlow.php` | The shared publish flow: attempt row, Idempotency-Key, reference tag, status polling, lost-create recovery |
| `app/Marmara/Zernio/Publishing/ZernioChannel.php` + `ZernioTikTokChannel.php`, `ZernioLinkedInPageChannel.php` | Per-platform payload (media rules, settings) and result (post id, URL) |
| `app/Marmara/Zernio/Publishing/ZernioMediaHost.php` | Media URL for Zernio: TryPost's public URL or a presigned upload |
| `app/Marmara/Zernio/ZernioTikTokPayload.php` | TryPost TikTok meta to Zernio `tiktokSettings` |
| `app/Marmara/Zernio/ZernioClient.php` | HTTP client (Bearer API key) |
| `app/Marmara/Zernio/ZernioConfig.php` | Reads `config/marmara-zernio.php`, account mapping, config checks |
| `app/Marmara/Zernio/ZernioAccountHealth.php` | Zernio account health (`GET /v1/accounts`, cached 5 min), shared by the guards and the verifier |
| `app/Marmara/Zernio/ZernioConnectionVerifier.php` | Extends `ConnectionVerifier`: a mapped channel's connection checks answer from Zernio |
| `app/Marmara/Hooks/AnalyticsAccountGuard.php`, `app/Marmara/Zernio/Analytics/ZernioAnalyticsAccountGuard.php` | The analytics channel filter hook; the default keeps upstream behaviour |
| `app/Marmara/Zernio/ZernioPublishGuard.php` | Checks a Zernio-mapped channel by its Zernio account health (cached 5 min) instead of the native token; lets its video id resolve without the native connection |
| `app/Marmara/Zernio/ZernioTikTokCreatorInfo.php` | Extends `TikTokCreatorInfo`: the composer's TikTok options of a mapped channel come from Zernio |
| `app/Marmara/Zernio/Models/ZernioPublication.php` + `database/migrations/` | One row per TryPost post: the current Zernio attempt (loaded by the provider with `loadMigrationsFrom`) |
| `app/Marmara/Hooks/SocialAccountPublishGuard.php`, `PublishGuardVerdict.php` | The publish job's channel-check hook; the default keeps upstream behaviour |
| `app/Marmara/Zernio/Exceptions/ZernioPublishException.php` | A `SocialPublishException`, so the publish job fails, waits or reports it like a native refusal |
| `app/Marmara/Zernio/Console/*` | `marmara:zernio:accounts`, `marmara:zernio:test`, `marmara:zernio:release {post}`, `marmara:zernio:resolve-video-ids` (scheduled every 15 min by the provider), `marmara:zernio:analytics-backfill`, `marmara:zernio:link` |
| `app/Marmara/Zernio/Analytics/ZernioPostAnalytics.php` | Reads a TikTok account's post list, post metrics and followers from Zernio's stored analytics; maps metrics |
| `app/Marmara/Zernio/Analytics/ZernioAnalyticsResponse.php` | Zernio HTTP answers as `AnalyticsCollectionException` categories (429 with Retry-After / `details.retryAfterSeconds`) |
| `app/Marmara/Zernio/Analytics/Collectors/*` | Subclasses of TryPost's three TikTok collectors (publications, metrics, followers), and the three LinkedIn page collectors TryPost lacks |
| `app/Marmara/Zernio/Analytics/Factories/*` | Subclasses of TryPost's three collector factories that add LinkedIn pages |
| `app/Marmara/Zernio/Analytics/ZernioAnalyticsPlatformGate.php`, `app/Marmara/Hooks/AnalyticsPlatformGate.php` | The analytics platform hook (below); the default keeps upstream behaviour |
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
| `bootstrap/providers.php` | 8, 17-18 | `use App\Marmara\Zernio\ZernioServiceProvider;` and the provider in the list |
| `app/Jobs/PublishToSocialPlatform.php` | 18, 100-107, 109, 115, 124 | `use App\Marmara\Hooks\SocialAccountPublishGuard;`; in `handle()` a commented call `app(SocialAccountPublishGuard::class)->check($this->post)` that can fail the post, and `$guard->checksNativeConnection &&` in front of the three native checks (disconnected, token expired, missing scopes) |

| `app/Models/SocialAccount.php` | 19, 624-638 | `use App\Marmara\Hooks\AnalyticsAccountGuard;` and a commented `scopeConnectedForAnalytics()`: `connected()`, or `orWhereIn(id, AnalyticsAccountGuard::alsoUsableAccountIds())`. The default guard returns `[]`, i.e. exactly `connected()` |
| `app/Jobs/Analytics/{AbstractPublicationSync,BootstrapAccountAnalytics,CollectPublicationMetrics,CollectAccountDailySnapshot,FinalizeAccountDailySnapshot,FinalizeAccountDailySnapshots}.php`, `app/Console/Commands/Analytics/{DispatchPublicationDiscovery,DispatchPublicationMetrics,DispatchAccountDailyAnalytics}.php`, `app/Actions/Analytics/{DispatchAccountAnalytics,BuildFollowerAnalyticsReport,BuildWorkspaceAnalyticsReport}.php` | one line each | `->connected()` became `->connectedForAnalytics() // Marmara AI fork hook` in the channel query. On a conflict keep upstream's line and swap the scope back in |
| `app/Enums/SocialAccount/Platform.php` | 10, 53-54 | `use App\Marmara\Hooks\AnalyticsPlatformGate;`; in `isIncludedInAnalytics()` a commented arm `self::LinkedInPage => app(AnalyticsPlatformGate::class)->includes($this)` (LinkedInPage taken out of the `false` arm). The default gate answers false, i.e. upstream behaviour |
| `app/Jobs/ResolveTikTokVideoId.php` | 8, 54-58 | `use App\Marmara\Hooks\SocialAccountPublishGuard;`; in `handle()` a commented `requiresConnectedChannel($this->post)` call, and the "channel connected" check only applies when it is true |

Those are the only upstream files edited. On a merge conflict in
`handle()`, keep upstream's checks and put the guard call and the three
`$guard->checksNativeConnection &&` prefixes back. The module also relies, without editing
them, on these upstream contracts. If a merge changes one, re-check the module:

- `app/Jobs/PublishToSocialPlatform.php` `getPublisher()` resolves
  `app(TikTokPublisher::class)` (line ~584). This is the extension point: the
  provider binds `TikTokPublisher` to a closure that returns
  `ZernioTikTokPublisher` when `ZERNIO_ENABLED=true`, else the plain native
  publisher. If upstream ever builds the publisher with `new`, add back a
  container call there and list it here.
- `getPublisher()` resolves `app(LinkedInPagePublisher::class)` (line ~582)
  the same way; the provider binds it to `ZernioLinkedInPagePublisher` when
  `ZERNIO_ENABLED=true`. It relies on `publish(Post): array` and the
  protected `validateContentLength()`.
- `ConnectionVerifier` is resolved from the container by every caller
  (`app(...)` or injection); the provider binds it to
  `ZernioConnectionVerifier` when `ZERNIO_ENABLED=true`. It relies on
  `verify()`, `verifyAccessToken()`, `refreshToken()` and their exceptions.
- The analytics jobs get `PublicationHistoryCollectorFactory`,
  `PublicationMetricsCollectorFactory` and `FollowerCollectorFactory` by
  injection or `app()`, and the factories resolve each TikTok collector with
  `app()`: the provider rebinds all six.
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
no longer marks a mapped channel expired: its connection checks answer from
Zernio (`ZernioConnectionVerifier`, see "Channels without a working native
connection").

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

### Per-platform switch (mapping)

A channel goes through Zernio only while it is in its platform's mapping:

    ZERNIO_ACCOUNTS="tiktok:marmaraai=6af764170fb0c006b7a777e1,linkedin-page:<TryPost channel id or username>=<Zernio id>"

Platforms: `tiktok`, `linkedin-page` (the TryPost platform values; a personal
`linkedin` profile is not supported and is reported by `marmara:zernio:test`).
The TryPost side is the channel id (UUID), the username or the platform user
id. The older `ZERNIO_TIKTOK_TRYPOST_ACCOUNT` + `ZERNIO_TIKTOK_ACCOUNT_ID` and
`ZERNIO_TIKTOK_ACCOUNTS` variables still map TikTok (merged with the
`tiktok:` entries), so production needs no change for TikTok.

A mapped channel publishes through Zernio, is checked by Zernio's account
health instead of its native token, and (with `ZERNIO_ANALYTICS_ENABLED`, on
by default) is measured through Zernio. An unmapped channel of the same
platform stays on TryPost's native path.

Moving one channel back to TryPost's native path (for example LinkedIn, once
LinkedIn approves the club's app for the Community Management API):

1. Reconnect the channel in TryPost (Channels) so its native token has the
   publishing scopes.
2. Remove its entry from `ZERNIO_ACCOUNTS` (leave the others), then recreate
   the app container so the cached config is rebuilt:
   `docker compose up -d --force-recreate app`.
3. Check: `php artisan marmara:zernio:accounts` no longer lists the channel
   under "Mapped to TryPost"; publish a test post.
4. Posts already published through Zernio keep their ids. Scheduled posts go
   out natively from then on. A post that was mid-publish in Zernio when the
   mapping was removed is published natively on its next retry, so check
   `marmara_zernio_publications` for rows with `last_status` `processing` or
   `unknown` before switching.
5. LinkedIn analytics: TryPost has no native LinkedIn collectors. With
   `MARMARA_LINKEDIN_NATIVE_ANALYTICS=true` the LinkedIn module measures the
   page natively from then on (see "LinkedIn: native LinkedIn page
   analytics"); without it, with no `linkedin-page:` entry left LinkedIn
   pages leave analytics again (upstream behaviour). The data already
   collected stays in the tables either way.

`ZERNIO_ENABLED=false` switches every channel back at once.

### LinkedIn company pages

`ZernioLinkedInPagePublisher` (bound for `LinkedInPagePublisher`) sends a
mapped page's post with `POST /v1/posts` (`platform: linkedin`) through the
same flow as TikTok (attempt row, idempotency, polling, manual check). The
payload follows TryPost's native LinkedIn rules
(https://docs.zernio.com/platforms/linkedin):

- media: a document (PDF, PPT(X), DOC(X); Zernio: one per post, up to 100 MB)
  wins, with `documentTitle` from the post's document title or file name;
  else two or more images become a multi-image post (Zernio: up to 20,
  8 MB each, JPEG/PNG/GIF); else the first video (one per post, up to 30
  minutes on an organization page) or image. Alt text is sent as `altText`.
- text: TryPost's LinkedIn text (`ContentSanitizer`); Zernio's 3,000
  character limit is the same as TryPost's. Zernio generates the link
  preview of a text post; a post with the preview turned off sends
  `disableLinkPreview: true`.
- `organizationUrn`: `urn:li:organization:{meta.organization_id}` of the
  TryPost channel, when it has one.
- TryPost's thread reply (published natively as a comment) becomes
  `firstComment`. Zernio takes one text comment, so a post with more than
  one reply, or a reply with media, fails before anything is sent.
- the result: Zernio's `platformPostId` (the post URN, as the native
  publisher stores it) and `platformPostUrl`
  (`https://www.linkedin.com/feed/update/{urn}` when missing); without a URN
  yet, `zernio:{zernio post id}`.

Analytics: TryPost leaves LinkedIn out of analytics
(`Platform::isIncludedInAnalytics()`), which every analytics query, job,
controller and the channel list read. The enum now asks
`AnalyticsPlatformGate` for LinkedIn pages; the module's gate includes them
while a `linkedin-page:` mapping exists and Zernio analytics are on. This is
per platform, not per channel: an unmapped LinkedIn page in the same install
would show in analytics without data (its collectors answer `unsupported`).
The three collector factories are rebound to subclasses that hand LinkedIn
pages to Zernio collectors: history from `GET /v1/analytics?platform=linkedin`
(organization pages report every post, published through Zernio or not),
metrics `impressions` → impressions, `reach` → reach, `likes` → reactions,
`comments`, `shares`, `clicks`, `views` (video, when reported); engagements
derived (reactions + comments + shares + clicks); followers from
`/v1/accounts/follower-stats`. `marmara:zernio:analytics-backfill` covers
LinkedIn pages too.

The TryPost LinkedIn page channel must exist (posts target it). If the
club's LinkedIn app cannot connect the page natively, create the channel
from Zernio with `marmara:zernio:link` (below).

### Channels without a working native connection

A mapped channel does not depend on its native token, for publishing (the
publish guard) nor for analytics or the connection checks:

- `ZernioConnectionVerifier` (bound for `ConnectionVerifier`, which
  `VerifyWorkspaceConnections`, `VerifyUpcomingPostConnections`,
  `RefreshSocialToken` and the publishers resolve from the container)
  answers a mapped channel's `verify()` from Zernio's account health: a
  healthy Zernio account keeps the channel `connected` and brings a
  `token_expired` one back; a Zernio account reported disconnected or
  missing expires it (with TryPost's usual notice). Its native token is
  still refreshed when possible, but a refused refresh no longer expires the
  channel.
- The analytics jobs, dispatchers and reports select channels with
  `SocialAccount::connectedForAnalytics()` instead of `connected()` (hook
  below): `connected`, or a mapped channel whose Zernio account is usable
  (`AnalyticsAccountGuard`, rebound to `ZernioAnalyticsAccountGuard`).
- Zernio's account health (`GET /v1/accounts`, cached 5 minutes) is in
  `ZernioAccountHealth`, shared by the three.

`marmara:zernio:link {zernioAccountId} {--workspace=} {--account=}` creates
the TryPost channel of an account connected only to Zernio (TikTok or a
LinkedIn page): platform from Zernio, `username`, `display_name`,
`avatar_url` (Zernio `profilePicture`, downloaded to TryPost's storage with
`uploadFromUrl()` like a native connect; left empty if the download fails),
`platform_user_id` (Zernio
`platformUserId`, else `zernio:{id}`), `meta.organization_id` for a LinkedIn
page when Zernio reports `urn:li:organization:{id}`, status `connected`, a
placeholder `access_token` (the column is required; it is never used), no
refresh token and no `token_expires_at`, so the token refresh sweep never
selects it and `meta.managed_by=zernio` makes the verifier skip native
refreshes. A LinkedIn account is linked only when Zernio shows it as a company page
(`accountType: organization`, `metadata.organizationInfo`, an
`urn:li:organization:` id or a `/company/` profile URL); a personal profile,
or an account Zernio does not show as a page, is refused. The publish guard
applies the same rule: a `linkedin-page:` mapping whose Zernio account is not
shown as a company page fails the post before anything is sent.
An existing channel (`--account`, or the same username on the
platform in the workspace) is updated and marked connected instead. The
command prints the `ZERNIO_ACCOUNTS` entry to add (it does not edit .env);
the channel is only published and measured through Zernio once that entry
is in place and the container is recreated.

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
- LinkedIn: that Zernio's LinkedIn `platformPostId` is the post URN
  (`urn:li:share:…` / `urn:li:ugcPost:…`, what TryPost's native publisher
  stores) and the `platform` value is `linkedin`; that `organizationUrn`
  is accepted when the Zernio account is the page itself; that follower
  stats cover organization pages; that `platformSpecificData` is accepted
  without other fields (spec types it as a `oneOf`).
- LinkedIn comment on publish (upstream #475, the post's one thread reply):
  `ZernioLinkedInPagePublisher` does not send it, so a page mapped to Zernio
  publishes the post without its comment. Pages on the native publisher get
  it from upstream's `AbstractLinkedInPublisher`.
- GIFs on LinkedIn (upstream v2.1.0, #514): composer validation now lets a
  GIF through for LinkedIn pages, and this module passes it to Zernio with
  its own mime type; whether Zernio keeps it animated is untested.

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

Pending data: a post whose Zernio copy is not synced yet (`syncStatus`
`pending` / `partial`) is not cached from the history page and its metrics
read answers `delayed` (read again later that day, nothing written), so
Zernio's placeholder zeros never become measurements. `unavailable` (Zernio
can never sync it) is `unsupported`, which ends its baseline reads.

A channel whose native token expired is still measured while its Zernio
account is usable (see "Channels without a working native connection").

## LinkedIn: native LinkedIn page analytics

The club's LinkedIn app ("Marmara AI Sosyal") is approved for the Community
Management API (Development tier). This module reads a LinkedIn page's post
metrics, follower count and post history from LinkedIn's versioned REST API
(`https://api.linkedin.com/rest/...`, `LinkedIn-Version: MARMARA_LINKEDIN_API_VERSION`,
`X-Restli-Protocol-Version: 2.0.0`), so the page no longer needs Zernio for
analytics. Publishing is unchanged: TryPost's native `LinkedInPagePublisher`
(or Zernio while the page is mapped). Variables: `marmara/linkedin.env.example`.

### Files

| Path | What |
| --- | --- |
| `app/Marmara/LinkedIn/LinkedInServiceProvider.php` | Wraps the three collector factories and the analytics platform gate with `extend()`, registers the commands |
| `app/Marmara/LinkedIn/LinkedInConfig.php` | Reads `config/marmara-linkedin.php` |
| `app/Marmara/LinkedIn/NativeLinkedInAccounts.php` | The per-channel choice (below) |
| `app/Marmara/LinkedIn/LinkedInApi.php` | HTTP client: headers, short timeouts, token through `AnalyticsAccessToken`, errors as `AnalyticsCollectionException` categories |
| `app/Marmara/LinkedIn/LinkedInCallBudget.php` | Daily call budget (per UTC day) and the back-off after a 429 |
| `app/Marmara/LinkedIn/Analytics/LinkedInPostMetrics.php` | Batched, cached share statistics and video views; the metric mapping |
| `app/Marmara/LinkedIn/Analytics/Collectors/*` | History (posts finder), post metrics, followers; each hands a channel it does not measure to the previous collector |
| `app/Marmara/LinkedIn/Analytics/Factories/*`, `Analytics/LinkedInAnalyticsPlatformGate.php` | Wrappers around whatever the container hands out without this module (upstream's or Zernio's) |
| `app/Marmara/LinkedIn/Console/*` | `marmara:linkedin:test`, `marmara:linkedin:analytics-backfill`, `marmara:linkedin:align-page-id` |
| `app/Marmara/LinkedIn/Connect/BasicProfileLinkedInProvider.php`, `LinkedInBasicProfileExtendSocialite.php` | The `linkedin-openid` Socialite driver without OpenID Connect (`MARMARA_LINKEDIN_PAGE_BASIC_PROFILE`, below) |
| `config/marmara-linkedin.php` | Module config |
| `tests/Feature/Marmara/LinkedIn/*` | Pest tests |
| `marmara/linkedin.env.example` | The .env lines for stage / production |

### Upstream touch points

| File | Lines | Change |
| --- | --- | --- |
| `bootstrap/providers.php` | 6, 21-22 | `use App\Marmara\LinkedIn\LinkedInServiceProvider;` and the provider in the list |

Nothing else upstream is edited. It reuses the Zernio module's hooks
(`AnalyticsPlatformGate` in `Platform::isIncludedInAnalytics()`,
`connectedForAnalytics()`) and relies on these contracts: the analytics jobs
resolve `PublicationHistoryCollectorFactory`, `PublicationMetricsCollectorFactory`
and `FollowerCollectorFactory` from the container (`extend()` wraps them in any
provider order, also around Zernio's rebinds); `AbstractPublicationHistoryCollector::page()`,
`AbstractPublicationMetricsCollector::collect()`, `AbstractFollowerCollector::collect()`;
`AnalyticsAccessToken::for()` (refresh through `ConnectionVerifier`);
`MoveAnalyticsIdentity::handle()`; `LinkedInController` resolving
`Socialite::driver('linkedin-openid')` and reading only `getId()`, `getName()`,
`getAvatar()`, `token`, `refreshToken`, `expiresIn` and `approvedScopes` from
it (no email); upstream registering its `linkedin-openid` extension as a
`SocialiteWasCalled` listener in `AppServiceProvider::boot()`, before this
provider's. Servers that bind-mount their own `bootstrap/providers.php` must
add the provider there too.

### Connecting the page without the OpenID Connect product

The club's LinkedIn app has only the Community Management API product, so
LinkedIn refuses `openid`, `profile` and `email`, which upstream's
`linkedin-openid` driver (Socialite's `LinkedInOpenIdProvider`, member from
`/v2/userinfo`) always asks for. The module listens to `SocialiteWasCalled`
after `AppServiceProvider` and registers `BasicProfileLinkedInProvider` (a
subclass of upstream's provider) as `linkedin-openid`. With
`MARMARA_LINKEDIN_PAGE_BASIC_PROFILE=true`:

- the consent screen asks exactly `r_basicprofile`, `w_organization_social`,
  `r_organization_social`, `rw_organization_admin`, `r_organization_followers`,
  `r_organization_social_feed`, `w_organization_social_feed` (inbox replies,
  see [Inbox](#inbox-comments-and-dms-in-one-place)) (plus the personal profile's own scopes without
  openid/profile/email while `LINKEDIN_ENABLED` is on); `LINKEDIN_PAGE_SCOPES`
  is not used;
- the member comes from `GET /v2/me?projection=(id,localizedFirstName,localizedLastName)`
  (`r_basicprofile`). Its `id` is the same person id the OpenID `sub` carries,
  so `meta.admin_user_id` and existing personal cards keep matching. No email
  is read; TryPost's LinkedIn connect never used one;
- the rest is upstream's `LinkedInController`: the administered pages from
  `/v2/organizationAcls`, LinkedIn's comma-joined `scope` split into
  separate scopes, "Refresh connection" updating the same card.

With the switch off the driver is upstream's, method for method. The personal
profile connect keeps working with the switch on too (identity from
`/v2/me`), but the club runs `LINKEDIN_ENABLED=false`.

### Which channel is measured natively

Per channel, natively when all of these hold, otherwise exactly as before
(Zernio for a mapped page, upstream's "unsupported" for the rest):

1. `MARMARA_LINKEDIN_NATIVE_ANALYTICS=true`;
2. the channel is a LinkedIn page;
3. it is **not** in `ZERNIO_ACCOUNTS` (while `ZERNIO_ENABLED=true`): a mapped
   page stays with Zernio, so the two never measure the same channel;
4. it has `meta.organization_id`;
5. it has a native token, i.e. it was connected through TryPost's LinkedIn
   OAuth (not the `zernio-managed:` placeholder / `meta.managed_by=zernio` of
   `marmara:zernio:link`).

Whether the token still works is not part of the choice: an expired token
that cannot be refreshed, or one LinkedIn refuses (401), is reported as
`authentication`; a missing scope or a member who is not a page
ADMINISTRATOR (403) as `permission`; the history read without
`r_organization_social` ends "provider limited". TryPost's jobs record these
like any other channel and stop, they do not loop.

`MARMARA_LINKEDIN_NATIVE_ANALYTICS` also puts LinkedIn pages in analytics
(`LinkedInAnalyticsPlatformGate`, per platform like Zernio's gate).

### Endpoints and mapping

| TryPost collector | LinkedIn endpoint |
| --- | --- |
| History (discovery + backfill) | `GET /rest/posts?q=author&author=urn:li:organization:{id}&sortBy=LAST_MODIFIED&count=N&start=S` ([Posts API](https://learn.microsoft.com/linkedin/marketing/community-management/shares/posts-api)); cursor = `start`; a post is in the window by `publishedAt` (else `createdAt`), so an old draft published recently is found; paging stops at the first post whose `lastModifiedAt` is before the cutoff; dark (`feedDistribution: NONE`, `adContext.isDsc`) and unpublished posts are left out |
| Post metrics | `GET /rest/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity={org}&shares=List(...)` / `ugcPosts=List(...)` ([share statistics](https://learn.microsoft.com/linkedin/marketing/community-management/organizations/share-statistics)), lifetime |
| Video views | `GET /rest/videoAnalytics?q=entity&entity={ugcPost}&type=VIDEO_VIEW&aggregation=ALL` ([video analytics](https://learn.microsoft.com/linkedin/marketing/community-management/shares/video-analytics-api)), video `ugcPost`s up to 180 days old |
| Followers | `GET /rest/networkSizes/{org}?edgeType=COMPANY_FOLLOWED_BY_MEMBER` → `firstDegreeSize` ([organization lookup](https://learn.microsoft.com/linkedin/marketing/community-management/organizations/organization-lookup-api)) |
| Follower history (backfill only) | `GET /rest/organizationalEntityFollowerStatistics?q=organizationalEntity&organizationalEntity={org}&timeIntervals=(timeRange:(start,end),timeGranularityType:DAY)` ([follower statistics](https://learn.microsoft.com/linkedin/marketing/community-management/organizations/follower-statistics)) |
| Admin check (`marmara:linkedin:test`) | `GET /rest/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED` |

Metric mapping (lifetime counts, the same keys Zernio wrote, so history
continues): `impressionCount` → impressions, `uniqueImpressionsCount` (or the
`uniqueImpressionsCounts` spelling in LinkedIn's samples) → reach,
`clickCount` → clicks, `likeCount` → reactions (all reaction types; never
below 0), `commentCount` → comments, `shareCount` → shares, video
`VIDEO_VIEW` → views; engagements derived (reactions + comments + shares +
clicks), like Zernio's. LinkedIn's `engagement` ratio is kept in the
publication's `provider_metadata.linkedin_engagement`, not stored as a metric
(TryPost derives the rate on read). A post LinkedIn leaves out of the answer
had no activity: every count is 0 (documented behaviour). A field missing from
a returned element is not reported. Posts older than 12 months are refused as
unsupported before any request (LinkedIn's rolling window), and a post id that
is not a `urn:li:share:` / `urn:li:ugcPost:` (a Zernio post still waiting for
its URN) is unsupported too.

### Call budget

Development tier: about 500 calls per app and 100 per member per day, reset
at midnight UTC. Every call takes a unit of `MARMARA_LINKEDIN_DAILY_CALL_BUDGET`
(80) first; a refused unit is `rate_limited` until 00:05 UTC, which TryPost's
jobs wait for. A 429 blocks every call until `Retry-After` (else
`MARMARA_LINKEDIN_BACKOFF_MINUTES`, 60). Timeouts: 5 s connect, 20 s total.

- Share statistics are batched: the first metric read of the day asks for up
  to 50 not-yet-cached posts of the page (one request per URN kind) and
  caches each answer for 12 h, so the daily reads of the 30-day window cost
  1-2 calls.
- Followers: 1 call a day. Discovery: TryPost runs it every 3 h; the post list
  is read at most every 12 h (2 calls a day). Video views: 1 call per video
  post under 30 days old, cached 12 h.
- About 5-10 calls a day for one page: 1-2 % of the app limit, under 10 % of
  the member limit.

### Commands

`php artisan marmara:linkedin:test [--account=] [--posts=5]`: lists the LinkedIn
page channels and whether each is measured natively (or why not), then for a
channel with a native token: refresh token present, granted scopes, the
member's ADMINISTRATOR role on the page, the follower count and the latest
posts with their statistics (about 4 calls). It works while the page is still
mapped to Zernio, so the native side can be checked first. Tokens are never
printed.

`php artisan marmara:linkedin:analytics-backfill [--days=365] [--account=] [--import-limit=] [--skip-followers] [--resume]`:
the same flow as `marmara:zernio:analytics-backfill` (the account's backfill
sync state restarted from `--days` ago, pages through the normal pipeline,
first metric reads queued, the newest `MARMARA_LINKEDIN_IMPORT_LIMIT` unlinked
publications imported into Sent), then the daily follower history: today's
count minus LinkedIn's daily gains, stored as `estimated` for days without a
reading. It stops `MARMARA_LINKEDIN_BACKFILL_RESERVE` (20) calls before the
daily budget; a 429 with a short `Retry-After` (≤ 2 min) is waited out,
anything longer (or the budget) stops it with the cursor kept: run it again
with `--resume` the next UTC day. A year of club posts is about 4 list pages,
4-6 statistics batches, one video read per recent video and 6 follower calls.

`php artisan marmara:linkedin:align-page-id {account} [--dry-run] [--force]`:
see below.

### Moving the LinkedIn page from Zernio to native

The page channel was created by `marmara:zernio:link`: its `platform_user_id`
is what Zernio reported (`platformUserId`, else `zernio:{id}`), it has a
placeholder token and `meta.managed_by=zernio`. TryPost's LinkedIn OAuth stores
a page as `platform_user_id = {organization id}` and finds the channel to
update by `(workspace, platform, platform_user_id)`:

- if the ids match, "Refresh connection" on the card (`?reconnect=`) updates
  that same channel: real token, refresh token when LinkedIn gives one,
  scopes, and `meta` replaced by `organization_id`, `admin_user_id`,
  `admin_name` (the Zernio markers go away). Its posts and analytics stay.
  A plain "Connect" offers the page as already connected (locked).
- if they differ, "Refresh connection" refuses the page (`page_not_found`,
  nothing changes), and a plain "Connect" creates a **second** LinkedIn page
  card, with no history, next to the old one.

So, on production:

1. Add the `marmara/linkedin.env.example` lines (keep
   `MARMARA_LINKEDIN_NATIVE_ANALYTICS=false` for now and the page's
   `ZERNIO_ACCOUNTS` entry in place), recreate the app container.
2. `php artisan marmara:linkedin:align-page-id <channel id> --dry-run`, then
   without `--dry-run`: sets `platform_user_id` to the organization id and
   moves the channel's publications, daily snapshots and sync states with
   TryPost's `MoveAnalyticsIdentity`. It refuses when another card already
   has that id, and when the `ZERNIO_ACCOUNTS` entry matches the channel only
   by its old platform user id (key the entry by the channel id, which
   `marmara:zernio:link` prints, or use `--force`).
3. In TryPost, Channels → the LinkedIn page → "Refresh connection", sign in
   as a page ADMINISTRATOR and finish. The page keeps publishing and being
   measured through Zernio while it is still mapped.
4. `php artisan marmara:linkedin:test`: native token, scopes, ADMINISTRATOR,
   followers and posts must all answer.
5. Check `marmara_zernio_publications` for rows with `last_status`
   `processing` / `unknown` (see "Per-platform switch"), then remove the
   `linkedin-page:` entry from `ZERNIO_ACCOUNTS`, set
   `MARMARA_LINKEDIN_NATIVE_ANALYTICS=true`, recreate the container. From now
   on `ZernioLinkedInPagePublisher` hands the page to the native publisher
   (`meta.organization_id`, the native token; the publish guard checks the
   native status, token and `w_organization_social` again),
   `ZernioConnectionVerifier` verifies and refreshes it natively, and the
   native collectors measure it.
6. Optionally `php artisan marmara:linkedin:analytics-backfill --days=365`
   for posts Zernio did not have; existing publications are matched by URN,
   not duplicated.

If a plain "Connect" already created a second card: remove that new card
(Channels → Disconnect; it deletes only its own posts), run step 2, then step 3
on the old card.

Tokens: LinkedIn access tokens live 60 days. TryPost stores the refresh token
LinkedIn returns and `ConnectionVerifier` refreshes with it
(`grant_type=refresh_token`). LinkedIn documents programmatic refresh tokens
(365 days, not extended by refreshing) for approved Marketing Developer
Platform partners; whether a Community Management API Development tier app
gets one is shown by `marmara:linkedin:test` ("Refresh token: yes/no"). Without
one the channel turns `token_expired` when the token runs out (TryPost's usual
notice) and needs "Refresh connection" every 60 days; analytics then answer
`authentication` until it is reconnected.

### Not verified against the live API

Written from LinkedIn's docs (November 2026, version 202610) and tested with
`Http::fake` only: the `List(...)` encoding for `ugcPosts` (the docs show only
the Rest.li 1.0 index form), mixing is avoided by one request per URN kind;
the batch size LinkedIn accepts (not documented, 50 by default); which scope
`networkSizes` needs; whether the follower statistics finder pages its daily
elements (the code follows `paging.total`); whether the posts finder lists the
page's reshares (kept, with `provider_metadata.linkedin_reshare`).

## Portal: default language and guest branding

Makes the panel open in Turkish and gives the auth screens (login, register,
forgot / reset password, verify email, invites, and the other pages upstream
renders with `layouts/AuthLayout.vue`) the club's own frame. Both are switched
by env; with `APP_LOCALE=en` and `MARMARA_BRANDING=false` TryPost behaves
exactly like upstream. Variables: `marmara/portal.env.example`.

### Files

| Path | What |
| --- | --- |
| `app/Marmara/Portal/PortalServiceProvider.php` | Binds `SetLocale` to `SetPortalLocale`, shares the `marmara` Inertia prop |
| `app/Marmara/Portal/SetPortalLocale.php` | Subclass of upstream `SetLocale`: runs it unchanged, then switches a guest request to `APP_LOCALE` |
| `app/Marmara/Portal/PortalConfig.php` | Reads `config/marmara-portal.php` (`default_locale` comes from `APP_LOCALE`); validates accent, logo and links |
| `config/marmara-portal.php` | `MARMARA_BRANDING`, `MARMARA_BRAND_*`, `MARMARA_SOURCE_URL` |
| `resources/js/marmara/portal.ts` | Reads the `marmara` prop on the client |
| `resources/js/marmara/AuthLayoutSwitch.vue` | Picks `MarmaraAuthLayout` with branding on, upstream `AuthSplitLayout` otherwise; passes props and slots through |
| `resources/js/marmara/MarmaraAuthLayout.vue` | The club frame: logo, name, tagline, card around the upstream page, legal footer, TryPost credit, source link, accent tokens |
| `public/marmara/logo.svg`, `logo-dark.svg` | The club logo (light) and its dark-mode copy (navy `#0f1c47` swapped for `#f1f5f9`, gradient ids renamed) |
| `tests/Feature/Marmara/Portal/PortalLocaleAndBrandingTest.php` | Tests |
| `marmara/portal.env.example` | The .env lines for stage / production |

### How the language is picked

Upstream TryPost ignores `APP_LOCALE` for web requests:
`App\Http\Middleware\App\SetLocale` renders a signed-in user in their
`users.locale` and every guest in `Locale::DEFAULT` (English). There is no
cookie, no `Accept-Language` detection on the server, and a guest's pick in
the language menu lives only in the browser tab (`resources/js/language.ts`).
The browser's language is used in one place: the `locale` a sign-up sends
(`signupLocale` in `useGuestLocale.ts`, also used by Google / GitHub sign-up)
is the guest's pick, else the browser's language, else the page's.

The module changes two things:

1. `SetPortalLocale` (bound in the container in place of `SetLocale`, which
   Laravel resolves from the container) sets a guest request to
   `APP_LOCALE` when that is a TryPost locale other than English. Signed-in
   users are untouched, so a user's own choice always wins.
2. The client prefers that default over the browser's language in
   `signupLocale`, so a new account is Turkish unless the person picked
   another language on the login / register screen.

The default is read from `config('marmara-portal.default_locale')`, which
copies `APP_LOCALE` (or `MARMARA_DEFAULT_LOCALE`) when the config loads,
never from `config('app.locale')` at request time: `App::setLocale()`
writes that key, so after upstream `SetLocale` ran it says `en` (or the last
user's language in a long-running worker).

Existing accounts keep their stored `users.locale` (the column defaulted to
`en`, so older accounts are English until the user picks Turkish in
Settings > Preferences). Mails and jobs without a recipient language also use
`APP_LOCALE`; dates and numbers follow the active locale (dayjs `tr` and
`lang/tr` are part of upstream). Keep `APP_FALLBACK_LOCALE=en`.

### Branding

`MARMARA_BRANDING=true` replaces upstream's `AuthSplitLayout` with
`MarmaraAuthLayout`: a single centred column with the club logo, the club
name and `MARMARA_BRAND_TAGLINE` ("Sosyal Medya Paneli") on top, the upstream
page (forms, OAuth buttons, validation, status) in a card, the page's own
footer (privacy / terms links) under it, and a small "TryPost ile çalışır /
Powered by TryPost" credit linking to trypost.it at the bottom, plus
"Kaynak kodu" when `MARMARA_SOURCE_URL` is set (AGPL-3.0 section 13). The
language menu stays for guests. There is no reviews panel. TryPost's neutral
tokens are used, so light, dark and mobile follow the app theme.
`MARMARA_BRANDING=false` renders upstream's `AuthSplitLayout`, untouched.

Only the frame is ours: the pages under `resources/js/pages/auth/` are
upstream's and still render through the layout's default and `footer`
slots, so upstream fixes to them apply as they are.

Accent: `MARMARA_BRAND_ACCENT`, by default `#1e6bf5`, the logo's blue
(`#3278f6`) darkened so white button text keeps 4.7:1 (the logo blue gives
4.1:1). It remaps TryPost's primary tokens only while the club frame is on
screen. Set it empty for TryPost's own colours.

Logo: `public/marmara/logo.{svg,png,webp}` (and optional `logo-dark.*`) are
picked up automatically, with the club name as a text wordmark when there is
none; `MARMARA_BRAND_LOGO(_DARK)` can point elsewhere. The URL carries a
content hash (`?v=`), so a replaced file is never served from a stale cache.
The club website (marmaraai.com) is not used for colours or layout.

### Upstream touch points

| File | Lines | Change |
| --- | --- | --- |
| `bootstrap/providers.php` | 7, 19-20 | `use App\Marmara\Portal\PortalServiceProvider;` and the provider in the list |
| `resources/js/layouts/AuthLayout.vue` | 2 | `import AuthLayout from '@/marmara/AuthLayoutSwitch.vue'` instead of `'@/layouts/auth/AuthSplitLayout.vue'`. On a conflict take upstream's file and change this import back |
| `resources/js/composables/useGuestLocale.ts` | 6, 35-41 | `import { portalDefaultLocale } ...`; `portalDefaultLocale(page.props) ??` before `browserLocale.value` in `signupLocale` |

Contracts relied on without editing them: `SetLocale` is not final and is
resolved from the container (`$middleware->web(append: [SetLocale::class])`
in `bootstrap/app.php`); `HandleInertiaRequests` shares `locale` from
`app()->getLocale()` after `SetLocale` ran; `Inertia::share()` from a
provider merges with the middleware's props; every auth page renders through
`layouts/AuthLayout.vue` with the props `title`, `description`, `status`,
`panel`, `width` and the `footer` slot (`AuthLayoutSwitch` and
`MarmaraAuthLayout` take the same ones; add any new upstream prop to both).
`AuthSplitLayout.vue` itself is not edited. If upstream renames
`SetLocale` or makes it final, re-check `SetPortalLocale`.

Servers that bind-mount their own `bootstrap/providers.php` must add
`App\Marmara\Portal\PortalServiceProvider::class` to it; without that the
module is not loaded and the panel looks and speaks like upstream.

## Inbox: comments and DMs in one place

One place to see and answer the comments and direct messages of the club's
channels: the "Gelen Kutusu" page in TryPost, a REST API and MCP tools for
Echo (WhatsApp) / Hermes, and the `inbox.item_received` webhook. Every network
is implemented only as far as its official API (or Zernio) allows, each behind
its own flag; a missing permission shows as "needs permission X" (page,
`marmara:inbox:status`), never as a failing job. Variables:
`marmara/inbox.env.example`.

### Files

| Path | What |
| --- | --- |
| `app/Marmara/Inbox/InboxServiceProvider.php` | Migrations, lang, routes, schedule (`marmara:inbox:sync` every 5 min), commands, the `marmaraInbox` shared prop, container rebinds (below) |
| `app/Marmara/Inbox/InboxConfig.php`, `config/marmara-inbox.php` | Flags and limits |
| `app/Marmara/Inbox/Models/*`, `database/migrations/` | `marmara_inbox_items`, `marmara_inbox_replies`, `marmara_inbox_sync_states` |
| `app/Marmara/Inbox/Providers/InboxProvider.php` | The provider interface: capabilities, missing permission, `fetch()` with a cursor, `reply()`, `hide()`, reply length |
| `app/Marmara/Inbox/Providers/{LinkedIn,X,Zernio}InboxProvider.php`, `Providers/Meta/*` | The providers (matrix below) |
| `app/Marmara/Inbox/InboxProviders.php` | Which provider reads a channel |
| `app/Marmara/Inbox/InboxSync.php`, `Jobs/SyncInboxAccount.php` | One sync per channel, provider and kind: cursor saved after every page, statuses, back-off |
| `app/Marmara/Inbox/InboxItemStore.php` | Dedupe by external id, own replies skipped, DM window per conversation, the webhook (once, after commit) |
| `app/Marmara/Inbox/Actions/SendInboxReply.php` | The one reply flow (page, API, MCP): checks, idempotency by `request_id`, 409/422 refusals |
| `app/Marmara/Inbox/InboxItemPayload.php` | The contract item shape |
| `app/Marmara/Inbox/Http/*`, `routes.php` | Page, REST API, Zernio webhook receiver, `EnsureInboxEnabled` |
| `app/Marmara/Inbox/Mcp/*` | `list-inbox-items-tool`, `get-inbox-item-tool`, `reply-to-inbox-item-tool`, `InboxTryPostServer` |
| `app/Marmara/Inbox/Connect/*` | Upstream's Facebook, Instagram, Instagram (Facebook) and X connect controllers with the inbox scopes added |
| `app/Marmara/Inbox/lang/{en,tr}/inbox.php` | The page's copy (namespace `marmara-inbox`; other locales fall back to English) |
| `app/Marmara/Inbox/Console/*` | `marmara:inbox:sync [--account=] [--now] [--force]`, `marmara:inbox:status [--account=]`, `marmara:inbox:raw {item} [--stored]` (read only: the stored text and meta, then the provider's raw JSON for that message, tokens redacted) |
| `app/Marmara/Inbox/Support/InboxMessageText.php` | Turns DMs that are not (only) text into Turkish text: story mention (with the story link), story reply, reaction, media, shared post or reel, unsupported; the source fields go to the item's `meta` |
| `resources/js/marmara/inbox/*`, `resources/js/pages/marmara/Inbox.vue` | The page (the file under `pages/` only forwards to it, since Inertia resolves pages there) and the sidebar entry |
| `tests/Feature/Marmara/Inbox/*` | Pest tests |

### Upstream touch points

| File | Lines | Change |
| --- | --- | --- |
| `bootstrap/providers.php` | 5, 23-24 | `use App\Marmara\Inbox\InboxServiceProvider;` and the provider in the list |
| `app/Enums/Webhook/EventType.php` | 24 | `case InboxItemReceived = 'inbox.item_received';` (webhook validation, the MCP webhook tools and the UI picker all read the enum) |
| `resources/js/components/webhook/webhook-events.ts` | 19 | `'inbox.item_received'` in the event list, so the webhook dialogs offer it |
| `lang/*/webhooks.php` (17 locales, `id` since v2.1.0) | 2 lines each | `events.inbox_item_received` and `event_descriptions.inbox_item_received`, after `post_feedback_requested` (WebhookTranslationsTest wants every event in every locale) |
| `resources/js/components/AppSidebar.vue` | 48, 104 | `import { marmaraInboxNavItems } ...` and `...marmaraInboxNavItems(page.props),` in `mainNavItems` (empty while the module is off) |
| `tests/fixtures/single-line-copy.php`, `tests/Unit/Enums/WebhookEventTypeTest.php` | 1 line each | The new description's length budget and the enum value |

Not edited, relied on: Laravel resolves route controllers through the
container, so `InboxServiceProvider` binds `FacebookController`,
`InstagramController`, `InstagramFacebookController` and `XController` to
subclasses whose constructor appends the inbox scopes to upstream's
`$scopes` (only while the provider's flag is on; otherwise upstream's list).
laravel/mcp builds the server with `Container::make(TryPostServer::class)`
(web transport and `TryPostServer::actingAs()` alike), so `TryPostServer` is
bound to `InboxTryPostServer`, whose `boot()` adds the three tools; each
tool's `shouldRegister()` hides it while `MARMARA_INBOX_ENABLED` is off.
The subclass inherits upstream's `$tools` list (new upstream tools need no
change here), and the route middleware in `routes/ai.php` (since v2.1.0 also
`throttle:mcp`, per token, production only) is attached to the route, not to
the class, so the inbox tools share upstream's MCP rate limit.
Comments the channel posts itself never become inbox items: every provider
sets `fromSelf` (LinkedIn: the comment's `actor` is the page's
`urn:li:organization:{id}`, the same URN upstream's LinkedIn "first comment"
uses as its actor), and `InboxItemStore::ingest()` drops those and our own
`InboxReply` ids before storing or firing `inbox.item_received`.
`WebhookService::dispatch()` and `DispatchWebhook` sign the event exactly like
the `post.*` events. On an upstream conflict in any file above, keep
upstream's version and re-add the one line.

### Providers

| Network | Provider | See comments | Reply to comments | Hide comments | See DMs | Reply to DMs | Needs |
| --- | --- | --- | --- | --- | --- | --- | --- |
| LinkedIn page | `linkedin` (native) | yes | yes, as the page | no API | no API | no API | `r_organization_social_feed`, `w_organization_social_feed` (granted to the app; reconnect), ADMINISTRATOR role |
| Instagram (Instagram Login) | `instagram` | yes | yes | yes | yes | yes, 24 h window | `instagram_business_manage_comments`, `instagram_business_manage_messages` |
| Instagram (Facebook Login) | `instagram` | yes | yes | yes | yes (through the Page) | yes, 24 h window | `instagram_manage_comments`, `instagram_manage_messages`, `pages_manage_metadata`, MESSAGING task on the Page |
| Facebook Page | `facebook` | yes | yes | yes | Messenger | yes, 24 h window | `pages_read_engagement`, `pages_read_user_content`, `pages_manage_engagement`, `pages_messaging`; MODERATE and MESSAGING tasks |
| TikTok | `zernio` | yes | yes (150 chars) | yes | yes (Business accounts) | yes, 48 h / 10 messages | Zernio inbox (`INBOX_REQUIRED` otherwise), account connected through TikTok's Business app in Zernio (`PLATFORM_LIMITATION` otherwise) |
| X | `x` (native, off) | mentions | yes (reply post) | no | yes | yes | X API plan that can read (pay-per-use); DMs need `dm.read`, `dm.write` (reconnect) |
| X through Zernio | `zernio` | no (Zernio: `platform_not_supported`) | - | - | yes | yes | X DMs bill separately in Zernio |
| Instagram / Facebook / Threads / YouTube / Bluesky / Reddit through Zernio | `zernio` (`MARMARA_INBOX_ZERNIO_ACCOUNTS`) | yes | yes | IG, FB, Threads | IG, FB, Bluesky, Reddit | yes | the channel in Zernio |

Meta: Standard Access works without App Review for accounts whose people have
a role on the Meta app (the club's own accounts); every other account needs
Advanced Access, i.e. Meta App Review. The status text says "requires Meta
App Review advanced access" for that reason. The HUMAN_AGENT tag (7 days) is
not used: it needs its own review and only covers replies a person types.
Meta webhooks (real time) need Live mode, Advanced Access, App Review and
Business Verification, so the inbox polls.

Which provider reads a channel: a channel listed in
`MARMARA_INBOX_ZERNIO_ACCOUNTS` goes to Zernio; otherwise the native provider
of its network when its flag is on; otherwise Zernio when the channel is
mapped in `ZERNIO_ACCOUNTS` (TikTok; a LinkedIn page still published through
Zernio). Never two providers for one channel, and of two channels of the
same Instagram account (Instagram login and Facebook login, matched by
username) only one reads: the one in `MARMARA_INBOX_ZERNIO_ACCOUNTS`, else
the one connected first. Items are unique per channel and network id across
providers, so moving a channel between the native provider and Zernio does
not store its messages again.

DMs that are not text are stored with readable text (sent to Echo as is):
"📸 Hikâyende bizi etiketledi", "↩️ Hikâyene cevap verdi: 😍", "↩️ Hikâyene
tepki verdi" (a story reply with no text, usually an emoji Zernio does not
give), "😍 Mesaja tepki verdi", "🖼️ Görsel gönderdi", "🎥 Video gönderdi",
"🔗 Gönderi paylaştı", "Desteklenmeyen mesaj türü"; text and attachment show
both. A reaction is an item of its own, `reaction:{message id}:{emoji}`, the
same id from Zernio's list (`reactions[]`), its `reaction.received` webhook
and Graph (`reactions`). A DM Zernio polled without content
(`contentNotRetained`) reads "Mesajın içeriği Zernio'da saklanmıyor" until
the webhook brings its text; a row stored with no text (null, '' or
whitespace) is filled the same way on the next sync. Story URLs are signed CDN
links that expire: they stay in `meta.story_url`, a story reply never links
one in the text, and a mention links it only when it is not a
`lookaside.fbsbx.com` URL.

### Endpoints and versions (verified November 2026)

| Provider | Endpoint | Docs |
| --- | --- | --- |
| LinkedIn | `GET /rest/socialActions/{postUrn}/comments?start=0&count=50`, `POST /rest/socialActions/{parent commentUrn}/comments` with `actor` (organization), `object` (post URN), `message.text`, `parentComment`; `LinkedIn-Version: MARMARA_LINKEDIN_API_VERSION` (202610), Rest.li 2.0. Posts list: the LinkedIn module's `/rest/posts?q=author` | [Comments API](https://learn.microsoft.com/linkedin/marketing/community-management/shares/comments-api) (li-lms-2026-10) |
| Instagram Login | `GET /{ig-id}/media`, `GET /{media-id}/comments?fields=...,replies{...}`, `POST /{comment-id}/replies`, `POST /{comment-id}` `hide=true`, `GET /{ig-id}/conversations?platform=instagram&fields=messages.limit(20){...}`, `POST /{ig-id}/messages` (text up to 1000 bytes) on `INSTAGRAM_GRAPH_API` (graph.instagram.com/v25.0) | [comment moderation](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/comment-moderation), [messaging](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/messaging-api), [conversations](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/conversations-api) |
| Instagram (Facebook) / Facebook | same comment calls, `GET /{page-id}/published_posts`, `GET /{post-id}/comments?filter=stream`, `POST /{comment-id}/comments`, `POST /{comment-id}` `is_hidden=true`, `GET /{page-id}/conversations?platform=instagram|messenger`, `POST /{page-id}/messages` (`messaging_type=RESPONSE`, Messenger text up to 2000) on graph.facebook.com/v25.0 | [comments](https://developers.facebook.com/docs/graph-api/reference/object/comments/), [conversations](https://developers.facebook.com/docs/messenger-platform/conversations) |
| Zernio | `GET /v1/inbox/comments?accountId&sortBy=date&sortOrder=desc&since&minComments=1`, `GET /v1/inbox/comments/{postId}?accountId`, `POST /v1/inbox/comments/{postId}` (`commentId`, `Idempotency-Key`), `POST /v1/inbox/comments/{postId}/{commentId}/hide`, `GET /v1/inbox/conversations?accountId&sortOrder=desc`, `GET` / `POST /v1/inbox/conversations/{id}/messages` | [comments](https://docs.zernio.com/products/comments), [messaging](https://docs.zernio.com/products/messaging), [conversations](https://docs.zernio.com/messages/list-inbox-conversations), [messages](https://docs.zernio.com/messages/get-inbox-conversation-messages), [comment.received](https://docs.zernio.com/webhooks/inbox/comment-received), [message.received](https://docs.zernio.com/webhooks/inbox/message-received), [reaction.received](https://docs.zernio.com/webhooks/inbox/reaction-received) |
| X | `GET /2/users/{id}/mentions` (`since_id`, `pagination_token`), `POST /2/tweets` with `reply.in_reply_to_tweet_id`, `GET /2/dm_events`, `POST /2/dm_conversations/{id}/messages` | [X API](https://docs.x.com/x-api) |

### Sync, budget and back-off

`marmara:inbox:sync` runs every 5 minutes (one server, no overlap) and queues
`SyncInboxAccount` for each channel, provider and kind whose interval has
passed (LinkedIn 180 min, Meta and Zernio 10, X 30) and that is not backing
off. A run reads up to `MARMARA_INBOX_MAX_PAGES_PER_SYNC` pages and saves the
provider's cursor after each, so a stopped run resumes. Comments are polled
on the posts of the last `MARMARA_INBOX_POST_WINDOW_DAYS` (14), a few posts a
run, rotating; DMs from the conversations updated since the cursor.

- Dedupe: one row per (channel, provider, external id), unique in the
  database; the channel's own comments/messages (author is the page / account,
  or the external id of a reply sent from TryPost) are never stored.
- LinkedIn shares the LinkedIn module's budget: every inbox request takes a
  unit of `MARMARA_LINKEDIN_DAILY_CALL_BUDGET` (80) through
  `LinkedInCallBudget`, after a unit of the inbox's own share
  (`MARMARA_INBOX_LINKEDIN_DAILY_CALLS`, 30), so analytics keep at least 50.
  A 429 blocks every LinkedIn call (analytics too) until `Retry-After`. The
  post list is read at most every 12 hours. About 26 calls a day by default.
- Meta: a rate-limit answer (codes 4, 17, 32, 613, 800xx or HTTP 429) blocks
  the channel until `X-Business-Use-Case-Usage.estimated_time_to_regain_access`
  (else 30 min). Zernio and X: `Retry-After` / X's reset headers.
- Statuses per channel and kind: `ok`, `needs_permission` (the detail names
  it), `rate_limited` (until `blocked_until`), `authentication` (reconnect),
  `unsupported`, `error`.

### Contract for Echo / Hermes

Item (`data.item` of the webhook, the API's items, the MCP tools):
`{id, platform, kind: "comment"|"message", author: {name, handle}, text,
permalink, post: {id, excerpt}|null, received_at, can_reply,
reply_window_ends_at|null}`. `post` is null for a DM; `post.id` is the TryPost
post id when TryPost published the post, else the network's post id.
LinkedIn gives no commenter name (only the member URN): `author` is then
null/null. `can_reply` is false once the DM window has closed.

- Webhook `inbox.item_received`: TryPost's own webhooks (Settings > Webhooks,
  or the API / MCP webhook tools), signed with `X-Webhook-Signature` like the
  `post.approval_*` events. Fired once per new item, after the row commits;
  never for our own replies; not for items older than
  `MARMARA_INBOX_NOTIFY_MAX_AGE_HOURS` (the first sync's backlog).
- Replying (and hiding) is the `reply-to-inbox-item` gate on the panel, the
  API and MCP alike: the token's user needs the workspace's direct publishing
  right (`WorkspacePolicy::publishDirectly`), like publishing a post.
- REST (API key, `auth:api` + `workspace.token`, workspace of the key):
  - `GET /api/inbox/items?status=unread|read|archived|open|all&limit=1..100&platform=&kind=&before=` → `{data: [item + status], has_more}` (default `open`, 20)
  - `GET /api/inbox/items/{id}` → item + `status`, `channel`, `thread`, `replies` (each `{id, status, text, error, created_at, author: {name}|null}`; `author` is the member who sent it)
  - `POST /api/inbox/items/{id}/replies` `{text, request_id, acknowledged_reply_id?}` → 201 `{id, status: sent|failed[, error]}`; the same `request_id` answers the same result without sending again (checked first, before everything below); 409 `{code: already_replied, reply}` when the item already has a `sent` reply and `acknowledged_reply_id` is not that reply or a newer one (a reply that came after the acknowledged one refuses again); 409 `{code: reply_in_progress, reply}` while another reply is `sending` (no waiting; try again); `reply` is the blocking reply in the `replies` shape; 409 `{code: reply_window_closed}`; 422 `{code: text_too_long}` (LinkedIn 1250, Instagram comments 2200 / DMs 1000 bytes, Facebook 8000 / Messenger 2000, TikTok 150, X 280) or `{code: cannot_reply}`; 403 for a member whose posts need approval
  - `POST /api/inbox/items/{id}/read` → `{id, status}`
- MCP: `list-inbox-items-tool` and `get-inbox-item-tool` (read only),
  `reply-to-inbox-item-tool` (destructive, open world; `request_id` required,
  `acknowledged_reply_id` as in the API; `already_replied` names who replied
  and what, so Hermes asks the user before sending again).
- One reply per item unless acknowledged (`SendInboxReply`): the check and
  the `sending` row are made in one transaction holding the item row lock
  (`lockForUpdate`), and the network call runs after the commit, so the
  `sending` row itself keeps concurrent senders out (`reply_in_progress`).
  A `sending` row older than `SendInboxReply::STALE_SENDING_MINUTES` (5; the
  process died mid-call) no longer blocks as in progress; its outcome is
  unknown, so it counts like a sent reply (`already_replied`, needs an
  acknowledgement) and stays `sending` in the history. `failed` replies never
  block. The panel shows `already_replied` as a toast with "Send anyway",
  which resends with the reply acknowledged.

Optional Zernio receiver: `POST {APP_URL}/api/marmara/zernio/inbox-webhook`
(`comment.received`, `message.received`), verified with `X-Zernio-Signature`
(HMAC-SHA256 of the raw body, lowercase hex) against
`MARMARA_INBOX_ZERNIO_WEBHOOK_SECRET`, deduped by the event `id`. Polling
stays on as the fallback. A DM has one id on both paths: the platform's
message id (the list API's `id`, the webhook's `message.platformMessageId`);
the webhook's own `message.id` (Zernio's internal id) is kept in
`meta.zernio_message_id` and checked as an alias, and a row stored under it
is moved to the platform id. `comment.received` documents only `comment.id`,
used as is on both paths.

### How to enable each provider

1. Add `marmara/inbox.env.example` to `.env` with `MARMARA_INBOX_ENABLED=true`
   and the provider flags you want; recreate the container (the migration
   runs with the others). `php artisan marmara:inbox:status` shows what each
   channel can do and what it still needs.
2. LinkedIn page: `MARMARA_INBOX_LINKEDIN=true`. The app already has
   `r_organization_social_feed` and `w_organization_social_feed`; the connect
   now asks for `w_organization_social_feed` too, so do Channels > the
   LinkedIn page > "Refresh connection" as a page ADMINISTRATOR (until then:
   comments are read, replies say "needs permission
   w_organization_social_feed"). The page must be native (not in
   `ZERNIO_ACCOUNTS`). Page DMs: no API, not available.
3. Instagram / Facebook: in the Meta app add the permissions to the use case
   (Instagram Login: `instagram_business_manage_comments`,
   `instagram_business_manage_messages`; Facebook Login:
   `instagram_manage_comments`, `instagram_manage_messages`,
   `pages_manage_metadata`, `pages_read_user_content`,
   `pages_manage_engagement`, `pages_messaging`). With the club's own
   accounts and their admins in an app role, Standard Access is enough; for
   anyone else request Advanced Access in App Review (screencast of the inbox
   page reading and answering), plus Business Verification. Then set
   `MARMARA_INBOX_INSTAGRAM=true` / `MARMARA_INBOX_FACEBOOK=true` and
   "Refresh connection" every Instagram / Facebook card (the connect asks the
   new scopes only while the flag is on). The Facebook user who connects
   needs the Page's MODERATE and MESSAGING tasks. In the Instagram app, allow
   "Connected tools" message access (Settings > Messages).
4. TikTok (and others) through Zernio: check that the Zernio plan has the
   inbox (a 403 `INBOX_REQUIRED` otherwise) and that the TikTok account is
   connected through TikTok's Business app in Zernio; `MARMARA_INBOX_ZERNIO=true`.
   Optional real time: create a Zernio webhook for `comment.received` and
   `message.received` to `{APP_URL}/api/marmara/zernio/inbox-webhook` and put
   its secret in `MARMARA_INBOX_ZERNIO_WEBHOOK_SECRET`.
5. X: only with an X API plan that can read. `MARMARA_INBOX_X=true`, then
   "Refresh connection" on the X card for `dm.read`/`dm.write` (also enable
   DMs for the app in the X developer portal). Otherwise leave it off; X DMs
   can also come through Zernio.
6. Echo: in TryPost (Settings > Webhooks, or the webhook API) add
   `inbox.item_received` to Echo's webhook; Echo answers with
   `POST /api/inbox/items/{id}/replies` using its API key.

### Not verified against the live API

Written from the docs above and tested with `Http::fake` only: LinkedIn's
`start`/`count` on the comments finder (not documented on the Comments page)
and the reply's `commentUrn` in the body (the id is also built from
`x-restli-id`); Graph's nested `messages.limit(20){...}` field expansion on
conversations; Zernio's `message.received` payload (not documented; an
unknown shape only makes the channel's next poll due) and the error `code`
field placement; X's pay-per-use read limits. The LinkedIn and Instagram
comment length limits are the networks' product limits, not stated on the
API pages.

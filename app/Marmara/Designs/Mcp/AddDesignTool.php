<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Mcp;

use App\Actions\Media\ResolveWorkspaceMedia;
use App\Marmara\Designs\Actions\DesignActions;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Models\DesignMedia;
use App\Marmara\Designs\Posts\DesignPostLinks;
use App\Marmara\Designs\Posts\RecordDesignPublication;
use App\Marmara\Designs\Support\DesignMembers;
use App\Marmara\Designs\Support\DesignSource;
use App\Marmara\Designs\Support\DesignTypes;
use App\Marmara\Designs\Upload\DesignUploader;
use App\Marmara\Designs\Upload\DesignUploadUnavailable;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

/**
 * Hermes / Echo's "bu dosyayı depoya yükle": a file TryPost already holds (a
 * temporary upload by its upload_token, or a media id of this workspace) goes
 * into the shared Drive folder through DesignUploader, exactly like the
 * page's "Yükle", then gets its tags through DesignActions::syncTags.
 */
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
#[Description('Add a file to the club\'s design repository (the "Tasarımlar" gallery of the shared Google Drive folder), like the page\'s "Yükle" button. media: {upload_token} of a file uploaded to TryPost (request-media-upload-tool, or the token Echo gives for a WhatsApp image, video or PDF; spent by this call, once it succeeds) or {id} of a media item of this workspace (left as it is). Accepted: images (JPEG, PNG, WebP, HEIC), PDF and video, up to max MB of the designs module (100 MB by default). title defaults to the file name; tags (without #, lowercased, at most 20 of up to 50 characters); folder is the collection path inside the shared folder (e.g. "Bahar Şenliği/Afişler", created when missing; empty for the main folder); on_behalf_of_user_id is the TryPost user id of the WhatsApp sender (a member of this workspace; the design is credited to them), else on_behalf_of is the name of the person who asked (shown as "Name (WhatsApp)" under "Added by"). Needs the Drive upload permission an admin gives on the Designs page; without it the call fails and says so. Answers the new design: id, title, kind, tags, folder, uploader {id, name}, source (whatsapp, agent), url (opens it on the Tasarımlar page), drive_url, and media_id (the TryPost file, reusable as {id} on a post until it expires).')]
class AddDesignTool extends Tool
{
    use AuthorizesMcpTool;

    public function shouldRegister(DesignsConfig $config): bool
    {
        return $config->enabled();
    }

    public function handle(Request $request, DesignUploader $uploader, DesignActions $actions, DesignPostLinks $links): Response|ResponseFactory
    {
        // The page's "Yükle" needs createPost on the workspace; so does this.
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $config = app(DesignsConfig::class);

        if (! $config->isDesignsWorkspace($workspace)) {
            return Response::error('Designs are not available in this workspace.');
        }

        $validated = $request->validate([
            'media' => ['required', 'array'],
            'media.upload_token' => ['nullable', 'uuid', 'required_without:media.id', 'prohibits:media.id'],
            'media.id' => ['nullable', 'uuid', 'required_without:media.upload_token'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:'.DesignActions::MAX_TAGS],
            'tags.*' => ['string', 'max:50'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:500'],
            'on_behalf_of' => ['sometimes', 'nullable', 'string', 'max:80'],
            'on_behalf_of_user_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        // The WhatsApp sender as a member: only a member of this workspace.
        $member = null;

        if (filled($validated['on_behalf_of_user_id'] ?? null)) {
            $member = DesignMembers::find($workspace, (string) $validated['on_behalf_of_user_id']);

            if ($member === null) {
                throw ValidationException::withMessages(['on_behalf_of_user_id' => 'on_behalf_of_user_id is not a member of this workspace. Leave it out and pass on_behalf_of with the person\'s name instead.']);
            }
        }

        try {
            $uploader->connection();
        } catch (DesignUploadUnavailable $unavailable) {
            return Response::error($this->message($unavailable));
        }

        $token = data_get($validated, 'media.upload_token');
        $media = filled($token)
            ? ResolveWorkspaceMedia::byUploadTokens($workspace, [(string) $token])->first()
            : ResolveWorkspaceMedia::execute($workspace, [(string) data_get($validated, 'media.id')])->first();

        if (! $media instanceof Media) {
            return Response::error(filled($token) ? (string) __('posts.errors.media_expired') : 'Media not found.');
        }

        // Checked before the token is spent or the file is read.
        if (! DesignTypes::supported($media->mime_type) || DesignTypes::isGoogleNative($media->mime_type)) {
            return Response::error($this->message(new DesignUploadUnavailable('unsupported')));
        }

        if ((int) $media->size > $config->uploadMaxBytes()) {
            return Response::error($this->message(new DesignUploadUnavailable('too_large')));
        }

        // Single use: only one call can take the token; it is given back when the upload fails.
        if (filled($token) && ! $this->spend($media, (string) $token)) {
            return Response::error((string) __('posts.errors.media_expired'));
        }

        $user = $request->user();
        $onBehalfOf = Str::squish((string) ($validated['on_behalf_of'] ?? ''));

        // The member sender, else the sender's name only, else the token's user.
        [$uploaderName, $source, $uploaderUser] = match (true) {
            $member !== null => [(string) $member->name, DesignSource::WhatsApp, $member],
            $onBehalfOf !== '' => ["{$onBehalfOf} (WhatsApp)", DesignSource::WhatsApp, null],
            default => [(string) $user->name, DesignSource::Agent, $user],
        };
        $temporary = null;

        try {
            $temporary = $this->localCopy($media);
            $design = $uploader->upload(
                new UploadedFile($temporary, $this->originalName($media), $media->mime_type, null, true),
                (string) ($validated['folder'] ?? ''),
                (string) ($validated['title'] ?? ''),
                $uploaderName,
                $user,
                $media->mime_type,
                $source,
                $uploaderUser,
            );
        } catch (DesignUploadUnavailable $unavailable) {
            $this->giveBack($media, $token);

            return Response::error($this->message($unavailable));
        } catch (Throwable $exception) {
            $this->giveBack($media, $token);

            throw $exception;
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }

        $tags = $actions->syncTags($design, $user, array_values((array) ($validated['tags'] ?? [])));

        // The file is the design's now: a post made with media_id (or a
        // channel's copy of it) is linked to the design, and a post that
        // already holds this media row is linked right away.
        DesignMedia::remember($design, $media->id, $design->approvalVersion());
        $media->refresh();

        if ($media->post_id !== null) {
            $links->link($design, $media->post_id, (int) $media->order, $user);
            $links->groupPost($media->post_id);
            $post = Post::query()->find($media->post_id);

            if ($post !== null) {
                app(RecordDesignPublication::class)->record($post);
            }
        }

        return Response::structured([
            'id' => $design->id,
            'title' => $design->title,
            'kind' => $design->kind,
            'tags' => $tags,
            'folder' => $design->folder_path,
            'uploader' => ['id' => $design->uploader_user_id, 'name' => $design->uploaderLabel()],
            'source' => $design->source->value,
            'url' => route('marmara.designs.index', ['design' => $design->id]),
            'drive_url' => $design->web_view_link,
            'media_id' => $media->id,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'media' => $schema->object(fn (JsonSchema $item): array => [
                'upload_token' => $item->string()->description('Token of a file uploaded to TryPost (request-media-upload-tool, or the upload_token Echo gives for a WhatsApp file). Spent by this call.'),
                'id' => $item->string()->description('UUID of a media item of this workspace (e.g. a post\'s media id); the file is copied, the media item stays.'),
            ])->required()->description('The file: exactly one of upload_token or id.'),
            'title' => $schema->string()->description('Title of the design (up to 200 characters). Default: the file name.'),
            'tags' => $schema->array()->items($schema->string())->description('Tags, without # (lowercased; at most 20, each up to 50 characters).'),
            'folder' => $schema->string()->description('Collection: folder path inside the shared folder, e.g. "Bahar Şenliği/Afişler"; missing folders are created. Empty for the main folder.'),
            'on_behalf_of_user_id' => $schema->string()->description('TryPost user id (UUID) of the person who asked for the upload, when known (Echo\'s "[TryPost kullanıcı: <uuid>]" note). Must be a member of this workspace, otherwise the call fails. The design is credited to that member, with source whatsapp. Never guess it.'),
            'on_behalf_of' => $schema->string()->description('Display name of the person who asked for the upload (e.g. the WhatsApp sender) when their TryPost user id is not known; shown as "Name (WhatsApp)". Ignored when on_behalf_of_user_id is given. Default: the connected TryPost user (source agent).'),
        ];
    }

    private function spend(Media $media, string $token): bool
    {
        return Media::query()
            ->whereKey($media->id)
            ->where('upload_token', $token)
            ->update(['upload_token' => null]) === 1;
    }

    private function giveBack(Media $media, mixed $token): void
    {
        if (blank($token)) {
            return;
        }

        Media::query()->whereKey($media->id)->whereNull('upload_token')->update(['upload_token' => (string) $token]);
    }

    /**
     * The stored file (local disk or object storage) as a local temporary file.
     *
     * @throws DesignUploadUnavailable
     */
    private function localCopy(Media $media): string
    {
        $stream = filled($media->path) ? Storage::readStream($media->path) : null;

        if (! is_resource($stream)) {
            throw new DesignUploadUnavailable('failed', 'The file could not be read: it is no longer stored in TryPost.');
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'marmara-design-up-');
        $target = fopen($path, 'wb');

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($target);
            fclose($stream);
        }

        return $path;
    }

    private function originalName(Media $media): string
    {
        $name = trim(basename((string) $media->original_filename));

        return $name !== '' ? $name : 'WhatsApp.'.DesignTypes::extensionFor((string) $media->mime_type);
    }

    /**
     * The uploader's reason in English, like the other designs tools' errors.
     */
    private function message(DesignUploadUnavailable $unavailable): string
    {
        $key = "marmara-designs::designs.upload.{$unavailable->reason}";

        return match ($unavailable->reason) {
            'failed' => $unavailable->getMessage(),
            'too_large' => (string) __($key, ['size' => intdiv(app(DesignsConfig::class)->uploadMaxBytes(), 1048576)], 'en'),
            default => (string) __($key, [], 'en'),
        };
    }
}

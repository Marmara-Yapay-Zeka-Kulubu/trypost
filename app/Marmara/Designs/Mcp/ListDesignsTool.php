<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Mcp;

use App\Marmara\Designs\DesignPayload;
use App\Marmara\Designs\DesignQuery;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Models\Design;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the club\'s designs (the "Tasarımlar" gallery of the shared Google Drive folder), newest first. Filters: q (title search: the title set in TryPost and the Drive file name), folder (collection path, e.g. "Bahar Şenliği"; "/" for the main folder; includes subfolders), uploader (a member\'s user id, or a name), status (draft = Taslak, in_review = Değerlendirmede, approved = Onaylandı, used = Kullanıldı, archived = Arşiv), tag, kind (image, pdf, video, drawing), from / to (dates, by when the file was added). sort: newest (default), oldest, title, votes, last_comment. limit 1-50 (default 20), page from 1. Only the newest version of linked versions is listed. Each design has title (the title shown; drive_title is the one from the Drive file name, custom_title the one set in TryPost or null), uploader {id, name} (id null when only a name is known) and source (site, whatsapp, drive, agent). thumbnail_url is a temporary signed image URL; url opens the design in TryPost.')]
class ListDesignsTool extends Tool
{
    use AuthorizesMcpTool;

    public function shouldRegister(DesignsConfig $config): bool
    {
        return $config->enabled();
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'view');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        if (! app(DesignsConfig::class)->isDesignsWorkspace($workspace)) {
            return Response::error('Designs are not available in this workspace.');
        }

        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'uploader' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['draft', 'in_review', 'approved', 'used', 'archived'])],
            'tag' => ['sometimes', 'nullable', 'string', 'max:50'],
            'kind' => ['sometimes', 'nullable', 'string', Rule::in(['image', 'pdf', 'video', 'drawing'])],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(DesignQuery::SORTS)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);
        $designs = DesignQuery::for($workspace, $validated)->skip(($page - 1) * $limit)->take($limit + 1)->get();

        return Response::structured([
            'designs' => $designs->take($limit)->map(fn (Design $design): array => DesignPayload::item($design, signedThumbnails: true))->values()->all(),
            'has_more' => $designs->count() > $limit,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Words of the title.'),
            'folder' => $schema->string()->description('Collection (folder path inside the shared folder), e.g. "Bahar Şenliği/Afişler"; "/" for the main folder.'),
            'uploader' => $schema->string()->description('Who added it: a member\'s user id (uploader.id of a design), or a name for designs without a member (uploader.name).'),
            'status' => $schema->string()->enum(['draft', 'in_review', 'approved', 'used', 'archived'])->description('draft (Taslak), in_review (Değerlendirmede), approved (Onaylandı), used (Kullanıldı), archived (Arşiv).'),
            'tag' => $schema->string()->description('A tag, without #.'),
            'kind' => $schema->string()->enum(['image', 'pdf', 'video', 'drawing'])->description('File type.'),
            'from' => $schema->string()->description('Added on or after this date (YYYY-MM-DD).'),
            'to' => $schema->string()->description('Added on or before this date (YYYY-MM-DD).'),
            'sort' => $schema->string()->enum(DesignQuery::SORTS)->description('newest (default), oldest, title, votes, last_comment.'),
            'limit' => $schema->integer()->description('How many, 1 to 50 (default 20).'),
            'page' => $schema->integer()->description('Page, from 1.'),
        ];
    }
}

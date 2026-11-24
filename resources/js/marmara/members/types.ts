/** A member of the workspace, as the creator filter and the per member counts list it. */
export interface WorkspaceMemberOption {
    id: string;
    name: string;
    photo_url: string | null;
}

/** One row of "Posts by member" (App\Marmara\Members\Actions\BuildMemberPostStats). */
export interface MemberPostStatsRow {
    user: WorkspaceMemberOption;
    published: number;
    failed: number;
    scheduled: number;
    pending_approval: number;
    drafts: number;
    via_whatsapp: number;
    platforms: Record<string, number>;
}

export interface MemberPostStats {
    start: string;
    end: string;
    members: MemberPostStatsRow[];
}

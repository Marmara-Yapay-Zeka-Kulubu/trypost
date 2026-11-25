/**
 * Marmara AI fork: Instagram people tags on stories and reels
 * (app/Marmara/README.md, Instagram people tags).
 *
 * A feed image's tags need a position (x, y), so they are placed by clicking
 * the image. A video (a reel, a video story, a feed video that publishes as a
 * Reel) and the images of a post that only goes to stories take usernames
 * only, so the editor shows a username list instead.
 */
const POSITIONED_CONTENT_TYPES = ['instagram_feed'];

export const tagsNeedPosition = (
    contentTypes: string[],
    isVideo: boolean,
): boolean =>
    !isVideo &&
    contentTypes.some((contentType) =>
        POSITIONED_CONTENT_TYPES.includes(contentType),
    );

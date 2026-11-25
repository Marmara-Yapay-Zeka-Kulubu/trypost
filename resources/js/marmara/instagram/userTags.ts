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

/** Content types besides the feed that take tags on a video (one per post). */
const VIDEO_TAG_CONTENT_TYPES = ['instagram_story', 'instagram_reel'];

/**
 * Meta takes no people tags on the videos of a carousel, so the publisher
 * sends none there: a feed post with more than one media item is a carousel.
 * The tab stays when a story or a reel of the post takes the tags.
 */
export const carouselDropsVideoTags = (
    contentTypes: string[],
    mediaCount: number,
): boolean =>
    mediaCount > 1 &&
    contentTypes.some((contentType) =>
        POSITIONED_CONTENT_TYPES.includes(contentType),
    ) &&
    !contentTypes.some((contentType) =>
        VIDEO_TAG_CONTENT_TYPES.includes(contentType),
    );

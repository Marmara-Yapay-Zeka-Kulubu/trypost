/**
 * Marmara AI fork: LinkedIn company mention markers
 * (`@[Display Name](https://www.linkedin.com/company/<vanity>)`, also without
 * the scheme or as `urn:li:organization:<id>`). Mirror of
 * `App\Marmara\LinkedIn\Mentions\CompanyMentions::PATTERN`: the editor counts
 * characters and previews against the display name, on every network
 * (LinkedIn turns the marker into a mention when it publishes).
 */
const COMPANY_MENTION_PATTERN =
    /@\[([^\]\r\n]{1,100})\]\((?:urn:li:organization:\d+|(?:https?:\/\/)?(?:[a-z]{2,3}\.|www\.)?linkedin\.com\/company\/[^\s/?#()\[\]]+\/?)\)/giu;

export const companyMentionsToText = (content: string): string =>
    content.includes('@[')
        ? content.replace(
              COMPANY_MENTION_PATTERN,
              (_whole: string, name: string): string => name.trim(),
          )
        : content;

import type { MetadataRoute } from 'next';
// Keep crawling closed, including when NODE_ENV is production.
export default function robots():MetadataRoute.Robots {
 return {rules:{userAgent:'*',disallow:'/'}};
}

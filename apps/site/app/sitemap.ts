import type { MetadataRoute } from 'next';
import { publicPaths, publishedVehicles, siteLanguages, siteUrl, languageAlternates } from '../lib/seo';
export const dynamic='force-dynamic';
export default async function sitemap():Promise<MetadataRoute.Sitemap> {
 const cars=await publishedVehicles();
 const paths=[...publicPaths,...new Set(cars.map(car=>`voitures/${car.id}`))];
 return paths.flatMap(path=>siteLanguages.map(lang=>({url:siteUrl(lang,path),alternates:{languages:languageAlternates(path)}})));
}

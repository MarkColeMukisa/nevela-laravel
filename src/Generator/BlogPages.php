<?php

namespace Nevela\Laravel\Generator;

use Nevela\Laravel\Support\Descriptor;

/**
 * The site's blog: the pages that show a resource's public records as posts.
 *
 * Written once by `nevela:blog` into the web app, and the app's from then on, like the
 * dashboard's pages. What differs between one app's blog and another's is which resource
 * the posts are and what its fields are called, and all of that goes into one file,
 * lib/blog.ts. The pages only know a post has a title, a body and an address.
 *
 * Pure, like the resource generator: this returns files, and Writer writes them.
 */
final class BlogPages
{
    /**
     * @param  array{title: string, body: string, excerpt?: string|null, image?: string|null, published?: string|null}  $fields  The resource's field for each part of a post
     * @return list<GeneratedFile>
     */
    public static function files(Descriptor $d, array $fields): array
    {
        $path = (string) $d->blog;
        $segment = ltrim($path, '/');
        $image = $fields['image'] ?? null;
        $published = $fields['published'] ?? null;
        $excerpt = $fields['excerpt'] ?? null;
        $quote = fn (?string $value) => $value === null ? 'null' : json_encode($value, JSON_UNESCAPED_SLASHES);

        $replace = [
            '__PATH__' => $path,
            '__RESOURCE__' => $d->name,
            '__SLUG__' => $d->slug,
            '__PLURAL__' => strtolower($d->pluralLabel),
            '__BY__' => (string) $d->public?->by,
            '__TITLE__' => $fields['title'],
            '__BODY__' => $fields['body'],
            '__EXCERPT__' => $quote($excerpt),
            '__IMAGE__' => $quote($image),
            '__PUBLISHED__' => $quote($published),
            // HTML only where Laravel cleans it: a rich text field. Anything else is shown as the text it is.
            '__HTML__' => $d->fields[$fields['body']]->isRich() ? 'true' : 'false',
            // An upload has renditions and is fetched through this app. A path kept in a plain column is the old app's own file.
            '__UPLOAD__' => $image !== null && $d->fields[$image]->kind === 'file' ? 'true' : 'false',
            '__SORT__' => $published !== null ? "-{$published}" : '-createdAt',
        ];
        $header = "// Generated once by Nevela for the blog at {$path}. This file is yours.\n";
        $files = [
            'lib/blog.ts' => self::LIBRARY,
            "app/{$segment}/page.tsx" => self::INDEX,
            "app/{$segment}/[slug]/page.tsx" => self::POST,
            "app/{$segment}/feed.xml/route.ts" => self::FEED,
            'app/sitemap.ts' => self::SITEMAP,
            // The newest on the home page, where its template marks the place: nevela:blog puts it there.
            'components/marketing/latest-posts.tsx' => self::LATEST,
        ];

        return array_map(
            fn (string $file, string $contents) => new GeneratedFile(GeneratedFile::TARGET_WEB, $file, $header.strtr($contents, $replace), GeneratedFile::MODE_ONCE),
            array_keys($files),
            $files,
        );
    }

    private const LIBRARY = <<<'TS'
    //
    // Everything the blog's pages know about where posts come from is here: which resource
    // they are, what its fields are called, and how long a page of them may be reused.
    // The posts are the resource's public records (its descriptor's "public" section, and
    // the publicQuery scope on its model), read with no session, so the pages are the same
    // for every visitor and are cached.
    import { resourceTag } from "@/lib/cache";
    import { fileUrl } from "@/lib/files";
    import { API_URL, laravelPublic } from "@/lib/laravel";
    import { site } from "@/lib/site";

    export const blog = {
      /** Where the blog is on the site. The pages are in app__PATH__. */
      path: "__PATH__",
      title: "Blog",
      description: `News and notes from ${site.name}.`,
      /** Posts on a page of the list. */
      perPage: 9,
    };

    /** The resource's field for each part of a post. `null` where it has none. */
    const FIELDS = {
      title: "__TITLE__",
      body: "__BODY__",
      excerpt: __EXCERPT__ as string | null,
      image: __IMAGE__ as string | null,
      published: __PUBLISHED__ as string | null,
    };

    /**
     * Whether the body is HTML. True for a rich text field, which Laravel cleans before it
     * sends it, so it can go on the page as it is. Leave it false for any other field: its
     * value is then shown as plain text, and whatever is in it can do no harm.
     */
    export const BODY_IS_HTML: boolean = __HTML__;

    /**
     * Whether the picture is a file uploaded through Nevela (an image field), which has
     * smaller renditions and is fetched through this app. False for a plain column that
     * holds a path or an address of the app's own.
     */
    const IMAGE_IS_UPLOAD: boolean = __UPLOAD__;

    /**
     * How long an answer may be reused, in seconds. Saving a post in the dashboard clears it
     * at once (the tag below). The time is for what nobody saves: a post scheduled for later
     * appears within this long of its moment.
     */
    const FRESH_FOR = 60;
    const TAGS = [resourceTag("__RESOURCE__")];

    export interface BlogPost {
      id: string;
      title: string;
      /** What its address ends in: __PATH__/<slug>. */
      slug: string;
      /** A sentence or two for the list and for search engines. From the body when the post has none. */
      excerpt: string;
      body: string;
      /** A picture for the list, and a larger one for the post's own page and for sharing. */
      image: string | null;
      imageLarge: string | null;
      /** When it was published, as an ISO date. */
      publishedAt: string | null;
    }

    /** The address a visitor reaches this site at, for the sitemap, the feed and shared links. */
    export function siteUrl(): string {
      return (process.env.NEXT_PUBLIC_SITE_URL ?? process.env.SITE_URL ?? "http://localhost:3000").replace(/\/+$/, "");
    }

    const words = (html: string) => html.replace(/<[^>]*>/g, " ").replace(/&nbsp;/g, " ").replace(/&amp;/g, "&").replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&quot;/g, '"').replace(/&#0*39;/g, "'").replace(/\s+/g, " ").trim();

    function picture(value: unknown, rendition?: string): string | null {
      if (typeof value !== "string" || value === "") return null;
      // A file uploaded through Nevela: fetched through this app, in the size asked for.
      if (IMAGE_IS_UPLOAD) return fileUrl(value, rendition);
      // A path kept in a plain column: a file on the Laravel app's public disk, or an address already.
      if (/^https?:\/\//.test(value)) return value;
      return `${new URL(API_URL).origin}/storage/${value.replace(/^\/+/, "")}`;
    }

    function toPost(row: Record<string, unknown>): BlogPost {
      const text = (key: string | null) => (key && typeof row[key] === "string" ? (row[key] as string) : "");
      const body = text(FIELDS.body);
      const excerpt = text(FIELDS.excerpt) || words(body).slice(0, 180);
      return {
        id: String(row.id),
        title: text(FIELDS.title),
        slug: String(row["__BY__"] ?? row.id),
        excerpt,
        body,
        // "thumb" is the small rendition every image profile makes. One that was never made is answered with the picture itself.
        image: picture(FIELDS.image ? row[FIELDS.image] : null, "thumb"),
        imageLarge: picture(FIELDS.image ? row[FIELDS.image] : null),
        publishedAt: text(FIELDS.published) || null,
      };
    }

    /** One page of published posts, newest first. */
    export async function listPosts(page = 1, perPage = blog.perPage): Promise<{ posts: BlogPost[]; page: number; totalPages: number; total: number }> {
      const response = await laravelPublic(`__SLUG__?sort=__SORT__&page=${page}&perPage=${perPage}`, { revalidate: FRESH_FOR, tags: TAGS });
      if (!response.ok) return { posts: [], page, totalPages: 1, total: 0 };
      const { data, meta } = response.body as { data: Record<string, unknown>[]; meta: { page: number; totalPages: number; total: number } };
      return { posts: data.map(toPost), page: meta.page, totalPages: meta.totalPages, total: meta.total };
    }

    /** One published post by its address, or null: a draft, one still to come and one that never was are all "not here". */
    export async function getPost(slug: string): Promise<BlogPost | null> {
      const response = await laravelPublic(`__SLUG__/${encodeURIComponent(slug)}`, { revalidate: FRESH_FOR, tags: TAGS });
      return response.ok ? toPost(response.body as Record<string, unknown>) : null;
    }

    /** Every published post, newest first, for the sitemap and the feed. */
    export async function allPosts(most = 1000): Promise<BlogPost[]> {
      const posts: BlogPost[] = [];
      for (let page = 1; posts.length < most; page++) {
        const result = await listPosts(page, 100);
        posts.push(...result.posts);
        if (page >= result.totalPages || result.posts.length === 0) break;
      }
      return posts.slice(0, most);
    }

    /** "11 October 2026" */
    export function postDate(post: BlogPost): string {
      return post.publishedAt ? new Intl.DateTimeFormat("en-GB", { dateStyle: "long" }).format(new Date(post.publishedAt)) : "";
    }

    TS;

    private const LATEST = <<<'TSX'
    import Link from "next/link";
    import { blog, listPosts, postDate } from "@/lib/blog";

    /** The newest three __PLURAL__ on the home page, and the way to the rest. Nothing while none is published. */
    export async function LatestPosts() {
      const { posts } = await listPosts(1, 3);
      if (posts.length === 0) return null;

      return (
        <section className="border-t border-border px-6 py-24">
          <div className="mx-auto max-w-6xl">
            <div className="flex flex-wrap items-end justify-between gap-4">
              <div className="max-w-2xl">
                <h2 className="text-4xl text-balance">From the {blog.title.toLowerCase()}</h2>
                <p className="mt-4 text-lg text-foreground-muted">{blog.description}</p>
              </div>
              <Link href={blog.path} className="text-sm font-medium hover:underline">
                Read the {blog.title.toLowerCase()} →
              </Link>
            </div>
            <ul className="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
              {posts.map((post) => (
                <li key={post.id}>
                  <Link href={`${blog.path}/${post.slug}`} className="group flex h-full flex-col overflow-hidden rounded-[calc(var(--radius)+4px)] border border-border bg-surface hover:border-foreground/30">
                    {post.image && (
                      // eslint-disable-next-line @next/next/no-img-element -- already the right size: Laravel made this rendition when the picture was uploaded
                      <img src={post.image} alt="" loading="lazy" decoding="async" className="aspect-[16/9] w-full object-cover" />
                    )}
                    <div className="flex flex-1 flex-col gap-2 p-6">
                      {post.publishedAt && (
                        <time dateTime={post.publishedAt} className="text-xs text-foreground-muted">
                          {postDate(post)}
                        </time>
                      )}
                      <h3 className="text-xl text-balance group-hover:underline">{post.title}</h3>
                      <p className="line-clamp-3 text-sm leading-relaxed text-foreground-muted">{post.excerpt}</p>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        </section>
      );
    }

    TSX;

    private const INDEX = <<<'TSX'
    import type { Metadata } from "next";
    import Link from "next/link";
    import { SiteHeader } from "@/components/marketing/site-header";
    import { blog, listPosts, postDate } from "@/lib/blog";

    export const metadata: Metadata = {
      title: blog.title,
      description: blog.description,
      alternates: { types: { "application/rss+xml": `${blog.path}/feed.xml` } },
    };

    /** The blog: published __PLURAL__, newest first. */
    export default async function BlogPage({ searchParams }: { searchParams: Promise<{ page?: string }> }) {
      const asked = Number.parseInt((await searchParams).page ?? "1", 10);
      const { posts, page, totalPages } = await listPosts(Number.isInteger(asked) && asked > 0 ? asked : 1);

      return (
        <>
          <SiteHeader />
          <main className="mx-auto max-w-6xl px-6 py-16">
            <header className="max-w-2xl">
              <h1 className="text-4xl md:text-5xl">{blog.title}</h1>
              <p className="mt-4 text-lg text-foreground-muted">{blog.description}</p>
            </header>

            {posts.length === 0 ? (
              <p className="mt-16 text-foreground-muted">Nothing has been published yet.</p>
            ) : (
              <ul className="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                {posts.map((post) => (
                  <li key={post.id}>
                    <Link href={`${blog.path}/${post.slug}`} className="group flex h-full flex-col overflow-hidden rounded-[calc(var(--radius)+4px)] border border-border bg-surface hover:border-foreground/30">
                      {post.image && (
                        // eslint-disable-next-line @next/next/no-img-element -- already the right size: Laravel made this rendition when the picture was uploaded
                        <img src={post.image} alt="" loading="lazy" decoding="async" className="aspect-[16/9] w-full object-cover" />
                      )}
                      <div className="flex flex-1 flex-col gap-2 p-6">
                        {post.publishedAt && (
                          <time dateTime={post.publishedAt} className="text-xs text-foreground-muted">
                            {postDate(post)}
                          </time>
                        )}
                        <h2 className="text-xl text-balance group-hover:underline">{post.title}</h2>
                        <p className="line-clamp-3 text-sm leading-relaxed text-foreground-muted">{post.excerpt}</p>
                      </div>
                    </Link>
                  </li>
                ))}
              </ul>
            )}

            {totalPages > 1 && (
              <nav aria-label="Pages" className="mt-12 flex items-center justify-between text-sm">
                {page > 1 ? (
                  <Link href={page === 2 ? blog.path : `${blog.path}?page=${page - 1}`} className="hover:underline">
                    ← Newer
                  </Link>
                ) : (
                  <span />
                )}
                <span className="text-foreground-muted">
                  Page {page} of {totalPages}
                </span>
                {page < totalPages ? (
                  <Link href={`${blog.path}?page=${page + 1}`} className="hover:underline">
                    Older →
                  </Link>
                ) : (
                  <span />
                )}
              </nav>
            )}
          </main>
        </>
      );
    }

    TSX;

    private const POST = <<<'TSX'
    import type { Metadata } from "next";
    import Link from "next/link";
    import { notFound } from "next/navigation";
    import { SiteHeader } from "@/components/marketing/site-header";
    import { BODY_IS_HTML, blog, getPost, postDate, siteUrl } from "@/lib/blog";
    import { site } from "@/lib/site";

    type Props = { params: Promise<{ slug: string }> };

    /** What a search engine and a shared link show: the title, the excerpt and the picture. */
    export async function generateMetadata({ params }: Props): Promise<Metadata> {
      const post = await getPost((await params).slug);
      if (!post) return { title: "Not found" };
      const image = post.imageLarge ? [new URL(post.imageLarge, siteUrl()).toString()] : undefined;
      return {
        title: post.title,
        description: post.excerpt,
        alternates: { canonical: `${siteUrl()}${blog.path}/${post.slug}` },
        openGraph: { type: "article", title: post.title, description: post.excerpt, publishedTime: post.publishedAt ?? undefined, images: image },
        twitter: { card: image ? "summary_large_image" : "summary", title: post.title, description: post.excerpt, images: image },
      };
    }

    /** One post. A draft, one scheduled for later and one that never was are all a 404. */
    export default async function PostPage({ params }: Props) {
      const post = await getPost((await params).slug);
      if (!post) notFound();

      // What the post is, for search engines (schema.org): a title, a date, a picture, who published it.
      const url = `${siteUrl()}${blog.path}/${post.slug}`;
      const article = {
        "@context": "https://schema.org",
        "@type": "BlogPosting",
        headline: post.title,
        description: post.excerpt || undefined,
        datePublished: post.publishedAt ?? undefined,
        image: post.imageLarge ? new URL(post.imageLarge, siteUrl()).toString() : undefined,
        url,
        mainEntityOfPage: url,
        publisher: { "@type": "Organization", name: site.name, url: siteUrl() },
      };

      return (
        <>
          {/* A "<" in a title can't end the script early. */}
          <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(article).replace(/</g, "\\u003c") }} />
          <SiteHeader />
          <main className="mx-auto max-w-3xl px-6 py-16">
            <Link href={blog.path} className="text-sm text-foreground-muted hover:text-foreground">
              ← {blog.title}
            </Link>
            <article className="mt-8">
              <header>
                {post.publishedAt && (
                  <time dateTime={post.publishedAt} className="text-sm text-foreground-muted">
                    {postDate(post)}
                  </time>
                )}
                <h1 className="mt-2 text-4xl text-balance md:text-5xl">{post.title}</h1>
              </header>
              {post.imageLarge && (
                // eslint-disable-next-line @next/next/no-img-element -- optimised by Laravel when it was uploaded
                <img src={post.imageLarge} alt="" className="mt-10 w-full rounded-[calc(var(--radius)+4px)] border border-border" />
              )}
              {BODY_IS_HTML ? (
                // Laravel cleaned this HTML when the post was saved and again when it was read.
                <div className="rich-text mt-10 text-lg" dangerouslySetInnerHTML={{ __html: post.body }} />
              ) : (
                <div className="mt-10 text-lg leading-relaxed whitespace-pre-line">{post.body}</div>
              )}
            </article>
          </main>
        </>
      );
    }

    TSX;

    private const FEED = <<<'TS'
    import { blog, allPosts, siteUrl } from "@/lib/blog";
    import { site } from "@/lib/site";

    /** Rebuilt at most once a minute, like the pages. */
    export const revalidate = 60;

    const escape = (text: string) => text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

    /** The blog as an RSS feed: the latest fifty published __PLURAL__. */
    export async function GET() {
      const posts = await allPosts(50);
      const home = siteUrl();
      const items = posts
        .map((post) => {
          const link = `${home}${blog.path}/${post.slug}`;
          return [
            "    <item>",
            `      <title>${escape(post.title)}</title>`,
            `      <link>${escape(link)}</link>`,
            `      <guid isPermaLink="true">${escape(link)}</guid>`,
            post.publishedAt ? `      <pubDate>${new Date(post.publishedAt).toUTCString()}</pubDate>` : "",
            `      <description>${escape(post.excerpt)}</description>`,
            "    </item>",
          ]
            .filter(Boolean)
            .join("\n");
        })
        .join("\n");

      const xml = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<rss version="2.0">',
        "  <channel>",
        `    <title>${escape(`${site.name}: ${blog.title}`)}</title>`,
        `    <link>${escape(`${home}${blog.path}`)}</link>`,
        `    <description>${escape(blog.description)}</description>`,
        items,
        "  </channel>",
        "</rss>",
        "",
      ].join("\n");

      return new Response(xml, { headers: { "Content-Type": "application/rss+xml; charset=utf-8" } });
    }

    TS;

    private const SITEMAP = <<<'TS'
    import type { MetadataRoute } from "next";
    import { allPosts, blog, siteUrl } from "@/lib/blog";

    /** Rebuilt at most once a minute, so a post is in it soon after it is published. */
    export const revalidate = 60;

    /** The site's pages, and every published post. Add your own pages to the first list. */
    export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
      const home = siteUrl();
      const posts = await allPosts();
      return [
        { url: home, changeFrequency: "monthly", priority: 1 },
        { url: `${home}${blog.path}`, changeFrequency: "daily", priority: 0.8 },
        ...posts.map((post) => ({
          url: `${home}${blog.path}/${post.slug}`,
          lastModified: post.publishedAt ? new Date(post.publishedAt) : undefined,
        })),
      ];
    }

    TS;
}

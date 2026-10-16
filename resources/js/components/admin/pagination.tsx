import { Link } from '@inertiajs/react';

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

/** Laravel paginator links. Labels arrive as HTML entities ("&laquo; Previous"), so decode them as text. */
export default function Pagination({ links }: { links: PageLink[] }) {
    if (links.length <= 3) return null;

    const text = (label: string) => label.replace('&laquo;', '‹').replace('&raquo;', '›').replace(/<[^>]*>/g, '').trim();

    return (
        <nav aria-label="Pages" className="mt-4 flex flex-wrap gap-1">
            {links.map((link, i) =>
                link.url ? (
                    <Link
                        key={i}
                        href={link.url}
                        preserveScroll
                        aria-current={link.active ? 'page' : undefined}
                        className={`min-w-9 rounded-md border px-3 py-1.5 text-center text-sm ${link.active ? 'border-foreground bg-foreground text-background' : 'hover:bg-muted'}`}
                    >
                        {text(link.label)}
                    </Link>
                ) : (
                    <span key={i} className="min-w-9 rounded-md border border-transparent px-3 py-1.5 text-center text-sm text-muted-foreground">
                        {text(link.label)}
                    </span>
                ),
            )}
        </nav>
    );
}

import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

interface Props {
    title: string;
    description?: string;
    /** Absolute or root-relative image for link previews. */
    image?: string | null;
    children?: ReactNode;
}

/** Facts for search engines (schema.org), kept off the page itself. "<" is escaped so the data can never close the script tag. */
export function JsonLd({ data }: { data: Record<string, unknown> }) {
    return <script head-key="json-ld" type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(data).replace(/</g, '\u003c') }} />;
}

/** <title>, description and link-preview tags for a page. The site name comes from the brand config. */
export default function PageHead({ title, description, image, children }: Props) {
    const { brand, locale } = usePage<SharedData>().props;
    const full = title ? `${title} · ${brand.name}` : brand.name;

    // The app adds the site name to every tab title already, so the tab says it once; the link previews below get the full form.
    return (
        <Head title={title}>
            {description && <meta head-key="description" name="description" content={description} />}
            {typeof window !== 'undefined' && <link head-key="canonical" rel="canonical" href={window.location.origin + window.location.pathname} />}
            <meta head-key="og:title" property="og:title" content={full} />
            {description && <meta head-key="og:description" property="og:description" content={description} />}
            <meta head-key="og:site_name" property="og:site_name" content={brand.name} />
            <meta head-key="og:locale" property="og:locale" content={locale === 'bn' ? 'bn_BD' : 'en_US'} />
            {image && <meta head-key="og:image" property="og:image" content={image} />}
            {children}
        </Head>
    );
}

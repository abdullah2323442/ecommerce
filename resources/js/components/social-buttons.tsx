import { useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/** "Continue with Google / Facebook", only for the ones the owner switched on (Admin > Connections). A plain link: the browser leaves for the provider. */
export default function SocialButtons() {
    const { t } = useI18n();
    const { socialProviders } = usePage<SharedData>().props;

    if (!socialProviders || socialProviders.length === 0) {
        return null;
    }

    return (
        <div className="mt-6 space-y-3">
            <div className="flex items-center gap-3 text-xs text-muted-foreground">
                <span className="h-px flex-1 bg-border" />
                {t('or')}
                <span className="h-px flex-1 bg-border" />
            </div>
            {socialProviders.map((p) => (
                <a
                    key={p.key}
                    href={route('social.redirect', p.key)}
                    className="flex h-10 w-full items-center justify-center rounded-md border bg-background text-sm font-medium transition-colors hover:bg-muted"
                >
                    {t('Continue with {name}', { name: p.name })}
                </a>
            ))}
        </div>
    );
}

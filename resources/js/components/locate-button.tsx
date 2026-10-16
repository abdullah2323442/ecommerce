import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { LocateFixed } from 'lucide-react';
import { useState } from 'react';

export interface Found {
    district: string | null;
    area: string;
    line: string;
    lat: number;
    lng: number;
}

/**
 * "Use my location" (spec ch 20). The browser asks the buyer for permission and gives two numbers; the server names the place. The buyer can change
 * every field afterwards, and if anything goes wrong they simply type the address as before.
 */
export default function LocateButton({ onFound }: { onFound: (f: Found) => void }) {
    const { t } = useI18n();
    const [busy, setBusy] = useState(false);
    const [note, setNote] = useState<string | null>(null);

    const go = () => {
        setNote(null);

        if (!('geolocation' in navigator)) {
            setNote(t('This phone or browser cannot share your place. Please type your address.'));

            return;
        }

        setBusy(true);
        navigator.geolocation.getCurrentPosition(
            async (pos) => {
                const { latitude: lat, longitude: lng } = pos.coords;

                try {
                    const r = await fetch(`/location/lookup?lat=${lat}&lng=${lng}`, { headers: { Accept: 'application/json' } });
                    const j = await r.json();

                    if (!r.ok) {
                        setNote(j.message ?? t('We could not find your address. Please type it.'));
                    } else {
                        onFound({ district: j.district, area: j.area ?? '', line: j.line ?? '', lat: Math.round(lat * 1e6) / 1e6, lng: Math.round(lng * 1e6) / 1e6 });
                        setNote(t('Your place is filled in. Please check it and add your house or flat.'));
                    }
                } catch {
                    setNote(t('We could not find your address. Please type it.'));
                } finally {
                    setBusy(false);
                }
            },
            (err) => {
                setBusy(false);
                setNote(err.code === 1 ? t('You did not allow your location. Please type your address.') : t('We could not find where you are. Please type your address.'));
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
        );
    };

    return (
        <div className="sm:col-span-2">
            <Button type="button" variant="outline" size="sm" onClick={go} disabled={busy}>
                <LocateFixed className="size-4" aria-hidden />
                {busy ? t('Finding your place…') : t('Use my location')}
            </Button>
            {note && (
                <p className="mt-1 text-xs text-muted-foreground" role="status">
                    {note}
                </p>
            )}
        </div>
    );
}

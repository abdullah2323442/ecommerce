import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { Camera, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Found = { rawValue: string };
type Detector = { detect: (source: CanvasImageSource) => Promise<Found[]> };
type DetectorClass = new (options?: { formats?: string[] }) => Detector;

/** What a medicine pack carries: the common shop barcodes, plus QR for the odd one. */
const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code'];

/** How often the picture is looked at. A few times a second is quick enough and keeps a cheap phone cool. */
const LOOK_EVERY_MS = 250;

/**
 * Scan a barcode with the phone's camera (spec ch 06, item 107). The browser's own barcode reader does the work, so there is no outside
 * service and nothing leaves the phone: the picture is looked at in place and thrown away. A USB or Bluetooth scanner, which types the
 * digits, keeps working beside it.
 *
 * It says plainly when it cannot work: no camera, no permission, a browser without a reader, or a page that is not https (a camera needs one).
 */
export default function CameraScan({ onCode, label }: { onCode: (code: string) => void; label?: string }) {
    const { t } = useI18n();
    const [state, setState] = useState<'idle' | 'starting' | 'live'>('idle');
    const [note, setNote] = useState<string | null>(null);
    const video = useRef<HTMLVideoElement>(null);
    const stream = useRef<MediaStream | null>(null);
    const timer = useRef<number | null>(null);

    const stop = useCallback(() => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
            timer.current = null;
        }
        stream.current?.getTracks().forEach((track) => track.stop());
        stream.current = null;
        setState('idle');
    }, []);

    // The camera is switched off when the page goes away, never left running.
    useEffect(() => stop, [stop]);

    const start = async () => {
        setNote(null);

        const Reader = (window as unknown as { BarcodeDetector?: DetectorClass }).BarcodeDetector;

        if (!window.isSecureContext) {
            setNote(t('A camera only works on a secure (https) page. Type the code or use a scanner.'));

            return;
        }

        if (!navigator.mediaDevices?.getUserMedia || !Reader) {
            setNote(t('This browser cannot read barcodes with the camera. Use Chrome on the phone, or type the code.'));

            return;
        }

        setState('starting');

        try {
            const media = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
            stream.current = media;

            if (!video.current) {
                stop();

                return;
            }

            video.current.srcObject = media;
            await video.current.play();
            setState('live');

            const reader = new Reader({ formats: FORMATS });
            const look = async () => {
                if (!video.current || !stream.current) {
                    return;
                }

                try {
                    const found = await reader.detect(video.current);
                    const code = found[0]?.rawValue?.trim();

                    if (code) {
                        navigator.vibrate?.(60);
                        stop();
                        onCode(code);

                        return;
                    }
                } catch {
                    // A frame that cannot be read is skipped; the next one is tried.
                }

                timer.current = window.setTimeout(look, LOOK_EVERY_MS);
            };

            look();
        } catch (e) {
            stop();
            const denied = e instanceof DOMException && (e.name === 'NotAllowedError' || e.name === 'SecurityError');
            setNote(denied ? t('The camera was not allowed. Allow it for this site, or type the code.') : t('No camera could be opened. Type the code or use a scanner.'));
        }
    };

    return (
        <div className="space-y-2">
            <Button type="button" variant="outline" onClick={state === 'idle' ? start : stop} disabled={state === 'starting'} aria-pressed={state === 'live'}>
                {state === 'live' ? <X className="size-4" aria-hidden /> : <Camera className="size-4" aria-hidden />}
                {state === 'starting' ? t('Opening the camera…') : state === 'live' ? t('Close the camera') : (label ?? t('Scan with the camera'))}
            </Button>
            <div className={state === 'idle' ? 'hidden' : 'space-y-1'}>
                <video ref={video} muted playsInline aria-label={t('Camera view')} className="aspect-video max-h-64 w-full max-w-sm rounded-md border bg-black object-cover" />
                {state === 'live' && (
                    <p role="status" className="text-sm text-muted-foreground">
                        {t('Hold the barcode inside the picture.')}
                    </p>
                )}
            </div>
            {note && (
                <p role="alert" className="text-sm text-muted-foreground">
                    {note}
                </p>
            )}
        </div>
    );
}
